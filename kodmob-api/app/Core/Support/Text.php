<?php

namespace App\Core\Support;

use Illuminate\Support\Str;

/**
 * Metin temizleme yardımcıları (WordPress'teki sanitize_* / esc_url_raw
 * işlevlerinin karşılıkları). Panelden ve ziyaretçiden gelen her değer bu
 * işlevlerden ya da Content\Fields'tan geçer.
 */
final class Text
{
    /** Tek satır metin: etiketler, denetim karakterleri ve fazla boşluk atılır. */
    public static function field(mixed $value): string
    {
        return self::clean((string) $value, false);
    }

    /** Çok satırlı metin: satır sonları korunur. */
    public static function textarea(mixed $value): string
    {
        return self::clean((string) $value, true);
    }

    /** Anahtar: küçük harf, yalnızca a-z 0-9 _ -. */
    public static function key(mixed $value): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?? '';
    }

    /** Adres parçası (slug): Türkçe harfler sadeleşir, boşluklar tireye döner. */
    public static function slug(mixed $value): string
    {
        $value = self::stripTags((string) $value);

        return Str::slug($value, '-', 'tr');
    }

    /**
     * http(s) adresi. Şemasız yazılmışsa (kodmob.com) `http://` eklenir; izin
     * verilmeyen şema (javascript:, data:) boş döner. Kök-göreli adresler kalır.
     *
     * @param  list<string>  $schemes
     */
    public static function url(mixed $value, array $schemes = ['http', 'https']): string
    {
        $url = trim(preg_replace('/[\x00-\x20\x7F]/', '', (string) $value) ?? '');
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (! preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            $url = 'http://'.$url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, $schemes, true) || ! parse_url($url, PHP_URL_HOST)) {
            return '';
        }

        return $url;
    }

    public static function isEmail(mixed $value): bool
    {
        return filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function email(mixed $value): string
    {
        $email = trim((string) $value);

        return self::isEmail($email) ? mb_strtolower($email) : '';
    }

    /** Etiketler (script/style içerikleriyle) atılır. */
    public static function stripTags(string $html, bool $removeBreaks = false): string
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $html) ?? '';
        $text = strip_tags($text);
        if ($removeBreaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text) ?? '';
        }

        return trim($text);
    }

    /** Güvenli dosya adı: ASCII, küçük harf, tireli; uzantı korunur. */
    public static function fileName(string $name): string
    {
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo($name, PATHINFO_EXTENSION)) ?? '');
        $base = Str::slug(pathinfo($name, PATHINFO_FILENAME), '-', 'tr') ?: 'dosya';

        return mb_substr($base, 0, 120).($ext !== '' ? '.'.$ext : '');
    }

    /** İlk `$count` kelime. */
    public static function words(string $text, int $count, string $more = '…'): string
    {
        $words = preg_split('/[\s]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) <= $count) {
            return implode(' ', $words);
        }

        return implode(' ', array_slice($words, 0, $count)).$more;
    }

    /** Binlik ayraçlı sayı (Türkçe): 12.500 */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    /** JSON (Türkçe karakterler ve eğik çizgiler kaçırılmadan). */
    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function clean(string $value, bool $multiline): string
    {
        $value = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        if (str_contains($value, '<')) {
            $value = self::stripTags($value);
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $value) ?? '';
        if (! $multiline) {
            return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        }
        $lines = array_map(static fn ($line) => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? ''), explode("\n", $value));

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '');
    }
}
