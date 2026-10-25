<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\Locales;
use App\Core\Content\SlugHistory;
use App\Core\Content\Types;
use App\Core\Security\Capability;
use App\Core\Support\Clock;
use App\Core\Support\Options;
use App\Core\Support\PanelUrl;
use App\Core\Support\Paths;
use App\Modules\Blog\BlogPosts;
use App\Modules\Content\PageData;
use App\Modules\Notifications\NotificationService;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;

/**
 * Kırık bağlantı denetleyicisi (done-coklu-dil-raporu.md §4.7 "Önleme", Faz 6): yayındaki içeriğin
 * (blog yazıları; kayıt türlerinde HTML alanı varsa onlar da — bugün proje, hizmet ve referans
 * metinleri düz yazı) iç bağlantılarını sitenin adres
 * eşleştiricisinden geçirir. Sorunlar:
 * - `broken`: sayfa yok (404),
 * - `gone`: içerik silinmiş (410),
 * - `redirect`: adres yönleniyor (çalışır ama bağlantı yeni adrese güncellenmeli; `target`).
 *
 * "Yönlenen bağlantıları güncelle" (`fixRedirects`) yönlenen bağlantıları içerikte son hedefle
 * değiştirir (sorgu ve # parçası korunur; çeviriler eskimez).
 *
 * Sonuç `seo.link_check` seçeneğinde saklanır; panelde SEO > 404'te listelenir. Her gece ve
 * panelden "Yeniden denetle" ile çalışır; yeni kırık bağlantı çıkarsa SEO yetkisi olanlara
 * bildirim gider. Menü ve sayfa bağlantıları derlemede üretildiği için denetlenmez.
 */
final class LinkChecker
{
    public const OPTION = 'seo.link_check';

    /** @var array<string,?array> adres → sonuç (bir çalıştırmada tekrar denetlenmez) */
    private array $seen = [];

    private string $site = '';

    public function __construct(private readonly ?Router $router = null) {}

    /** Son sonuç: { checked_at, items } (hiç çalışmadıysa checked_at null). */
    public static function last(): array
    {
        $value = Options::get(self::OPTION);

        return is_array($value) ? $value : ['checked_at' => null, 'items' => []];
    }

    /** Denetler, saklar; yeni kırık bağlantı varsa bildirir. */
    public static function run(bool $notify = true): array
    {
        $before = array_map(self::key(...), array_filter(self::last()['items'], static fn ($i) => $i['problem'] !== 'redirect'));
        $result = ['checked_at' => Clock::atom(Clock::now()), 'items' => (new self(Router::load(Paths::siteRoot())))->check()];
        Options::set(self::OPTION, $result);

        $new = array_filter($result['items'], static fn ($i) => $i['problem'] !== 'redirect' && ! in_array(self::key($i), $before, true));
        if ($notify && $new) {
            $first = reset($new);
            NotificationService::notifyCapable(
                Capability::MANAGE_SEO,
                'seo.broken_content_link',
                count($new) === 1 ? 'İçerikte kırık bağlantı' : count($new).' kırık bağlantı',
                sprintf('"%s" içindeki %s bağlantısı açılmıyor%s.', $first['title'], $first['url'], count($new) > 1 ? ' (ve diğerleri)' : ''),
                PanelUrl::to('seo', ['tab' => '404']),
                'warning'
            );
        }

        return $result;
    }

    /**
     * Yönlenen bağlantıları içerikte hedefleriyle değiştirir; yeniden denetler.
     *
     * @return array{fixed:int,checked_at:string,items:list<array>} `fixed`: güncellenen kayıt sayısı
     */
    public static function fixRedirects(): array
    {
        $bySource = [];
        foreach (self::run(false)['items'] as $item) {
            if ($item['problem'] === 'redirect' && $item['target'] !== '') {
                $suffix = (string) preg_replace('#^[^?\#]*#', '', $item['url']);
                $bySource[$item['type'].':'.$item['id']][$item['url']] = $item['target'].$suffix;
            }
        }
        $fixed = 0;
        foreach ($bySource as $key => $map) {
            [$type, $id] = explode(':', $key);
            $done = $type === 'posts' ? (new BlogPosts)->replaceLinks((int) $id, $map) : (bool) Types::get($type)?->replaceLinks((int) $id, $map);
            $fixed += $done ? 1 : 0;
        }

        return ['fixed' => $fixed] + self::run(false);
    }

