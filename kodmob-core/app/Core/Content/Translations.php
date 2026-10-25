<?php

namespace App\Core\Content;

use App\Core\Support\Text;
use Illuminate\Support\Str;

/**
 * Çeviri yardımcıları (done-coklu-dil-raporu.md §4.4).
 *
 * "Eskimiş çeviri": bir çeviri kaydedilirken kaynak dilin (varsayılan dil; kayıtta yoksa ilk
 * eklenen dil) metinlerinin özeti çeviriyle saklanır. Kaynak sonradan değişirse özet tutmaz ve
 * çeviri "Güncellenmeli" görünür; çeviri kaydedilince ya da "Güncel say" denince özet yenilenir.
 */
final class Translations
{
    /** Özetlenen ve çevrilen alan türleri (adres/slug, sayı, görsel hariç). */
    public const TEXT_TYPES = ['text', 'textarea', 'html', 'list', 'rows'];

    /** Metinlerin kısa özeti (karşılaştırma için; içerik saklamaz). */
    public static function hash(array $values): string
    {
        return substr(md5(Text::json($values)), 0, 12);
    }

    /** Değerde yazılmış bir metin var mı (iç içe diziler dahil). */
    public static function filled(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::filled($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) ? trim($value) !== '' : ($value !== null && $value !== false && $value !== 0);
    }

    /** Alan tanımlarından metin alanlarının değerleri (özet ve çeviri için). */
    public static function texts(array $fields, array $values): array
    {
        $out = [];
        foreach ($fields as $name => $field) {
            if (in_array($field['type'] ?? '', self::TEXT_TYPES, true)) {
                $out[$name] = $values[$name] ?? '';
            }
        }

        return $out;
    }

    /**
     * HTML'deki bağlantı adreslerini değiştirir (`href` değeri tam eşleşirse; kırık bağlantı
     * denetleyicisinin "yönlenen bağlantıları güncelle" işlemi).
     *
     * @param  array<string,string>  $map  eski href → yeni href
     */
    public static function swapLinks(string $html, array $map): string
    {
        if ($map === [] || ! str_contains($html, 'href')) {
            return $html;
        }

        return (string) preg_replace_callback('#(href\s*=\s*)(["\'])(.*?)\2#i', static function (array $m) use ($map) {
            $href = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);

            return isset($map[$href]) ? $m[1].$m[2].htmlspecialchars($map[$href], ENT_QUOTES).$m[2] : $m[0];
        }, $html);
    }

    /** Yeni çeviri grubu kimliği (blog). */
    public static function newGroup(): string
    {
        return (string) Str::uuid();
    }
}
