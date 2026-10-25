<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Content\Types;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Seo\Seo;
use App\Modules\Site\Render\Renderer;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;

/**
 * Yapay zekâ asistanları için site özeti (llmstxt.org biçimi):
 *
 * - /llms.txt       başlık, kısa tanıtım, dil başına sayfalar, kendi sayfası olan kayıtlar (ör. projeler) ve
 *                   yazılar (bağlantı + özet)
 * - /llms-full.txt  aynı içerik + kayıtların (türün llmsText kancası) ve yazıların tam metni (Markdown)
 *
 * noindex işaretli kayıtlar ve sayfalar dahil edilmez. Sayfa önbelleği gibi önbelleğe alınır.
 */
final class Llms
{
    /** Bölüm başlıkları; listede olmayan dil (derlemeyle sonradan eklenen) İngilizcesini alır. */
    private const HEAD = [
        'tr' => ['Sayfalar', 'Blog'],
        'en' => ['Pages', 'Blog'],
        'de' => ['Seiten', 'Blog'],
    ];

    public static function build(bool $full): string
    {
        $router = Router::load(Paths::siteRoot());
        if (! $router) {
            return '';
        }
        $m = $router->manifest();
        $site = rtrim((string) $m['siteUrl'], '/');
        $noindex = SeoMeta::noindexSet();
        $pages = PageMeta::all();
        $intro = trim((string) Seo::setting('seo.llms_intro'));
        [$homeTitle, $homeDescription] = PageMeta::defaults($router, self::route($m, 'home', Locales::default()) ?? []);

        $out = ['# '.trim(SiteManifest::siteName()), '', '> '.($intro !== '' ? $intro : $homeDescription), ''];
        if ($homeTitle !== '') {
            $out[] = $homeTitle.' · '.$site.'/';
            $out[] = '';
        }

        $routes = SiteManifest::pageRoutes();
        foreach (Locales::active() as $locale) {
            [$pagesTitle, $blogTitle] = self::HEAD[$locale] ?? self::HEAD['en'];
            $language = (string) (Languages::find($locale)['native'] ?? $locale);
            $out[] = '## '.$language;
            $out[] = '';
            $out[] = '### '.$pagesTitle;
            foreach ($m['routes'] as $route) {
                if ($route['locale'] !== $locale || empty($route['path']) || empty($route['sitemap']) || ! empty($pages[$route['name']][$locale]['noindex']) || Renderer::moduleOff($route)) {
                    continue;
                }
                [$title, $description] = PageMeta::defaults($router, $route);
                $o = $pages[$route['name']][$locale] ?? [];
                $out[] = '- ['.(($o['title'] ?? '') ?: $title).']('.$site.$route['path'].')'.((($o['description'] ?? '') ?: $description) !== '' ? ': '.(($o['description'] ?? '') ?: $description) : '');
            }
            $out[] = '';

            // Kendi sayfası olan türler (ör. projeler); bölüm başlığı kayıt listesinin adı (derlemenin metinleri).
            $records = [];
            foreach (Types::paged() as $kind => $type) {
                $items = array_filter($type->publicList($locale), static fn ($p) => ! isset($noindex[$kind.':'.$p['id']]));
                if (! $items) {
                    continue;
                }
                $records[$kind] = $items;
                $list = (string) (self::route($m, $routes[$kind], $locale)['fallback'] ?? '');
                $out[] = '### '.($m['strings'][$locale]['lists'][$list] ?? $m['strings'][$locale][$list] ?? $type->labels()[0]);
                foreach ($items as $p) {
                    $seo = SeoContent::resolve($kind, $p, $locale);
                    $out[] = '- ['.$p['title'].']('.$site.$router->url($routes[$kind], $locale, (string) $p['slug']).'): '.$seo['description'];
                }
                $out[] = '';
            }

            $posts = DB::table('posts')->whereNull('deleted_at')->where('status', 'publish')->where('published_at', '<=', Clock::now())->where('locale', $locale)->orderByDesc('published_at')->get();
            $posts = $posts->filter(static fn ($p) => ! isset($noindex['post:'.$p->id]));
            if ($posts->isNotEmpty()) {
                $out[] = '### '.$blogTitle;
                foreach ($posts as $p) {
                    $description = $p->excerpt !== '' ? $p->excerpt : Text::words(Text::stripTags((string) $p->html), 30, '…');
                    $out[] = '- ['.$p->title.']('.$site.$router->url('post', $locale, (string) $p->slug).'): '.$description;
                }
                $out[] = '';
            }

            if ($full) {
                foreach ($records as $kind => $items) {
                    foreach ($items as $p) {
                        $text = Types::page($kind)?->llmsText($p) ?? ['title' => (string) $p['title'], 'paragraphs' => []];
                        $out[] = '---';
                        $out[] = '';
                        $out[] = '#### '.$text['title'];
                        $out[] = $site.$router->url($routes[$kind], $locale, (string) $p['slug']);
                        $out[] = '';
                        foreach ($text['paragraphs'] as $paragraph) {
                            $out[] = trim($paragraph);
                            $out[] = '';
                        }
                    }
                }
                foreach ($posts as $p) {
                    $out[] = '---';
                    $out[] = '';
                    $out[] = '#### '.$p->title;
                    $out[] = $site.$router->url('post', $locale, (string) $p->slug).' · '.substr((string) $p->published_at, 0, 10);
                    $out[] = '';
                    $out[] = self::markdown((string) $p->html);
                    $out[] = '';
                }
            }
        }

        return rtrim(implode("\n", $out))."\n";
    }

    /** Basit HTML → Markdown (başlıklar, listeler, bağlantılar, vurgular). */
    public static function markdown(string $html): string
    {
        $md = preg_replace(['#<h2[^>]*>(.*?)</h2>#is', '#<h3[^>]*>(.*?)</h3>#is', '#<h4[^>]*>(.*?)</h4>#is'], ["\n##### $1\n", "\n###### $1\n", "\n**$1**\n"], $html) ?? $html;
        $md = preg_replace('#<li[^>]*>(.*?)</li>#is', "- $1\n", $md) ?? $md;
        $md = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '[$2]($1)', $md) ?? $md;
        $md = preg_replace(['#<(strong|b)>(.*?)</\1>#is', '#<(em|i)>(.*?)</\1>#is'], ['**$2**', '*$2*'], $md) ?? $md;
        $md = preg_replace('#<blockquote[^>]*>(.*?)</blockquote>#is', "\n> $1\n", $md) ?? $md;
        $md = preg_replace('#</(p|ul|ol|table|tr|blockquote)>#i', "\n", $md) ?? $md;
        $md = preg_replace('#</t[hd]>#i', ' | ', $md) ?? $md;
        $md = html_entity_decode(strip_tags($md), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $md) ?? $md) ?? $md);
    }

    private static function route(array $manifest, string $name, string $locale): ?array
    {
        foreach ($manifest['routes'] as $route) {
            if ($route['name'] === $name && $route['locale'] === $locale) {
                return $route;
            }
        }

        return null;
    }
}
