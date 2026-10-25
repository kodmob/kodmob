<?php

namespace App\Core\Content;

use App\Core\Support\HtmlSanitizer;
use App\Core\Support\Text;
use App\Modules\Media\MediaLibrary;

/**
 * İçerik alanlarının türleri: temizleme (panelden gelen veri), panel için
 * görsel genişletme ve şema çıktısı. İçerik türleri (projeler, hizmetler,
 * referanslar, blog) alanlarını bu türlerle tanımlar; panel formu aynı
 * şemadan çizilir (`GET /admin/schema/<tür>`).
 *
 * Alan tanımı:
 *   [
 *     'type'     => text | textarea | url | slug | bool | int | money | select | multiselect
 *                   | icon | image | gallery | list | rows | html | date | post,
 *                   (money: kuruş cinsinden tam sayı, panelde ondalıklı; `unit` para birimi)
 *     'label'    => 'Başlık',
 *     'help'     => 'Alanın altında görünen açıklama',
 *     'required' => true,           // boşsa kayıt reddedilir
 *     'max'      => 200,            // metin uzunluğu / liste satırı sayısı
 *     'options'  => ['web' => 'Web'], // select, multiselect
 *     'columns'  => ['title' => ['type' => 'text', 'label' => 'Başlık']], // rows
 *     'from'     => 'title',        // slug: boşsa bu alandan üretilir
 *     'postType' => 'km_project',   // post: seçilebilecek kayıtlar
 *     'width'    => 'half',         // panel yerleşimi: full | half | third
 *     'section'  => 'Detay',        // panel bölümü
 *   ]
 */
final class Fields
{
    /** Kart ve özellik ikonları (kodmob-frontend/src/shell/icons.html'deki semboller). */
    public const ICONS = ['leaf', 'grid', 'calendar', 'sparkles', 'cart', 'map', 'monitor', 'smartphone', 'globe', 'code', 'briefcase', 'rocket', 'users', 'map-pin', 'bar-chart', 'lock', 'lightbulb', 'target', 'compass', 'layers', 'zap', 'image', 'check'];

    private const TEXT_MAX = 200;

    private const TEXTAREA_MAX = 5000;

    private const LIST_MAX = 50;

    private const GALLERY_MAX = 30;

    /** Panelden gelen değeri türüne göre temizler. */
    public static function clean(array $field, mixed $value): mixed
    {
        $max = $field['max'] ?? null;
        switch ($field['type']) {
            case 'text':
                return self::cut(self::plain((string) $value, false), $max ?? self::TEXT_MAX);
            case 'textarea':
                return self::cut(self::plain((string) $value, true), $max ?? self::TEXTAREA_MAX);
            case 'html':
                return HtmlSanitizer::clean((string) $value);
            case 'url':
                $url = Text::url($value);

                return str_starts_with($url, '/') ? '' : $url;
            case 'slug':
                return self::cut(Text::slug((string) $value), 120);
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'int':
                return max((int) ($field['min'] ?? PHP_INT_MIN), (int) $value);
            case 'money':
                return self::money($value);
            case 'date':
                $time = strtotime((string) $value);

                return $time ? date('Y-m-d\TH:i', $time) : '';
            case 'select':
                $value = (string) $value;
                // Seçenek listesi boşsa (sözlüğü derlemeden gelen alan, derleme eski ya da yok) değer korunur.
                if (empty($field['options'])) {
                    return Text::slug($value);
                }

                return array_key_exists($value, $field['options']) ? $value : (string) array_key_first($field['options']);
            case 'multiselect':
                if (empty($field['options'])) {
                    return array_values(array_filter(array_map(static fn ($v) => Text::slug((string) $v), (array) $value)));
                }

                return array_values(array_intersect(array_map('strval', (array) $value), array_map('strval', array_keys($field['options']))));
            case 'icon':
                return in_array($value, self::ICONS, true) ? $value : ($field['default'] ?? 'layers');
            case 'image':
                return self::imageId($value);
            case 'post':
                $id = abs((int) (is_array($value) ? ($value['id'] ?? 0) : $value));

                return $id && ContentRepository::exists($id, (string) ($field['postType'] ?? '')) ? $id : 0;
            case 'gallery':
                $ids = (array) $value;
                MediaLibrary::prime(array_map(static fn ($v) => (int) (is_array($v) ? ($v['id'] ?? 0) : $v), $ids));
                $ids = array_values(array_filter(array_map([self::class, 'imageId'], $ids)));

                return array_slice(array_values(array_unique($ids)), 0, $max ?? self::GALLERY_MAX);
            case 'list':
                $items = array_map(static fn ($item) => self::cut(self::plain(is_scalar($item) ? (string) $item : '', false), self::TEXT_MAX), (array) $value);

                return array_slice(array_values(array_filter($items, static fn ($item) => $item !== '')), 0, $max ?? self::LIST_MAX);
            case 'rows':
                $rows = [];
                foreach ((array) $value as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $clean = [];
                    $filled = false;
                    foreach ($field['columns'] as $name => $column) {
                        $clean[$name] = self::clean($column, $row[$name] ?? '');
                        // Boş satır: yazı sütunlarının hepsi boş, sayı sütunlarının hepsi 0 (ikon her zaman dolu olur).
                        if ((in_array($column['type'], ['text', 'textarea', 'url'], true) && $clean[$name] !== '')
                            || (in_array($column['type'], ['int', 'money'], true) && $clean[$name] !== 0)) {
                            $filled = true;
                        }
                    }
                    if ($filled) {
                        $rows[] = $clean;
                    }
                }

                return array_slice($rows, 0, $max ?? self::LIST_MAX);
        }

        return '';
    }

