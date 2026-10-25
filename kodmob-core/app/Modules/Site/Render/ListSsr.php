<?php

namespace App\Modules\Site\Render;

/**
 * Liste sayfalarının (projeler, blog, ana sayfa kartları) öğelerini sunucuda HTML'e yazar: JavaScript
 * çalıştırmayan botlar ve paylaşım önizlemeleri başlıkları, özetleri ve bağlantıları görsün.
 * Alpine açılırken bu kopyalar kaldırılır (src/app.js, `data-km-ssr-item`) ve liste her zamanki
 * gibi çizilir; çift içerik olmaz.
 *
 * Şablondaki işaret (`x-for` şablonunda):
 *
 *   <template x-for="(p, i) in $store.km.projects" :key="p.id"
 *             data-km-ssr-list="projects"      sayfa verisindeki liste (PageData)
 *             data-km-ssr-route="project"      öğe adresi bu kayıt rotasıyla (`url`; isteğe bağlı)
 *             data-km-ssr-limit="3"            ilk N öğe (isteğe bağlı)
 *             data-km-ssr-prefer="featured">   bu alanı dolu olanlar, yoksa hepsi (isteğe bağlı)
 *
 * Öğe kopyasında yalnızca basit yollar doldurulur: `x-text="p.title"` (boş öğenin metni),
 * `:href|:alt|:title|:src="p.url"`. Karmaşık ifadeler ve iç şablonlar (görsel, etiketler) Alpine'a
 * kalır. `x-cloak` kaldırılır (JavaScript yokken de görünsün).
 */
final class ListSsr
{
    private const ATTRS = ['href', 'alt', 'title', 'src'];

    /** @param callable(string $route, string $slug): ?string $url */
    public static function apply(string $html, array $data, callable $url): string
    {
        $offset = 0;
        while (($start = strpos($html, '<template', $offset)) !== false) {
            $tagEnd = strpos($html, '>', $start);
            if ($tagEnd === false) {
                break;
            }
            $tag = substr($html, $start, $tagEnd - $start + 1);
            $offset = $tagEnd + 1;
            if (! str_contains($tag, 'data-km-ssr-list=') || ! preg_match('/x-for="\(?\s*(\w+)/', $tag, $var)) {
                continue;
            }
            $close = self::closing($html, $tagEnd + 1);
            if ($close === null) {
                break;
            }
            $inner = substr($html, $tagEnd + 1, $close - $tagEnd - 1);
            $items = self::items($tag, $data, $url);
            $rendered = implode('', array_map(static fn (array $item) => self::render($inner, $var[1], $item), $items));
            $html = substr($html, 0, $start).$rendered.substr($html, $start);
            $offset = $start + strlen($rendered) + ($close - $start);
        }

        return $html;
    }

    /** Eşleşen `</template>`'in konumu (iç şablonlar sayılır). */
    private static function closing(string $html, int $from): ?int
    {
        $depth = 1;
        while (preg_match('#<template\b|</template>#', $html, $m, PREG_OFFSET_CAPTURE, $from)) {
            $depth += $m[0][0] === '</template>' ? -1 : 1;
            if ($depth === 0) {
                return $m[0][1];
            }
            $from = $m[0][1] + strlen($m[0][0]);
        }

        return null;
    }

    private static function items(string $tag, array $data, callable $url): array
    {
        $attr = static fn (string $name) => preg_match('/data-km-ssr-'.$name.'="([\w-]*)"/', $tag, $m) ? $m[1] : '';
        $items = array_values(array_filter((array) ($data[$attr('list')] ?? []), 'is_array'));
        if (($prefer = $attr('prefer')) !== '') {
            $preferred = array_values(array_filter($items, static fn ($i) => ! empty($i[$prefer])));
            $items = $preferred ?: $items;
        }
        if (($limit = (int) $attr('limit')) > 0) {
            $items = array_slice($items, 0, $limit);
        }
        if (($route = $attr('route')) !== '') {
            $items = array_map(static fn ($i) => $i + ['url' => isset($i['slug']) ? (string) $url($route, (string) $i['slug']) : ''], $items);
        }

        return array_slice($items, 0, 60);
    }

    private static function render(string $inner, string $var, array $item): string
    {
        $value = static function (string $path) use ($item): ?string {
            $current = $item;
            foreach (explode('.', $path) as $key) {
                if (! is_array($current) || ! array_key_exists($key, $current)) {
                    return null;
                }
                $current = $current[$key];
            }

            return is_scalar($current) ? (string) $current : null;
        };
        $v = preg_quote($var, '#');
        // Metin: boş öğe, basit yol.
        $html = (string) preg_replace_callback('#(<([a-z][a-z0-9]*)\b[^>]*\sx-text="'.$v.'\.([\w.]+)"[^>]*>)(</\2>)#', static function ($m) use ($value) {
            $text = $value($m[3]);

            return $text === null ? $m[0] : $m[1].self::e($text).$m[4];
        }, $inner);
        // Öznitelikler: :href="p.url" → href="…" (bağlama kalır; Alpine zaten bu kopyayı kaldırır).
        $html = (string) preg_replace_callback('#\s:('.implode('|', self::ATTRS).')="'.$v.'\.([\w.]+)"#', static function ($m) use ($value) {
            $text = $value($m[2]);

            return $text === null || $text === '' ? $m[0] : $m[0].' '.$m[1].'="'.self::e($text).'"';
        }, $html);
        $html = (string) preg_replace('#\sx-cloak(?=[\s>])#', '', $html);

        // Kök öğe işaretlenir: Alpine açılırken kaldırılır.
        return (string) preg_replace('#<([a-z][a-z0-9]*)\b#', '<$1 data-km-ssr-item', ltrim($html), 1);
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
