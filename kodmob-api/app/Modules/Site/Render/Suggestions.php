<?php

namespace App\Modules\Site\Render;

use App\Core\Content\Locales;
use App\Core\Content\Types;
use App\Core\Support\SiteManifest;
use App\Modules\Blog\BlogPosts;
use App\Modules\Live\PulseFile;
use Illuminate\Support\Facades\Cache;

/**
 * 404 sayfasındaki "Bunu mu demek istediniz?" (done-coklu-dil-raporu.md §4.7): bulunamayan adrese
 * yazımca en yakın, o dildeki bilinen adresler. Adaylar: sabit sayfalar (manifest), o dilde
 * yayındaki projeler ve yazılar. Aday listesi dil başına 10 dakika önbellekte.
 *
 * 404 HTML'i önbellekte kalabilsin diye öneriler tarayıcıdan istenir (`GET /suggest?path=`).
 */
final class Suggestions
{
    /** Bu oranın altındaki benzerlik öneri sayılmaz (0–100). */
    private const MIN_SCORE = 55;

    private const SKIP = ['notFound', 'panel', 'ui', 'uiPanel'];

    public function __construct(private readonly Router $router) {}

    /** @return list<array{url:string,title:string}> en fazla `$limit` öneri, en yakını önce */
    public function for(string $path, int $limit = 3): array
    {
        $path = '/'.ltrim(mb_strtolower(rawurldecode($path)), '/');
        $locale = $this->router->localeOf($path);
        $needle = self::key($path);
        if ($needle === '') {
            return [];
        }
        $scored = [];
        foreach ($this->candidates($locale) as $candidate) {
            if (rtrim($candidate['url'], '/') === rtrim($path, '/')) {
                continue;
            }
            $score = self::similarity($needle, self::key($candidate['url']));
            if ($score >= self::MIN_SCORE) {
                $scored[] = $candidate + ['score' => $score];
            }
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(static fn ($c) => ['url' => $c['url'], 'title' => $c['title']], array_slice($scored, 0, $limit));
    }

    /** @return list<array{url:string,title:string}> */
    private function candidates(string $locale): array
    {
        // İçerik değişince (PulseFile 'content') yeni liste.
        $version = (string) (PulseFile::snapshot()['content'] ?? '0');

        return Cache::remember("km.suggest.$locale.$version", 600, function () use ($locale) {
            $out = [];
            foreach ($this->router->manifest()['routes'] as $route) {
                if (($route['locale'] ?? '') !== $locale || ! isset($route['path']) || in_array($route['name'], self::SKIP, true) || str_ends_with($route['path'], '.html')) {
                    continue;
                }
                $out[] = ['url' => $route['path'], 'title' => ''];
            }
            if (! Locales::valid($locale)) {
                return $out;
            }
            $routes = SiteManifest::pageRoutes();
            foreach (Types::paged() as $kind => $type) {
                foreach ($type->publicList($locale) as $entry) {
                    if (($url = $this->router->url($routes[$kind], $locale, (string) $entry['slug'])) !== null) {
                        $out[] = ['url' => $url, 'title' => (string) $entry['title']];
                    }
                }
            }
            foreach ((new BlogPosts)->publicList($locale) as $post) {
                if (($url = $this->router->url('post', $locale, (string) $post['slug'])) !== null) {
                    $out[] = ['url' => $url, 'title' => (string) $post['title']];
                }
            }

            return $out;
        });
    }

    /** Karşılaştırılan kısım: önekten ve bölüm adından bağımsız son parça (yoksa yolun tamamı). */
    private static function key(string $url): string
    {
        $parts = array_values(array_filter(explode('/', trim($url, '/')), static fn ($p) => $p !== ''));

        return str_replace(['-', '_', '.html'], ' ', (string) end($parts) ?: '');
    }

    /** 0–100: ortak harf oranı ve düzenleme uzaklığının ortalaması. */
    private static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0;
        }
        similar_text($a, $b, $percent);
        $max = max(strlen($a), strlen($b));
        $distance = $max > 255 ? $max : levenshtein($a, $b);

        return ($percent + (1 - $distance / $max) * 100) / 2;
    }
}
