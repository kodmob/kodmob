<?php

namespace App\Modules\Site\Blocks;

/**
 * Blok şablonu: Mustache'ın küçük bir alt kümesi (mantıksız; ifade, işlev, kısmi şablon yok). Ön yüzdeki
 * eşi tv/src/blocks/template.js; iki taraf aynı örneklerle sınanır (tests/fixtures/blocks/template.json).
 *
 *   {{ yol }}               kaçışlı değer (metin ve öznitelikte güvenli)
 *   {{{ yol }}}             kaçışsız (yalnızca kayıtta temizlenmiş HTML alanı için)
 *   {{# yol }}…{{/ yol }}   liste: her öğe için; nesne: bir kez, onun içinde; doğru değer: bir kez
 *   {{^ yol }}…{{/ yol }}   boşsa (yok, false, '', 0, boş liste)
 *   {{! not }}              yorum
 *
 * Yol: `title`, `cover.src`, `.` (döngüdeki öğe), `items.length` (listenin uzunluğu; bir kez saran öğe için
 * `{{#items.length}}<ul>…</ul>{{/items.length}}`), döngüde `@index` (0'dan), `@number` (1'den), `@first`,
 * `@last`. Ad önce en içteki bağlamda, yoksa dıştakilerde aranır.
 */
final class Template
{
    private const TOKEN = '/\{\{(\{|#|\^|\/|!)?\s*([^}]*?)\s*\}?\}\}/';

    /**
     * Şablonu ağaca çevirir.
     *
     * @return list<string|array<string,mixed>>
     */
    public static function parse(string $template): array
    {
        $root = ['children' => []];
        $stack = [&$root];
        $last = 0;
        preg_match_all(self::TOKEN, $template, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            [$whole, $at] = $m[0];
            $sigil = $m[1][0];
            $name = $m[2][0];
            $node = &$stack[count($stack) - 1];
            if ($at > $last) {
                $node['children'][] = substr($template, $last, $at - $last);
            }
            $last = $at + strlen($whole);
            if ($sigil === '!') {
                unset($node);

                continue;
            }
            if ($sigil === '#' || $sigil === '^') {
                $node['children'][] = ['section' => $name, 'inverted' => $sigil === '^', 'children' => []];
                $stack[] = &$node['children'][array_key_last($node['children'])];
            } elseif ($sigil === '/') {
                if (count($stack) < 2 || ($node['section'] ?? null) !== $name) {
                    throw new \InvalidArgumentException("Şablon: {{/{$name}}} beklenmedik");
                }
                array_pop($stack);
            } else {
                $node['children'][] = ['name' => $name, 'raw' => $sigil === '{'];
            }
            unset($node);
        }
        if (count($stack) > 1) {
            throw new \InvalidArgumentException('Şablon: {{#'.$stack[count($stack) - 1]['section'].'}} kapanmadı');
        }
        if ($last < strlen($template)) {
            $root['children'][] = substr($template, $last);
        }

        return $root['children'];
    }

    /** @param string|list<string|array<string,mixed>> $template */
    public static function render(string|array $template, array $data = []): string
    {
        return self::nodes(is_string($template) ? self::parse($template) : $template, [$data]);
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function nodes(array $nodes, array $stack): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (is_string($node)) {
                $out .= $node;
            } elseif (! isset($node['section'])) {
                $value = self::text(self::lookup($stack, $node['name']));
                $out .= $node['raw'] ? $value : self::escape($value);
            } else {
                $value = self::lookup($stack, $node['section']);
                if ($node['inverted']) {
                    if (! self::truthy($value)) {
                        $out .= self::nodes($node['children'], $stack);
                    }
                } elseif (is_array($value) && array_is_list($value)) {
                    $count = count($value);
                    foreach ($value as $i => $item) {
                        $out .= self::nodes($node['children'], [...$stack, ['@index' => $i, '@number' => $i + 1, '@first' => $i === 0, '@last' => $i === $count - 1], $item]);
                    }
                } elseif (is_array($value)) {
                    $out .= self::nodes($node['children'], [...$stack, $value]);
                } elseif (self::truthy($value)) {
                    $out .= self::nodes($node['children'], $stack);
                }
            }
        }

        return $out;
    }

    /** En içteki bağlamdan dışa doğru yol. */
    private static function lookup(array $stack, string $path): mixed
    {
        if ($path === '.') {
            return $stack[count($stack) - 1];
        }
        $parts = explode('.', $path);
        $head = array_shift($parts);
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $ctx = $stack[$i];
            if (is_array($ctx) && ! array_is_list($ctx) && array_key_exists($head, $ctx)) {
                $value = $ctx[$head];
                foreach ($parts as $key) {
                    if (is_array($value) && array_is_list($value)) {
                        $value = $key === 'length' ? count($value) : null;
                    } else {
                        $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
                    }
                }

                return $value;
            }
        }

        return null;
    }

    /** JavaScript'teki eşiyle aynı: '0' metni doğrudur, 0 sayısı değildir. */
    private static function truthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0 || $value === []);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
