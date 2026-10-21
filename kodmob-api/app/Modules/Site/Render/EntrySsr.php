<?php

namespace App\Modules\Site\Render;

/**
 * Kayıt sayfasının (proje, blog yazısı) asıl içeriğini sunucuda HTML'e yazar: JavaScript
 * çalıştırmayan tarayıcılar ve botlar (Bing'in bir kısmı, GPTBot, ClaudeBot, Perplexity,
 * paylaşım önizlemeleri) başlığı ve metni görsün. Alpine aynı içeriği üzerine yazıp
 * çalışmaya devam eder; çift içerik ya da titreme olmaz.
 *
 * Şablondaki işaretler (Vite geliştirme sunucusunda yorum olarak kalır, etkisizdir):
 *
 *   <template x-if="$store.km.entry" data-km-ssr> … </template><!--/km:ssr-->
 *       kayıt kapısı: sunucu kaydın var olduğunu bildiği için açılır (içeriği gerçek DOM olur)
 *   <h1 x-text="p.tagline || p.title"><!--km:e:tagline|title--></h1>
 *       metin alanı (soldan ilk dolu alan; kaçışlı)
 *   <div x-html="post.html"><!--km:h:html--></div>
 *       HTML alanı (kayıtta HtmlSanitizer'dan geçmiş, ContentFilters işlemiş gövde)
 *   <div x-html="$store.km.paragraphs(p.about)"><!--km:p:about--></div>
 *       paragraf listesi
 */
final class EntrySsr
{
    public static function apply(string $html, array $entry): string
    {
        $html = (string) preg_replace('#<template x-if="\$store\.km\.entry" data-km-ssr>#', '', $html, 1, $opened);
        if ($opened) {
            $html = str_replace('</template><!--/km:ssr-->', '', $html);
        }

        return (string) preg_replace_callback('#<!--km:([ehp]):([\w|]+)-->#', static function (array $m) use ($entry) {
            $fields = explode('|', $m[2]);
            if ($m[1] === 'h') {
                return is_string($entry[$fields[0]] ?? null) ? $entry[$fields[0]] : '';
            }
            if ($m[1] === 'p') {
                $list = $entry[$fields[0]] ?? [];
                $list = is_array($list) ? $list : preg_split('/\n\s*\n/', (string) $list);

                return implode('', array_map(static fn ($p) => '<p>'.self::e((string) $p).'</p>', array_filter($list, static fn ($p) => is_string($p) && trim($p) !== '')));
            }
            foreach ($fields as $field) {
                $value = $entry[$field] ?? '';
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return self::e((string) $value);
                }
            }

            return '';
        }, $html);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
