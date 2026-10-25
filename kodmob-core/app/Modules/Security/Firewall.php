<?php

namespace App\Modules\Security;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * IP engelleri (`ip_blocks`) ve saldırı kalıbı süzgeci.
 *
 * - Engel tek IP (`203.0.113.7`) ya da aralık (`203.0.113.0/24`, IPv6 da) olabilir;
 *   süreli (otomatik engel) ya da süresiz (elle).
 * - Liste önbellekte 60 sn tutulur; her istekte veritabanı okunmaz.
 * - Kalıplar yalnızca adres ve sorgu dizesinde aranır; gövde taranmaz (blog
 *   yazısı gibi içerikte yanlış alarm olmasın).
 */
final class Firewall
{
    private const CACHE = 'km:ip_blocks';

    public const PATTERNS = [
        'sql_injection' => '/(\bunion\s+(all\s+)?select\b|\bsleep\s*\(\s*\d|\bbenchmark\s*\(|\bwaitfor\s+delay\b|@@version|\binformation_schema\b)/i',
        'xss' => '/(<script\b|\bon(error|load)\s*=|javascript\s*:)/i',
        'lfi' => '/(\.\.\/|\.\.\\\\|\/etc\/passwd|php:\/\/|boot\.ini)/i',
    ];

    public const PATTERN_LABELS = ['sql_injection' => 'SQL enjeksiyonu', 'xss' => 'XSS', 'lfi' => 'Dosya yolu'];

    /** IP engelliyse engelin kimliği. */
    public static function blockedBy(string $ip): ?int
    {
        if ($ip === '') {
            return null;
        }
        foreach (self::active() as $block) {
            if ($block['ip'] === $ip || (str_contains($block['ip'], '/') && IpUtils::checkIp($ip, $block['ip']))) {
                return $block['id'];
            }
        }

        return null;
    }

    /** Eşleşen saldırı kalıbının adı. */
    public static function match(string $subject): ?string
    {
        $subject = urldecode(urldecode($subject));
        foreach (self::PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $subject)) {
                return $name;
            }
        }

        return null;
    }

    public static function hit(int $id): void
    {
        DB::table('ip_blocks')->where('id', $id)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => Clock::now()]);
    }

    /**
     * Engel ekler ya da günceller.
     *
     * @param  int|null  $hours  null: süresiz
     */
    public static function block(string $ip, string $reason, string $source = 'manual', ?int $hours = null, ?int $by = null): array
    {
        $ip = self::normalize($ip);
        $values = [
            'reason' => mb_substr(Text::field($reason), 0, 255),
            'source' => $source,
            'expires_at' => $hours ? now()->addHours($hours)->format('Y-m-d H:i:s') : null,
            'created_by' => $by,
        ];
        if (DB::table('ip_blocks')->where('ip', $ip)->exists()) {
            DB::table('ip_blocks')->where('ip', $ip)->update($values);
        } else {
            DB::table('ip_blocks')->insert($values + ['ip' => $ip, 'created_at' => Clock::now()]);
        }
        self::forget();

        return (array) DB::table('ip_blocks')->where('ip', $ip)->first();
    }

    public static function unblock(int $id): ?array
    {
        $row = DB::table('ip_blocks')->where('id', $id)->first();
        if ($row) {
            DB::table('ip_blocks')->where('id', $id)->delete();
            self::forget();
        }

        return $row ? (array) $row : null;
    }

    /**
     * Düşmanca olay eşiği aşıldıysa IP'yi süreli engeller.
     */
    public static function autoBlock(string $ip): void
    {
        $threshold = SecurityModule::int(SecurityModule::AUTO_BLOCK, 20);
        if ($threshold <= 0 || $ip === '' || self::blockedBy($ip) !== null || (IpUtils::isPrivateIp($ip) && app()->isLocal())) {
            return;
        }
        if (SecurityLog::hostileCount($ip) >= $threshold) {
            $hours = SecurityModule::int(SecurityModule::AUTO_BLOCK_HOURS, 24);
            self::block($ip, sprintf('Son bir saatte %d+ şüpheli istek', $threshold), 'auto', $hours);
            SecurityLog::record(SecurityLog::IP_AUTO_BLOCKED, null, sprintf('%d saat', $hours));
        }
    }

    /** Geçerli IP ya da CIDR; değilse doğrulama hatası. */
    public static function normalize(string $ip): string
    {
        $ip = strtolower(trim($ip));
        [$address, $mask] = array_pad(explode('/', $ip, 2), 2, null);
        $valid = filter_var($address, FILTER_VALIDATE_IP) !== false;
        if ($valid && $mask !== null) {
            $max = str_contains($address, ':') ? 128 : 32;
            $valid = ctype_digit($mask) && (int) $mask >= 8 && (int) $mask <= $max;
        }
        if (! $valid) {
            throw ValidationException::field('ip', 'Geçerli bir IP adresi ya da aralık (ör. 203.0.113.0/24) yazın.');
        }

        return $ip;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** @return list<array{id:int,ip:string}> süresi dolmamış engeller */
    private static function active(): array
    {
        try {
            return Cache::remember(self::CACHE, 60, static function () {
                $now = Clock::now();
                DB::table('ip_blocks')->whereNotNull('expires_at')->where('expires_at', '<', $now)->delete();

                return DB::table('ip_blocks')->get(['id', 'ip'])->map(fn ($r) => ['id' => (int) $r->id, 'ip' => (string) $r->ip])->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }
}
