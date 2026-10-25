<?php

namespace App\Modules\Seo\Services;

use App\Core\Security\Capability;
use App\Core\Support\Clock;
use App\Core\Support\PanelUrl;
use App\Core\Support\Paths;
use App\Modules\Notifications\NotificationService;
use App\Modules\Seo\Seo;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\Suggestions;
use Illuminate\Support\Facades\DB;

/**
 * 404 izleme (`seo_404`). render.php bulunamayan her sayfa isteğini
 * `storage/framework/seo/404.log`'a bir JSON satırı olarak ekler (Laravel açılmaz);
 * buradaki `import()` satırları adres başına toplar. Panelde 404 listesi açılınca ve
 * her gece çalışır.
 *
 * Durumlar: new (yeni) · ignored (yoksayıldı) · redirected (yönlendirme eklendi).
 * Tekrar görülen yoksayılmış adres yoksayılmış kalır.
 *
 * Kırık iç bağlantı (`internal`): yönlendiren sitenin kendi adresi. İlk kez görülünce SEO
 * yetkisi olanlara bildirim gider. Yeni adrese öneri motorunun hedefi (`suggestion`) yazılır
 * (Site\Render\Suggestions); panelde "önerilene yönlendir".
 */
final class NotFoundLog
{
    public const STATUSES = ['new' => 'Yeni', 'redirected' => 'Yönlendirildi', 'ignored' => 'Yoksayıldı'];

    public static function import(): int
    {
        $lines = SeoConfig::drain('404.log');
        if (! $lines) {
            return 0;
        }
        $hosts = self::siteHosts();
        $rows = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (! is_array($entry) || empty($entry['p'])) {
                continue;
            }
            $path = mb_substr((string) $entry['p'], 0, 500);
            $hash = sha1($path);
            $time = strtotime((string) ($entry['t'] ?? '')) ? date('Y-m-d H:i:s', (int) strtotime((string) $entry['t'])) : Clock::now();
            $row = $rows[$hash] ?? ['path' => $path, 'hits' => 0, 'referrer' => '', 'bot' => true, 'internal' => false, 'first' => $time, 'last' => $time];
            $row['hits']++;
            $row['first'] = min($row['first'], $time);
            $row['last'] = max($row['last'], $time);
            $row['bot'] = $row['bot'] && ! empty($entry['b']);
            if (! empty($entry['r'])) {
                $row['referrer'] = mb_substr((string) $entry['r'], 0, 500);
                $row['internal'] = $row['internal'] || in_array(strtolower((string) parse_url($row['referrer'], PHP_URL_HOST)), $hosts, true);
            }
            $rows[$hash] = $row;
        }
        $broken = [];
        foreach ($rows as $hash => $row) {
            $existing = DB::table('seo_404')->where('path_hash', $hash)->first();
            if ($row['internal'] && (! $existing || (! $existing->internal && $existing->status === 'new'))) {
                $broken[] = $row['path'];
            }
            if ($existing) {
                DB::table('seo_404')->where('id', $existing->id)->update([
                    'hits' => DB::raw('hits + '.(int) $row['hits']),
                    'last_seen_at' => max((string) $existing->last_seen_at, $row['last']),
                    'referrer' => $row['referrer'] !== '' ? $row['referrer'] : $existing->referrer,
                    'bot' => $existing->bot && $row['bot'],
                    'internal' => $existing->internal || $row['internal'],
                    // Yönlendirilmiş adres yine 404 veriyorsa (kural silindi/kapalı) yeniden "yeni".
                    'status' => $existing->status === 'redirected' ? 'new' : $existing->status,
                ]);
            } else {
                DB::table('seo_404')->insert([
                    'path' => $row['path'],
                    'path_hash' => $hash,
                    'hits' => $row['hits'],
                    'referrer' => $row['referrer'],
                    'bot' => $row['bot'],
                    'internal' => $row['internal'],
                    'suggestion' => self::suggestion($row['path']),
                    'status' => 'new',
                    'first_seen_at' => $row['first'],
                    'last_seen_at' => $row['last'],
                ]);
            }
        }

        if ($broken) {
            NotificationService::notifyCapable(
                Capability::MANAGE_SEO,
                'seo.broken_link',
                count($broken) === 1 ? 'Kırık iç bağlantı' : count($broken).' kırık iç bağlantı',
                'Sitenin kendi sayfalarından gelen ziyaretçi 404 aldı: '.implode(', ', array_slice($broken, 0, 3)).(count($broken) > 3 ? ' …' : ''),
                PanelUrl::to('seo', ['tab' => '404']),
                'warning',
                ['paths' => array_slice($broken, 0, 20)]
            );
        }

        return count($lines);
    }

    /** Sitenin alan adları (yönlendiren buysa iç bağlantı). */
    private static function siteHosts(): array
    {
        $hosts = [parse_url((string) config('app.url'), PHP_URL_HOST)];
        $router = Router::load(Paths::siteRoot());
        if ($router) {
            $hosts[] = parse_url((string) ($router->manifest()['siteUrl'] ?? ''), PHP_URL_HOST);
        }
        $hosts = array_map('strtolower', array_filter($hosts, 'is_string'));
        foreach ($hosts as $host) {
            $hosts[] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        }

        return array_values(array_unique($hosts));
    }

    /** Yeni 404 adresi için öneri motorunun en iyi hedefi (yoksa boş). */
    private static function suggestion(string $path): string
    {
        try {
            $router = Router::load(Paths::siteRoot());

            return $router ? (string) ((new Suggestions($router))->for($path, 1)[0]['url'] ?? '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public static function prune(): int
    {
        $days = max(7, (int) (Seo::setting('seo.retention_404') ?: 90));

        return DB::table('seo_404')->where('last_seen_at', '<', now()->subDays($days)->format('Y-m-d H:i:s'))->delete();
    }

    public static function setStatus(array $ids, string $status): int
    {
        if (! isset(self::STATUSES[$status])) {
            return 0;
        }

        return DB::table('seo_404')->whereIn('id', array_map('intval', $ids))->update(['status' => $status]);
    }

    public static function delete(array $ids): int
    {
        return DB::table('seo_404')->whereIn('id', array_map('intval', $ids))->delete();
    }

    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'path' => (string) $row['path'],
            'hits' => (int) $row['hits'],
            'referrer' => (string) $row['referrer'],
            'bot' => (bool) $row['bot'],
            'internal' => (bool) ($row['internal'] ?? false),
            'suggestion' => (string) ($row['suggestion'] ?? ''),
            'status' => (string) $row['status'],
            'first_seen_at' => Clock::local($row['first_seen_at']),
            'last_seen_at' => Clock::local($row['last_seen_at']),
        ];
    }
}
