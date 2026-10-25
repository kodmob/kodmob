<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\MediaPayload;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Options;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\Site\Render\Router;

/**
 * Sabit sayfaların (ana sayfa, projeler, hizmetler, iletişim…) SEO düzenlemeleri.
 * Varsayılan başlık ve açıklama derlemedeki sözlükten gelir (sayfa kabuğundan okunur);
 * panelden yazılan değer onların yerine geçer.
 *
 * Saklama: `seo_pages` seçeneği = { rota: { dil: { title, description, noindex, image_id } } }.
 */
final class PageMeta
{
    public const OPTION = 'seo_pages';

    /** Listede gösterilmeyen rotalar. */
    private const SKIP = ['notFound', 'panel', 'ui', 'uiPanel'];

    /** @return array<string,array<string,array{title:string,description:string,noindex:bool,image_id:int}>> */
    public static function all(): array
    {
        $value = Options::get(self::OPTION, []);

        return is_array($value) ? $value : [];
    }

    /** @return array{title:string,description:string,noindex:bool,image_id:int}|null */
    public static function get(string $route, string $locale): ?array
    {
        $row = self::all()[$route][$locale] ?? null;

        return is_array($row) ? $row + ['title' => '', 'description' => '', 'noindex' => false, 'image_id' => 0] : null;
    }

    /**
     * Panel listesi: her sabit sayfa ve dil için varsayılan ve düzenlenmiş değerler.
     *
     * @return list<array>
     */
    public static function list(): array
    {
        $router = Router::load(Paths::siteRoot());
        if (! $router) {
            return [];
        }
        $overrides = self::all();
        $rows = [];
        foreach ($router->manifest()['routes'] as $route) {
            if (empty($route['path']) || in_array($route['name'], self::SKIP, true)) {
                continue;
            }
            [$title, $description] = self::defaults($router, $route);
            $o = $overrides[$route['name']][$route['locale']] ?? [];
            $rows[] = [
                'key' => $route['name'].'-'.$route['locale'],
                'route' => $route['name'],
                'locale' => $route['locale'],
                'path' => $route['path'],
                'default_title' => $title,
                'default_description' => $description,
                'title' => (string) ($o['title'] ?? ''),
                'description' => (string) ($o['description'] ?? ''),
                'noindex' => (bool) ($o['noindex'] ?? false),
                'image_id' => (int) ($o['image_id'] ?? 0),
                'image' => ! empty($o['image_id']) ? MediaPayload::admin((int) $o['image_id']) : null,
                'customized' => (bool) array_filter($o),
                'in_sitemap' => ! empty($route['sitemap']),
            ];
        }

        return $rows;
    }

    public static function save(string $route, string $locale, array $input): array
    {
        $router = Router::load(Paths::siteRoot());
        $known = false;
        foreach ($router?->manifest()['routes'] ?? [] as $r) {
            if ($r['name'] === $route && $r['locale'] === $locale && ! empty($r['path'])) {
                $known = true;
            }
        }
        if (! $known || in_array($route, self::SKIP, true)) {
            throw ValidationException::field('route', 'Bilinmeyen sayfa.');
        }
        $all = self::all();
        $row = [
            'title' => mb_substr(Text::field($input['title'] ?? ''), 0, SeoMeta::TITLE_MAX),
            'description' => mb_substr(Text::field($input['description'] ?? ''), 0, SeoMeta::DESCRIPTION_MAX),
            'noindex' => filter_var($input['noindex'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'image_id' => max(0, (int) ($input['image_id'] ?? 0)),
        ];
        if (array_filter($row)) {
            $all[$route][$locale] = $row;
        } else {
            unset($all[$route][$locale]);
            if (empty($all[$route])) {
                unset($all[$route]);
            }
        }
        Options::set(self::OPTION, $all);

        return $row;
    }

    /** @return array{0:string,1:string} kabuktaki başlık ve açıklama */
    public static function defaults(Router $router, array $route): array
    {
        $file = Paths::siteRoot().'/'.ltrim((string) ($route['shell'] ?? ''), '/');
        $html = is_file($file) ? (string) file_get_contents($file, false, null, 0, 20000) : '';
        $title = preg_match('#<title>(.*?)</title>#s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $description = preg_match('#<meta name="description" content="([^"]*)"#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

        return [trim($title), trim($description)];
    }
}