    /** @return list<array{type:string,id:int,title:string,locale:string,url:string,problem:string,target:string}> */
    public function check(): array
    {
        if ($this->router === null) {
            return [];
        }
        $this->site = rtrim((string) ($this->router->manifest()['siteUrl'] ?? ''), '/');
        $items = [];
        foreach ($this->sources() as $source) {
            foreach (self::links($source['text']) as $url) {
                $problem = $this->status($url);
                if ($problem !== null) {
                    $items[] = ['type' => $source['type'], 'id' => $source['id'], 'title' => $source['title'], 'locale' => $source['locale'], 'url' => $url] + $problem;
                }
            }
        }
        // Önce kırıklar, sonra silinenler, sonra yönlenenler.
        $order = ['broken' => 0, 'gone' => 1, 'redirect' => 2];
        usort($items, static fn ($a, $b) => [$order[$a['problem']], $a['title']] <=> [$order[$b['problem']], $b['title']]);

        return $items;
    }

    /** @return iterable<array{type:string,id:int,title:string,locale:string,text:string}> */
    private function sources(): iterable
    {
        $posts = DB::table('posts')->whereNull('deleted_at')->where('status', 'publish')->where('published_at', '<=', Clock::now())
            ->get(['id', 'title', 'locale', 'html', 'excerpt']);
        foreach ($posts as $post) {
            yield ['type' => 'posts', 'id' => (int) $post->id, 'title' => (string) $post->title, 'locale' => Locales::normalize((string) $post->locale), 'text' => $post->html.' '.$post->excerpt];
        }
        foreach (Types::all() as $name => $type) {
            foreach ($type->records(true) as $record) {
                foreach (Locales::all() as $locale) {
                    if (! $type->visibleIn($record->data, $locale)) {
                        continue;
                    }
                    $values = (array) ($record->data['i18n'][$locale] ?? []);
                    $text = '';
                    array_walk_recursive($values, static function ($value) use (&$text) {
                        if (is_string($value) && str_contains($value, 'href')) {
                            $text .= ' '.$value;
                        }
                    });
                    if ($text !== '') {
                        $title = (string) ($values[(string) $type->localeKey()] ?? '');
                        yield ['type' => $name, 'id' => $record->id, 'title' => $title !== '' ? strip_tags($title) : '#'.$record->id, 'locale' => $locale, 'text' => $text];
                    }
                }
            }
        }
    }

    /** Metindeki iç bağlantılar (kök-göreli ya da sitenin tam adresi), yolları. @return list<string> */
    private static function links(string $html): array
    {
        if (! preg_match_all('#href\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /** @return array{problem:string,target:string}|null null = sorun yok ya da iç bağlantı değil */
    private function status(string $href): ?array
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5);
        if ($this->site !== '' && str_starts_with($href, $this->site)) {
            $href = substr($href, strlen($this->site)) ?: '/';
        }
        if (! str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return null;
        }
        $path = rawurldecode((string) (parse_url($href, PHP_URL_PATH) ?: '/'));
        if (array_key_exists($path, $this->seen)) {
            return $this->seen[$path];
        }

        return $this->seen[$path] = $this->resolve($path);
    }

    private function resolve(string $path): ?array
    {
        if (preg_match('#^/(api|panel)(/|$)#', $path)) {
            return null;
        }
        if (preg_match('#^/(media|assets)/#', $path)) {
            return is_file(Paths::siteRoot().$path) ? null : ['problem' => 'broken', 'target' => ''];
        }
        $match = $this->router->match($path);
        if ($match['kind'] === 'redirect') {
            return ['problem' => 'redirect', 'target' => (string) $match['location']];
        }
        if ($match['kind'] === 'none' || ($match['kind'] === 'page' && $match['status'] === 404)) {
            return ['problem' => 'broken', 'target' => ''];
        }
        $route = $match['route'] ?? [];
        if ($match['kind'] !== 'page' || empty($route['dynamic'])) {
            return null;
        }
        if (empty(PageData::build((string) $route['name'], (string) $match['locale'], (string) $match['slug'])['notFound'])) {
            return null;
        }
        $resolved = SlugHistory::resolve((string) $route['dynamic'], (string) $match['locale'], (string) $match['slug'], fn (string $l, string $s) => $this->router->url((string) $route['name'], $l, $s));
        if (isset($resolved['location'])) {
            return ['problem' => 'redirect', 'target' => $resolved['location']];
        }

        return ['problem' => isset($resolved['gone']) ? 'gone' : 'broken', 'target' => ''];
    }

    private static function key(array $item): string
    {
        return $item['type'].':'.$item['id'].':'.$item['locale'].':'.$item['url'];
    }
}
