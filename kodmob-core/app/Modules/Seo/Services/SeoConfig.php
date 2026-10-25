<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\PageCache;
use App\Core\Support\Files;
use App\Core\Support\Options;
use App\Core\Support\Paths;
use App\Modules\Seo\Seo;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * render.php'nin Laravel açmadan okuduğu SEO yapılandırması:
 * `storage/framework/seo/config.json`.
 *
 *     { enabled, log404, llms, indexnow: "anahtar" | "",
 *       redirects: { exact: { "/eski": [id, "/yeni/", 301] }, regex: [[id, "#…#u", "/hedef/$1", 301]] },
 *       robots: "robots.txt metni" | null }
 *
 * Ayar, yönlendirme ya da robots değişince yeniden yazılır (SeoModule dinler).
 * Ziyaretçi isteğinde veritabanına gidilmez; yönlendirme tıklanmaları ve 404'ler aynı
 * klasördeki günlük dosyalarına eklenir, panel açılınca (ya da her gece) içeri alınır.
 */
final class SeoConfig
{
    public const ROBOTS_OPTION = 'seo_robots';

    /** Derlenmiş yapılandırmanın biçimi; değişince (yeni alan) eski dosya ilk sayfa üretiminde yenilenir (SitePage). */
    public const SCHEMA = 2;

    public static function dir(): string
    {
        return storage_path('framework/seo');
    }

    public static function compile(): void
    {
        try {
            $dir = self::dir();
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $config = [
                'schema' => self::SCHEMA,
                'enabled' => Seo::enabled(),
                'log404' => Seo::on('monitor404'),
                'llms' => Seo::on('llms'),
                'indexnow' => Seo::on('indexnow') ? IndexNow::key() : '',
                'redirects' => Seo::on('redirects') ? self::redirects() : ['exact' => (object) [], 'regex' => []],
                'robots' => Seo::on('robots') ? self::robots() : null,
                // Yapay zekâ botlarının ziyaret kaydı (render.php; SEO > Yapay zekâ).
                'aiBots' => Seo::on('ai') ? AiBots::pattern() : '',
                'compiled' => now()->toAtomString(),
            ];
            // Atomik: render.php yarım yazılmış dosya okumasın.
            Files::putAtomic($dir.'/config.json', (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            PageCache::flush();
        } catch (\Throwable $e) {
            Log::warning('[Kodmob SEO] yapılandırma yazılamadı: '.$e->getMessage());
        }
    }

    /** @return array{exact:array<string,array>|object,regex:list<array>} */
    private static function redirects(): array
    {
        $exact = [];
        $regex = [];
        $rows = DB::table('seo_redirects')->where('active', true)->orderBy('position')->orderBy('id')->get();
        foreach ($rows as $row) {
            if ($row->is_regex) {
                $regex[] = [(int) $row->id, Redirects::pattern((string) $row->source), (string) $row->target, (int) $row->code];
            } else {
                $exact[Redirects::key((string) $row->source)] ??= [(int) $row->id, (string) $row->target, (int) $row->code];
            }
        }

        return ['exact' => $exact ?: (object) [], 'regex' => $regex];
    }

    /** robots.txt (site arama motorlarına açıkken; kapalıyken render.php "Disallow: /" yazar). */
    public static function robots(): string
    {
        $lines = ['User-agent: *', 'Allow: /', 'Disallow: /api/', 'Disallow: /panel/'];
        $extra = trim((string) Options::get(self::ROBOTS_OPTION, ''));
        if ($extra !== '') {
            $lines[] = '';
            $lines[] = '# Ek kurallar (Panel > SEO > Robots)';
            array_push($lines, ...preg_split('/\r\n|\r|\n/', $extra));
        }
        if (Seo::setting('seo.ai_bots') === 'block') {
            $lines[] = '';
            $lines[] = '# Yapay zekâ tarayıcıları engelli';
            foreach (AiBots::robotsTokens() as $bot) {
                array_push($lines, 'User-agent: '.$bot, 'Disallow: /', '');
            }
        }
        $site = self::siteUrl();
        if ($site !== '') {
            $lines[] = '';
            $lines[] = 'Sitemap: '.$site.'/sitemap.xml';
        }

        return implode("\n", $lines)."\n";
    }

    /** robots.txt'nin varsayılanı (SEO ya da robots modülü kapalıyken render.php de aynısını yazar). */
    public static function defaultRobots(): string
    {
        $site = self::siteUrl();

        return "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /panel/\n".($site !== '' ? "\nSitemap: {$site}/sitemap.xml\n" : '');
    }

    public static function siteUrl(): string
    {
        $router = Router::load(Paths::siteRoot());

        return $router ? rtrim((string) ($router->manifest()['siteUrl'] ?? ''), '/') : '';
    }

    public static function indexable(): bool
    {
        $router = Router::load(Paths::siteRoot());

        return (bool) ($router?->manifest()['indexable'] ?? false);
    }

    /**
     * Günlük dosyasını alıp boşaltır (yarışsız: önce adı değişir).
     *
     * @return list<string> satırlar
     */
    public static function drain(string $name): array
    {
        $file = self::dir().'/'.$name;
        if (! is_file($file) || filesize($file) === 0) {
            return [];
        }
        $work = $file.'.'.getmypid().'.work';
        if (! @rename($file, $work)) {
            return [];
        }
        $lines = file($work, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        @unlink($work);

        return $lines;
    }
}
