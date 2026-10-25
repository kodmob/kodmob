<?php

namespace App\Modules\I18n;

use App\Core\Content\Locales;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsService;

/**
 * Çoklu dil ayarları (Ayarlar'da değil, Çoklu Dil sayfasında; grup yetkisi `km_manage_languages`):
 *
 * - `i18n.enabled`: ana anahtar. Kapalıyken yalnızca varsayılan dil yayında (tek dilli mod):
 *   diğer dillerin adresleri varsayılan dile 302, dil seçici, hreflang ve öneri bandı yok.
 *   İçerik ve diller silinmez; açınca geri gelir.
 * - `i18n.banner`: dil önerisi bandı (tarayıcı dili sayfanın dilinden farklıysa).
 */
final class I18nSettings
{
    public const GROUP = 'Çoklu dil';

    public const ENABLED = 'i18n.enabled';

    public const BANNER = 'i18n.banner';

    /** @return list<SettingDefinition> */
    public static function definitions(): array
    {
        return [
            new SettingDefinition(self::ENABLED, 'bool', true, 'Çoklu dil', self::GROUP, 'Kapalıyken yalnızca varsayılan dil yayında; diğer dillerin adresleri varsayılan dile yönlenir. İçerik silinmez.'),
            new SettingDefinition(self::BANNER, 'bool', true, 'Dil önerisi bandı', self::GROUP, 'Ziyaretçinin tarayıcı dili sayfanın dilinden farklıysa ve sayfanın o dilde karşılığı varsa alt köşede öneri çıkar.'),
        ];
    }

    public static function enabled(): bool
    {
        return Locales::multilingual();
    }

    public static function banner(): bool
    {
        return self::enabled() && self::read(self::BANNER);
    }

    /** Ayar okunamazsa (kurulum öncesi) açık sayılır. */
    private static function read(string $key): bool
    {
        try {
            return SettingsService::instance()->bool($key);
        } catch (\Throwable) {
            return true;
        }
    }
}
