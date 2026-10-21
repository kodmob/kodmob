<?php

namespace App\Modules\Seo\Services;

use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Seo\Seo;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Blog yazısının HTML'i ziyaretçiye giderken (sayfa ve /page-data):
 *
 * - toc: ara başlıklara (H2/H3) kimlik verir, yazının başına içindekiler listesi ekler
 *   (en az `seo.toc_min` başlık varsa).
 * - links: dış bağlantılara yeni sekme (rel="noopener") ve isteğe bağlı nofollow.
 *
 * Kayıttaki HTML değişmez; yalnızca çıktı.
 */
final class ContentFilters
{
    public static function post(string $html, string $locale): string
    {
        $toc = Seo::on('toc');
        $links = Seo::on('links');
        if (trim($html) === '' || (! $toc && ! $links)) {
            return $html;
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="km-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('km-root');
        if (! $root) {
            return $html;
        }
        $xpath = new DOMXPath($doc);

        if ($links) {
            $host = parse_url(SeoConfig::siteUrl(), PHP_URL_HOST) ?: '';
            $newTab = (bool) Seo::setting('seo.links_new_tab');
            $nofollow = (bool) Seo::setting('seo.links_nofollow');
            foreach ($xpath->query('.//a[@href]', $root) as $a) {
                /** @var DOMElement $a */
                $href = $a->getAttribute('href');
                $linkHost = parse_url($href, PHP_URL_HOST);
                if (! preg_match('#^https?://#i', $href) || ! $linkHost || ($host !== '' && strcasecmp($linkHost, $host) === 0)) {
                    continue;
                }
                $rel = array_filter(explode(' ', $a->getAttribute('rel')));
                if ($newTab) {
                    $a->setAttribute('target', '_blank');
                    $rel = array_merge($rel, ['noopener']);
                }
                if ($nofollow) {
                    $rel[] = 'nofollow';
                }
                if ($rel) {
                    $a->setAttribute('rel', implode(' ', array_unique($rel)));
                }
            }
        }

        $items = [];
        if ($toc) {
            $headings = $xpath->query('.//h2|.//h3', $root);
            if ($headings->length >= max(2, (int) Seo::setting('seo.toc_min'))) {
                $used = [];
                foreach ($headings as $h) {
                    /** @var DOMElement $h */
                    $text = trim($h->textContent);
                    $id = $h->getAttribute('id') ?: (Text::slug($text) ?: 'bolum');
                    for ($n = 2, $base = $id; isset($used[$id]); $n++) {
                        $id = $base.'-'.$n;
                    }
                    $used[$id] = true;
                    $h->setAttribute('id', $id);
                    $items[] = ['id' => $id, 'text' => $text, 'level' => $h->nodeName === 'h3' ? 3 : 2];
                }
            }
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        if ($items) {
            $list = '';
            foreach ($items as $item) {
                $list .= '<li class="toc-'.$item['level'].'"><a href="#'.htmlspecialchars($item['id'], ENT_QUOTES).'">'.htmlspecialchars($item['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</a></li>';
            }
            // Başlık sitenin sözlüğünden (tv/src/i18n → post.toc), dilde yoksa varsayılan dilden.
            $title = htmlspecialchars(SiteManifest::string('toc', $locale, 'Contents'), ENT_QUOTES, 'UTF-8');
            $out = '<nav class="toc" aria-label="'.$title.'"><p class="toc-title">'.$title.'</p><ol>'.$list.'</ol></nav>'.$out;
        }

        return trim($out);
    }
}
