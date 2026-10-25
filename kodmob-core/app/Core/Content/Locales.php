<?php

namespace App\Core\Content;

use App\Core\Settings\SettingsService;

/**
 * Site dilleri — dil kaydından (Languages, `languages` tablosu) okunur; koda gömülü liste yok.
 *
 * - all(): kayıtlı bütün diller (pasif olanlar dahil). İçerik alanları, görsel alt metinleri
 *   ve SEO bilgileri bu dillerde saklanır: yeni dil önce panelde çevrilir, sonra yayına alınır.
 * - active(): sitede yayında olan diller (site haritası, llms.txt, SEO listeleri).
 * - default(): varsayılan dil; geçersiz dil buna düşer.
 */
final class Locales
{
    /** @return list<string> */
    public static function all(): array
    {
        return array_column(Languages::all(), 'code');
    }

    /** @return list<string> yayındaki diller; tek dilli modda yalnızca varsayılan dil */
    public static function active(): array
    {
        if (! self::multilingual()) {
            return [self::default()];
        }

        return array_values(array_column(array_filter(Languages::all(), static fn ($l) => $l['active']), 'code'));
    }

    /** Çoklu dil açık mı (Çoklu Dil > ana anahtar, `i18n.enabled`). Okunamazsa (kurulum öncesi) açık. */
    public static function multilingual(): bool
    {
        try {
            return SettingsService::instance()->bool('i18n.enabled');
        } catch (\Throwable) {
            return true;
        }
    }

    public static function default(): string
    {
        foreach (Languages::all() as $language) {
            if ($language['default']) {
                return $language['code'];
            }
        }

        return Languages::FALLBACK[0]['code'];
    }

    /** @return array<string,string> kod → paneldeki adı (Türkçe, İngilizce…) */
    public static function labels(): array
    {
        return array_column(Languages::all(), 'name', 'code');
    }

    public static function valid(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::all(), true);
    }

    /** Geçersizse varsayılan dil. */
    public static function normalize(?string $locale): string
    {
        return self::valid($locale) ? (string) $locale : self::default();
    }
}
