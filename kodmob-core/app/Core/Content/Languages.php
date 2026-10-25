<?php

namespace App\Core\Content;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Dil kaydı (`languages` tablosu) — sitenin dilleri için tek kaynak. Yönetimi
 * Modules\I18n\LanguageService yapar; burada yalnızca okuma ve biçim vardır.
 *
 * Alanlar: code (değişmez anahtar: tr, en, pt-br) · name (paneldeki Türkçe adı:
 * İngilizce) · native (dil seçicideki kendi adı: English) · short (EN) · prefix
 * (adres öneki; yalnızca varsayılan dil boş olabilir) · hreflang · og · intl ·
 * dir (ltr/rtl) · fallback (eksik metin için yedek dil) · flag · active (sitede
 * yayında) · default · position.
 *
 * Tablo yoksa (kurulum öncesi) ya da boşsa FALLBACK geçerlidir; kayıt istek boyunca
 * bellekte tutulur. Laravel'siz katman (render.php) derlenmiş kopyayı okur
 * (Modules\I18n\I18nConfig).
 */
final class Languages
{
    /** Kurulum öncesi ve tohum: bugünkü üç dil. */
    public const FALLBACK = [
        ['code' => 'tr', 'name' => 'Türkçe', 'native' => 'Türkçe', 'short' => 'TR', 'prefix' => '', 'hreflang' => 'tr', 'og' => 'tr_TR', 'intl' => 'tr-TR', 'dir' => 'ltr', 'fallback' => null, 'flag' => '/assets/img/flags/tr.svg', 'active' => true, 'default' => true],
        ['code' => 'en', 'name' => 'İngilizce', 'native' => 'English', 'short' => 'EN', 'prefix' => 'en', 'hreflang' => 'en', 'og' => 'en_GB', 'intl' => 'en-GB', 'dir' => 'ltr', 'fallback' => null, 'flag' => '/assets/img/flags/en.svg', 'active' => true, 'default' => false],
        ['code' => 'de', 'name' => 'Almanca', 'native' => 'Deutsch', 'short' => 'DE', 'prefix' => 'de', 'hreflang' => 'de', 'og' => 'de_DE', 'intl' => 'de-DE', 'dir' => 'ltr', 'fallback' => null, 'flag' => '/assets/img/flags/de.svg', 'active' => true, 'default' => false],
    ];

    /** @var list<array>|null */
    private static ?array $cache = null;

    /** @return list<array> sıraya göre bütün kayıtlı diller (pasifler dahil) */
    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                $rows = DB::table('languages')->orderBy('position')->orderBy('code')->get()->map(static fn ($r) => self::fromRow((array) $r))->all();
            } catch (QueryException) {
                $rows = [];
            }
            self::$cache = $rows ?: array_map(static fn ($l, $i) => $l + ['position' => $i], self::FALLBACK, array_keys(self::FALLBACK));
        }

        return self::$cache;
    }

    public static function find(string $code): ?array
    {
        foreach (self::all() as $language) {
            if ($language['code'] === $code) {
                return $language;
            }
        }

        return null;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Veritabanı satırı → dil. */
    public static function fromRow(array $row): array
    {
        return [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'native' => (string) $row['native'],
            'short' => (string) $row['short'],
            'prefix' => (string) $row['prefix'],
            'hreflang' => (string) $row['hreflang'],
            'og' => (string) $row['og'],
            'intl' => (string) $row['intl'],
            'dir' => (string) $row['dir'] === 'rtl' ? 'rtl' : 'ltr',
            'fallback' => ($row['fallback'] ?? null) ?: null,
            'flag' => (string) $row['flag'],
            'active' => (bool) $row['active'],
            'default' => (bool) ($row['is_default'] ?? $row['default'] ?? false),
            'position' => (int) ($row['position'] ?? 0),
        ];
    }

    /** Dil → veritabanı satırı (zaman damgaları hariç). */
    public static function toRow(array $language): array
    {
        return [
            'code' => $language['code'],
            'name' => $language['name'],
            'native' => $language['native'],
            'short' => $language['short'],
            'prefix' => $language['prefix'],
            'hreflang' => $language['hreflang'],
            'og' => $language['og'],
            'intl' => $language['intl'],
            'dir' => $language['dir'],
            'fallback' => $language['fallback'],
            'flag' => $language['flag'],
            'active' => (bool) $language['active'],
            'is_default' => (bool) $language['default'],
        ] + (isset($language['position']) ? ['position' => (int) $language['position']] : []);
    }
}
