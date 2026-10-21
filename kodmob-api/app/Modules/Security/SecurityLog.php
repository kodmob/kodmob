<?php

namespace App\Modules\Security;

use App\Core\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Güvenlik olayları (`security_events`): hatalı giriş, kilit, engellenen istek,
 * otomatik IP engeli. İşlem Geçmişi'nden ayrıdır: kimliği belirsiz ziyaretçinin
 * olaylarıdır, çoktur ve kısa süre saklanır (SecurityModule::RETENTION_DAYS).
 *
 * Kayıt yazılamazsa istek bozulmaz (tablo kurulumdan önce yoktur).
 */
final class SecurityLog
{
    public const LOGIN_FAILED = 'login_failed';

    public const LOGIN_LOCKED = 'login_locked';

    public const IP_LOGIN_LIMITED = 'ip_login_limited';

    public const LOGIN_DISABLED = 'login_disabled';

    /** İki adımlı doğrulamada yedek kodla giriş (telefon kaybolmuş olabilir). */
    public const LOGIN_RECOVERY = 'login_recovery';

    public const WAF_BLOCKED = 'waf_blocked';

    public const IP_DENIED = 'ip_denied';

    public const IP_AUTO_BLOCKED = 'ip_auto_blocked';

    public const TOKEN_REUSED = 'token_reused';

    public const KEY_ROTATED = 'key_rotated';

    /** Otomatik IP engeli için sayılan olaylar. */
    public const HOSTILE = [self::LOGIN_FAILED, self::WAF_BLOCKED];

    /** @return array<string,array{label:string,tone:string}> */
    public static function types(): array
    {
        return [
            self::LOGIN_FAILED => ['label' => 'Hatalı giriş', 'tone' => 'warning'],
            self::LOGIN_LOCKED => ['label' => 'Giriş kilitlendi', 'tone' => 'danger'],
            self::IP_LOGIN_LIMITED => ['label' => 'IP girişi sınırlandı', 'tone' => 'danger'],
            self::LOGIN_DISABLED => ['label' => 'Pasif hesapla giriş denemesi', 'tone' => 'warning'],
            self::LOGIN_RECOVERY => ['label' => 'Yedek kodla giriş (iki adımlı doğrulama)', 'tone' => 'warning'],
            self::WAF_BLOCKED => ['label' => 'Saldırı kalıbı engellendi', 'tone' => 'danger'],
            self::IP_DENIED => ['label' => 'Engelli IP isteği', 'tone' => 'neutral'],
            self::IP_AUTO_BLOCKED => ['label' => 'IP otomatik engellendi', 'tone' => 'danger'],
            self::TOKEN_REUSED => ['label' => 'Kullanılmış oturum jetonu', 'tone' => 'warning'],
            self::KEY_ROTATED => ['label' => 'İmza anahtarı yenilendi', 'tone' => 'warning'],
        ];
    }

    public static function record(string $type, ?Request $request = null, string $details = '', string $username = '', ?int $userId = null): void
    {
        $request ??= app()->runningInConsole() ? null : request();
        try {
            DB::table('security_events')->insert([
                'type' => $type,
                'ip' => (string) $request?->ip(),
                'user_id' => $userId,
                'username' => mb_substr($username, 0, 190),
                'details' => mb_substr($details, 0, 500),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255),
                'created_at' => Clock::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Kodmob] güvenlik olayı yazılamadı: '.$e->getMessage(), ['type' => $type]);
        }
    }

    /** Son bir saatte IP'nin düşmanca olay sayısı (otomatik engel). */
    public static function hostileCount(string $ip, int $minutes = 60): int
    {
        if ($ip === '') {
            return 0;
        }

        return (int) DB::table('security_events')
            ->where('ip', $ip)
            ->whereIn('type', self::HOSTILE)
            ->where('created_at', '>=', now()->subMinutes($minutes)->format('Y-m-d H:i:s'))
            ->count();
    }

    public static function prune(int $days): int
    {
        return DB::table('security_events')
            ->where('created_at', '<', now()->subDays(max(1, $days))->format('Y-m-d H:i:s'))
            ->delete();
    }
}
