<?php

namespace App\Core\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Blog gövdesi için izin listeli HTML temizleyici (WordPress'teki
 * wp_kses_post'un karşılığı). Panelin zengin metin editörünün (TipTap)
 * ürettiği etiketler ve eski içerikteki figure/table kalır; geri kalanı:
 *
 * - Tehlikeli etiketler (script, iframe, form, svg…) içerikleriyle silinir.
 * - Bilinmeyen etiketler açılır (içindeki metin kalır).
 * - Öznitelikler izin listesindedir; `on*`, `style`, `class` atılır.
 * - Adresler yalnızca http(s), mailto, tel ve kök-göreli olabilir.
 * - `target="_blank"` bağlantılara `rel="noopener"` eklenir.
 */
final class HtmlSanitizer
{
    /** @var array<string,list<string>> etiket → izinli öznitelikler */
    private const TAGS = [
        'p' => [], 'br' => [], 'hr' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'del' => [], 'ins' => [], 'mark' => [],
        'sub' => [], 'sup' => [], 'small' => [], 'abbr' => ['title'], 'code' => [], 'pre' => [], 'kbd' => [],
        'blockquote' => ['cite'], 'q' => ['cite'], 'cite' => [], 'ul' => [], 'ol' => ['start', 'reversed', 'type'], 'li' => [],
        'dl' => [], 'dt' => [], 'dd' => [], 'a' => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'decoding', 'srcset', 'sizes'],
        'figure' => [], 'figcaption' => [], 'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'], 'span' => [], 'div' => [],
    ];

    /** İçerikleriyle birlikte silinen etiketler. */
    private const DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'option', 'noscript', 'template', 'svg', 'math', 'link', 'meta', 'base', 'head', 'title', 'audio', 'video', 'source', 'track', 'canvas'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="km-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('km-root');
        if (! $root) {
            return Text::stripTags($html);
        }
        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);

                continue;
            }
            self::walk($child);
            if (! isset(self::TAGS[$tag])) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }
            self::attributes($child, self::TAGS[$tag]);
        }
    }

    /** @param list<string> $allowed */
    private static function attributes(DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;
            $keep = in_array($name, $allowed, true);
            if ($keep && in_array($name, ['href', 'src', 'cite'], true)) {
                $value = self::url($value, $name === 'href');
                $keep = $value !== '';
            } elseif ($keep && $name === 'srcset') {
                $parts = array_filter(array_map(static function ($part) {
                    $bits = preg_split('/\s+/', trim($part), 2) ?: [];
                    $url = self::url($bits[0] ?? '', false);

                    return $url !== '' ? trim($url.' '.($bits[1] ?? '')) : '';
                }, explode(',', $value)));
                $value = implode(', ', $parts);
                $keep = $value !== '';
            } elseif ($keep && $name === 'target') {
                $keep = $value === '_blank';
            } elseif ($keep && in_array($name, ['width', 'height', 'colspan', 'rowspan', 'start'], true)) {
                $keep = ctype_digit($value);
            }
            $element->removeAttribute($attribute->name);
            if ($keep) {
                $element->setAttribute($name, $value);
            }
        }
        if ($element->getAttribute('target') === '_blank' && ! str_contains($element->getAttribute('rel'), 'noopener')) {
            $element->setAttribute('rel', trim($element->getAttribute('rel').' noopener'));
        }
    }

    private static function url(string $value, bool $link): string
    {
        $value = trim(preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($value === '') {
            return '';
        }
        if (preg_match('#^(/(?!/)|\#|\?)#', $value)) {
            return $value;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $schemes = $link ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'];
        if ($scheme === '' && ! str_contains($value, ':')) {
            return $value; // göreli adres (ör. "resim.webp")
        }

        return in_array($scheme, $schemes, true) ? $value : '';
    }
}
