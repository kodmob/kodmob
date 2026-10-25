<?php

namespace App\Modules\Site\Blocks;

use App\Core\Content\Fields;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\HtmlSanitizer;

/**
 * Bölgedeki blokların panelden gelen verisi: blok tanımının alanlarına göre temizlenir (bilinmeyen alan atılır,
 * metin düz yazıya, HTML HtmlSanitizer'dan, bağlantı izinli biçimlere). Blok örneği:
 *
 *   { id: 'k3f9a2', type: 'marquee', hidden: false, data: { ortak alanlar }, i18n: { tr: { dil alanları }, en: {…} } }
 */
final class BlockData
{
    /** Bölgede en çok blok. */
    public const MAX_BLOCKS = 40;

    /** Bölgenin temizlenmiş verisinin en büyük boyutu (bayt). */
    public const MAX_BYTES = 300_000;

    private const ID = '/^[a-z0-9]{1,16}$/';

    /**
     * @param  list<string>  $locales  kayıtlı diller
     * @return list<array<string,mixed>>
     */
    public static function cleanZone(mixed $blocks, array $locales): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw ValidationException::field('blocks', 'Bloklar bir liste olmalı.');
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::field('blocks', 'Bir bölgede en fazla '.self::MAX_BLOCKS.' blok olabilir.');
        }
        $out = [];
        $ids = [];
        foreach ($blocks as $i => $raw) {
            $block = is_array($raw) ? self::clean($raw, $locales, "blocks.{$i}") : null;
            if ($block === null) {
                continue;
            }
            // Yinelenen kimlik (kopyala-yapıştır) yenilenir.
            while (isset($ids[$block['id']])) {
                $block['id'] = self::newId();
            }
            $ids[$block['id']] = true;
            $out[] = $block;
        }
        if (strlen((string) json_encode($out)) > self::MAX_BYTES) {
            throw ValidationException::field('blocks', 'Bölgenin içeriği çok büyük; blokları bölün ya da kısaltın.');
        }

        return $out;
    }

    /** @param list<string> $locales */
    public static function clean(array $raw, array $locales, string $path = 'block'): array
    {
        $type = (string) ($raw['type'] ?? '');
        $block = BlockLibrary::block($type);
        if ($block === null) {
            throw ValidationException::field($path.'.type', "Bilinmeyen blok: {$type}");
        }
        $id = (string) ($raw['id'] ?? '');
        $out = [
            'id' => preg_match(self::ID, $id) ? $id : self::newId(),
            'type' => $type,
            'hidden' => filter_var($raw['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'data' => [],
            'i18n' => [],
        ];
        $data = (array) ($raw['data'] ?? []);
        $i18n = (array) ($raw['i18n'] ?? []);
        foreach ((array) ($block['fields'] ?? []) as $field) {
            $name = (string) $field['name'];
            if (empty($field['localized'])) {
                // Verilmeyen alan yazılmaz: çizimde bloğun varsayılanı kullanılır.
                if (array_key_exists($name, $data)) {
                    $out['data'][$name] = self::field($field, $data[$name]);
                }
            } else {
                foreach ($locales as $locale) {
                    if (isset($i18n[$locale]) && is_array($i18n[$locale]) && array_key_exists($name, $i18n[$locale])) {
                        $out['i18n'][$locale][$name] = self::field($field, $i18n[$locale][$name]);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * JSON'a çıkarken boş `data` / `i18n` nesne kalsın (`{}`; dizi `[]` olursa tarayıcıda alan eklenen dizi
     * kaydedilirken kaybolur).
     *
     * @param  list<array<string,mixed>>  $blocks
     * @return list<array<string,mixed>>
     */
    public static function objects(array $blocks): array
    {
        return array_map(static fn (array $b) => ['data' => (object) ($b['data'] ?? []), 'i18n' => (object) array_map(static fn ($v) => (object) $v, (array) ($b['i18n'] ?? []))] + $b, $blocks);
    }

    public static function newId(): string
    {
        return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 3)), 0, 8);
    }

    /** Alanın boş değeri. */
    public static function blank(array $field): mixed
    {
        return match ($field['type']) {
            'bool' => false,
            'int', 'image' => 0,
            'rows' => [],
            default => '',
        };
    }

    /** Tek alan (satırların sütunları da). */
    public static function field(array $field, mixed $value): mixed
    {
        $max = isset($field['max']) ? (int) $field['max'] : null;
        switch ($field['type']) {
            case 'text':
                return Fields::clean(['type' => 'text', 'max' => $max ?? 200], is_scalar($value) ? (string) $value : '');
            case 'textarea':
                return Fields::clean(['type' => 'textarea', 'max' => $max ?? 2000], is_scalar($value) ? (string) $value : '');
            case 'html':
                return mb_substr(HtmlSanitizer::clean(is_scalar($value) ? (string) $value : ''), 0, 20_000);
            case 'link':
                return self::link($value);
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'int':
                $int = (int) (is_scalar($value) ? $value : 0);

                return min((int) ($field['max'] ?? PHP_INT_MAX), max((int) ($field['min'] ?? PHP_INT_MIN), $int));
            case 'select':
                $options = array_map('strval', array_keys((array) ($field['options'] ?? [])));
                $value = is_scalar($value) ? (string) $value : '';

                return in_array($value, $options, true) ? $value : (string) ($field['default'] ?? ($options[0] ?? ''));
            case 'icon':
                $icons = BlockLibrary::icons();
                $value = is_scalar($value) ? (string) $value : '';

                return in_array($value, $icons, true) || (! $icons && preg_match('/^[a-z0-9-]{1,40}$/', $value)) ? $value : (string) ($field['default'] ?? 'check');
            case 'image':
                return Fields::clean(['type' => 'image'], is_array($value) ? (int) ($value['id'] ?? 0) : $value);
            case 'rows':
                $rows = [];
                foreach (is_array($value) ? $value : [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $clean = [];
                    $filled = false;
                    foreach ((array) ($field['columns'] ?? []) as $column) {
                        $clean[$column['name']] = self::field($column, $row[$column['name']] ?? self::blank($column));
                        if (in_array($column['type'], ['text', 'textarea', 'link'], true) && $clean[$column['name']] !== '') {
                            $filled = true;
                        }
                    }
                    if ($filled) {
                        $rows[] = $clean;
                    }
                }

                return array_slice($rows, 0, $max ?? 30);
        }

        return '';
    }

    /**
     * Bağlantı: `route:<rota>` (sitenin sayfası; dile göre çözülür), `/yol`, `#çapa`, `https://…`, `mailto:`, `tel:`.
     * Başka biçim (javascript:, data:…) boşalır.
     */
    public static function link(mixed $value): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        if ($value === '' || mb_strlen($value) > 500 || preg_match('/[\s<>"\'\\\\]/', $value)) {
            return '';
        }
        // Sitenin sayfası (`route:contact`) ya da blok sayfası (`page:12`).
        if (preg_match('/^route:[a-zA-Z][a-zA-Z0-9]*$/', $value) || preg_match('/^page:[1-9][0-9]{0,9}$/', $value)) {
            return $value;
        }
        if (preg_match('#^(/(?!/)|\#|\?)#', $value) || preg_match('#^(https?://|mailto:|tel:)#i', $value)) {
            return $value;
        }

        return '';
    }
}
