<?php

namespace App\Modules\Site\Render;

use App\Core\Content\PageCache;
use App\Core\Security\Capability;
use App\Modules\Auth\Models\User;
use App\Modules\Seo\Services\SeoConfig;
use App\Modules\Site\Blocks\PreviewToken;
use Illuminate\Support\Facades\Log;

/**
 * public/render.php'nin Laravel tarafı: önbellekte güncel kopya yoksa sayfa,
 * sitemap ya da RSS burada üretilir ve önbelleğe yazılır.
 *
 * Önbellek dosyası: storage/framework/pages/<md5(yol)>-<durum>.<html|xml>
 * (render.php aynı adla okur).
 */
final class SitePage
{
    public function __construct(private readonly Router $router) {}

    /**
     * `$previewToken`: Sayfa düzenleyicinin önizlemesi (Site\Blocks\PreviewToken; render.php geçerli anahtarı
     * verir). Kullanıcı hâlâ etkin ve düzenleyici yetkisindeyse taslak bloklar çizilir, önbelleğe yazılmaz.
     *
     * @return array{0:int,1:string,2:array<string,string>} durum kodu, gövde, ek başlıklar (301'de Location)
     */
    public function render(array $match, string $path, string $previewToken = ''): array
    {
        $headers = [];
        $renderer = new Renderer($this->router);
        $preview = $previewToken !== '' && self::previewer($previewToken);
        // render.php'nin okuduğu SEO yapılandırması henüz yoksa ya da eski biçimdeyse (sürüm güncellemesi).
        $compiled = is_file(SeoConfig::dir().'/config.json') ? json_decode((string) file_get_contents(SeoConfig::dir().'/config.json'), true) : null;
        if (($compiled['schema'] ?? 0) !== SeoConfig::SCHEMA) {
            SeoConfig::compile();
        }
        try {
            if ($match['kind'] === 'sitemap') {
                [$status, $body] = [200, $renderer->sitemap()];
            } elseif ($match['kind'] === 'rss') {
                [$status, $body] = [200, $renderer->rss($match['locale'])];
            } elseif ($match['kind'] === 'llms') {
                $body = $renderer->llms((bool) $match['full']);
                $status = $body === '' ? 404 : 200;
            } else {
                $page = $renderer->page($match, $preview);
                [$status, $body] = $page;
                $headers = $page[2] ?? [];
            }
            if ($preview) {
                return [$status, $body, $headers + ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow']];
            }
        } catch (\Throwable $e) {
            Log::error('[Kodmob render] '.$path.': '.$e->getMessage(), ['exception' => $e]);

            // Veri olmadan kabuk: tarayıcı içeriği /page-data ile kendisi ister.
            return $match['kind'] === 'page' ? [200, $this->router->shell($match['shell'], $match['locale'] ?? null), []] : [500, '', []];
        }

        // Yalnızca 200 ve 404 önbelleğe girer (render.php yalnız bunlara bakar); 301 ve 410 her seferinde üretilir.
        if (in_array($status, [200, 404], true)) {
            $dir = PageCache::dir();
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($dir.'/'.self::cacheName($path, $status, self::extension($match['kind'])), $body, LOCK_EX);
        }

        return [$status, $body, $headers];
    }

    /** Önizleme anahtarının sahibi etkin ve Sayfa düzenleyici yetkili mi. */
    private static function previewer(string $token): bool
    {
        $id = PreviewToken::user(storage_path(), $token);
        $user = $id ? User::query()->find($id) : null;

        return $user !== null && $user->canAccessPanel() && $user->can(Capability::EDIT_LAYOUT);
    }

    public static function cacheName(string $path, int $status, string $extension): string
    {
        return md5($path).'-'.$status.'.'.$extension;
    }

    /** Önbellek dosyasının uzantısı (render.php aynısını kullanır). */
    public static function extension(string $kind): string
    {
        return match ($kind) {
            'page' => 'html',
            'llms' => 'txt',
            default => 'xml',
        };
    }
}