    /**
     * Para: en küçük birimde (kuruş) negatif olmayan tam sayı. Panel tam sayı gönderir; "1.234,56", "1234.56"
     * gibi metin de anlaşılır (son ayırıcının ardında 1–2 hane varsa ondalıktır).
     */
    private static function money(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        $text = preg_replace('/[^\d.,]/', '', (string) $value) ?? '';
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $text, $m)) {
            return max(0, (int) preg_replace('/\D/', '', $m[1]) * 100 + (int) str_pad($m[2], 2, '0'));
        }

        return max(0, (int) preg_replace('/\D/', '', $text) * 100);
    }

    /** Alanın boş değeri (yeni kayıt ve eksik veri için). */
    public static function blank(array $field): mixed
    {
        return match ($field['type']) {
            'bool' => false,
            'int', 'money', 'image', 'post' => 0,
            'multiselect', 'gallery', 'list', 'rows' => [],
            'icon' => $field['default'] ?? 'layers',
            default => '',
        };
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === '' || $value === 0 || $value === [] || $value === null;
    }

    /** Panel için: görsel kimliklerini önizleme ve dillerin alt metniyle genişletir. */
    public static function expand(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'image' => $value ? MediaPayload::admin((int) $value) : null,
            'gallery' => array_values(array_filter(array_map(static fn ($id) => MediaPayload::admin((int) $id), (array) $value))),
            default => $value,
        };
    }

    /** Ziyaretçi sayfası için: görseller srcset'le, alt metin sayfanın dilinde. */
    public static function present(array $field, mixed $value, ?string $locale = null): mixed
    {
        $locale ??= Locales::default();

        return match ($field['type']) {
            'image' => $value ? MediaPayload::image((int) $value, 'large', $locale) : null,
            'gallery' => array_values(array_filter(array_map(static fn ($id) => MediaPayload::image((int) $id, 'large', $locale), (array) $value))),
            default => $value,
        };
    }

    /**
     * Varsayılan dilde alt metni olmayan görseller: alan adı → hata (galeride sıra no).
     *
     * @param  array<string,array>  $fields
     * @return array<string,string>
     */
    public static function missingAlts(array $fields, array $values): array
    {
        $errors = [];
        foreach ($fields as $name => $field) {
            $value = $values[$name] ?? null;
            if ($field['type'] === 'image' && $value && ! MediaPayload::hasAlt((int) $value)) {
                $errors[$name] = sprintf('Görselin %s alt metni eksik. Görsele tıklayıp alt metnini yazın.', Locales::labels()[Locales::default()] ?? Locales::default());
            }
            if ($field['type'] === 'gallery') {
                $missing = [];
                foreach (array_values((array) $value) as $i => $id) {
                    if (! MediaPayload::hasAlt((int) $id)) {
                        $missing[] = $i + 1;
                    }
                }
                if ($missing) {
                    $errors[$name] = 'Alt metni eksik görseller: '.implode(', ', $missing).'. sıradaki.';
                }
            }
        }

        return $errors;
    }

    /** HTML içindeki alt metni boş <img> sayısı (blog gövdesi). */
    public static function imagesWithoutAlt(string $html): int
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);
        $missing = 0;
        foreach ($tags[0] as $tag) {
            $alt = preg_match('/\balt\s*=\s*"([^"]*)"/i', $tag, $m) ? $m[1] : (preg_match("/\balt\s*=\s*'([^']*)'/i", $tag, $m) ? $m[1] : '');
            if (trim(html_entity_decode($alt)) === '') {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * Panel formunun şeması: türü, etiketi ve yerleşimi; seçenekler liste olarak.
     *
     * @param  array<string,array>  $fields
     * @return list<array>
     */
    public static function schema(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $field) {
            $item = ['name' => $name] + $field;
            if (isset($field['options'])) {
                $item['options'] = array_map(static fn ($key, $label) => ['value' => (string) $key, 'label' => $label], array_keys($field['options']), $field['options']);
            }
            if (isset($field['columns'])) {
                $item['columns'] = self::schema($field['columns']);
            }
            if ($field['type'] === 'icon') {
                $item['options'] = array_map(static fn ($icon) => ['value' => $icon, 'label' => $icon], self::ICONS);
            }
            $out[] = $item;
        }

        return $out;
    }

    /** Görsel kimliği: sayı ya da `{ id }`; kitaplıkta yoksa 0. */
    public static function imageId(mixed $value): int
    {
        $id = abs((int) (is_array($value) ? ($value['id'] ?? 0) : $value));

        return $id && MediaLibrary::find($id) ? $id : 0;
    }

    /**
     * Düz metin: etiketler ve denetim karakterleri atılır, boşluklar sadeleşir
     * (`$multiline`: satır sonları korunur).
     */
    public static function plain(string $value, bool $multiline): string
    {
        return $multiline ? Text::textarea($value) : Text::field($value);
    }

    private static function cut(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}
