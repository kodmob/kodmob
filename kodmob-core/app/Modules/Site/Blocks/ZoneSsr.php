<?php

namespace App\Modules\Site\Blocks;

use App\Modules\Site\Render\Router;

/**
 * Sayfa kabuğundaki bölgelere (`<!--km:zone home.main-->…<!--/km:zone-->`; derlemede `zone()`) yayındaki blokları
 * yazar. Düzenlenmemiş bölge (Layouts::forRender null) kabuktaki varsayılan haliyle kalır; boş liste bölgeyi
 * boşaltır. İşaretler çıktıda kalmaz.
 *
 * Önizlemede (Sayfa düzenleyici) her bölge `<div data-km-zone="…">` ile sarılır ve düzenlenmemiş bölgenin
 * varsayılan blokları da sunucuda (düzenleme işaretleriyle) çizilir.
 */
final class ZoneSsr
{
    private const MARKER = '#<!--km:zone ([a-zA-Z][a-zA-Z0-9.-]*)-->(.*?)<!--/km:zone-->#s';

    /** @return list<string> kabuktaki bölgeler */
    public static function zones(string $html): array
    {
        preg_match_all(self::MARKER, $html, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @param  array<string,?list<array>>  $layouts  bölge → bloklar (null: varsayılan)
     * @param  array<string,string>  $scoped  şablondaki bölge → kaydın bölgesi (blok sayfası: `page.main` → `page.main@12`;
     *                                        düzenleyici bu adla tanır)
     */
    public static function apply(string $html, array $layouts, string $locale, Router $router, bool $preview = false, array $scoped = []): string
    {
        if (! str_contains($html, '<!--km:zone ')) {
            return $html;
        }
        $renderer = new BlockRenderer($router);
        // Derlenmiş sayfada ikonlar ayrı dosyada (vite/plugins.js iconSprite): sunucunun çizdiği bloklar da oraya.
        $sprite = preg_match('/<html[^>]*\sdata-icons="([^"]+)"/', $html, $s) ? $s[1] : '';

        return (string) preg_replace_callback(self::MARKER, static function (array $m) use ($layouts, $locale, $renderer, $preview, $sprite, $scoped) {
            $zone = $m[1];
            $blocks = $layouts[$zone] ?? null;
            // Düzenlenmemiş bölge: kabuktaki hali; önizlemede ya da ilk bloklarının metni panelde değiştirilmişse
            // (Content\PageTexts; ZoneDefaults) sunucuda çizilir.
            if ($blocks === null && ($preview || ZoneDefaults::changed($zone, $locale))) {
                $blocks = ZoneDefaults::blocks($zone);
            }
            if ($blocks === null) {
                $inner = $m[2];
            } else {
                $inner = implode('', array_map(static fn (array $b) => $renderer->render($b, $locale, $preview), $blocks));
                if ($sprite !== '') {
                    $inner = str_replace(['href="#i-', "href='#i-"], ['href="'.$sprite.'#i-', "href='".$sprite.'#i-'], $inner);
                }
            }

            return $preview ? '<div data-km-zone="'.Template::escape($scoped[$zone] ?? $zone).'">'.$inner.'</div>' : $inner;
        }, $html);
    }

    /**
     * Sayfanın veri ihtiyacına bloklarınki eklenir (ör. Son yazılar → posts).
     *
     * @param  array<string,?list<array>>  $layouts
     * @return list<string>
     */
    public static function needs(array $layouts, bool $preview = false): array
    {
        $needs = [];
        foreach ($layouts as $zone => $blocks) {
            // Düzenlenmemiş bölge kabuktaki varsayılan bloklarla gelir; onların ihtiyacı da.
            $blocks ??= (array) (BlockLibrary::zone((string) $zone)['blocks'] ?? []);
            foreach ($blocks as $block) {
                if (empty($block['hidden']) || $preview) {
                    array_push($needs, ...(array) (BlockLibrary::block((string) ($block['type'] ?? ''))['needs'] ?? []));
                }
            }
        }

        return array_values(array_unique(array_map('strval', $needs)));
    }
}
