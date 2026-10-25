<?php

namespace App\Modules\Site\Blocks;

use App\Modules\Content\PageTexts;

/**
 * Bölgenin derlemedeki ilk blokları, panelden değiştirilmiş sayfa metinleriyle (Content\PageTexts).
 *
 * Şablonda `tx()` ile yazılmış bir sayfa bloklara geçince (ör. kodmob.com ana sayfası) "Sayfa metinleri"nde
 * değiştirilmiş metinler kaybolmasın diye derleme her ilk bloğa metinlerinin sözlük anahtarını yazar (`texts`: alan
 * yolu → anahtar; `title` → `home.hero.title`, `checks.0.text` → `home.hero.checks.0`; kodmob-frontend/vite/blocks.js). Bölge
 * düzenleyicide yayınlanınca metinler blokların kendisindedir; bu yalnızca hiç düzenlenmemiş bölge için geçerli.
 */
final class ZoneDefaults
{
    /** @return list<array> ilk bloklar, değiştirilmiş metinler yerinde */
    public static function blocks(string $zone): array
    {
        $blocks = array_values((array) (BlockLibrary::zone($zone)['blocks'] ?? []));
        $over = PageTexts::overrides();
        if (! $over) {
            return $blocks;
        }
        foreach ($blocks as &$block) {
            foreach ((array) ($block['texts'] ?? []) as $path => $key) {
                foreach ($over as $locale => $texts) {
                    if (isset($texts[$key], $block['i18n'][$locale]) && is_array($block['i18n'][$locale])) {
                        self::set($block['i18n'][$locale], (string) $path, (string) $texts[$key]);
                    }
                }
            }
        }
        unset($block);

        return $blocks;
    }

    /** Ziyaretçi sayfası: bölgenin ilk bloklarında bu dilde değiştirilmiş metin var mı (yoksa kabuktaki hali kalır). */
    public static function changed(string $zone, string $locale): bool
    {
        $over = PageTexts::overrides()[$locale] ?? [];
        if (! $over) {
            return false;
        }
        foreach ((array) (BlockLibrary::zone($zone)['blocks'] ?? []) as $block) {
            foreach ((array) ($block['texts'] ?? []) as $key) {
                if (isset($over[$key])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** `title` ya da `checks.0.text` yoluna yazar (yalnızca var olan alan ve satıra). */
    private static function set(array &$values, string $path, string $text): void
    {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            if (array_key_exists($path, $values)) {
                $values[$path] = $text;
            }

            return;
        }
        [$field, $row, $column] = $parts + [1 => '', 2 => ''];
        if (isset($values[$field][(int) $row]) && is_array($values[$field][(int) $row]) && array_key_exists($column, $values[$field][(int) $row])) {
            $values[$field][(int) $row][$column] = $text;
        }
    }
}
