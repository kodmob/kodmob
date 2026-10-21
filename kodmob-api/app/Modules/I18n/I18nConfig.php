<?php

namespace App\Modules\I18n;

use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Support\Files;
use Illuminate\Support\Facades\Log;

/**
 * Dil kaydının Laravel'siz katman (public/render.php) için derlenmiş kopyası:
 * storage/framework/i18n/config.json. Dil değişince, kurulumda ve yedekten geri
 * yüklemede yeniden yazılır (I18nModule); yazım atomiktir.
 *
 *   { default: "tr", enabled, languages: [{ code, prefix, active, default, hreflang, og, intl, dir, fallback, native, short, flag }], compiled }
 *
 * Tek dilli modda (`enabled: false`) yalnızca varsayılan dil `active` yazılır: Router diğer dilleri
 * pasif dil gibi varsayılan dile yönlendirir.
 */
final class I18nConfig
{
    public static function file(): string
    {
        return storage_path('framework/i18n/config.json');
    }

    public static function data(): array
    {
        $enabled = Locales::multilingual();

        return [
            'default' => Locales::default(),
            'enabled' => $enabled,
            'languages' => array_map(static fn (array $l) => ['active' => $enabled ? $l['active'] : $l['default']] + array_intersect_key($l, array_flip([
                'code', 'prefix', 'default', 'hreflang', 'og', 'intl', 'dir', 'fallback', 'native', 'short', 'flag',
            ])), Languages::all()),
            'compiled' => now()->toAtomString(),
        ];
    }

    /** Kayıt önce sitenin derlemesiyle eşitlenir (önek, varsayılan dil, yeni derleme dilleri). */
    public static function compile(): bool
    {
        try {
            Languages::forget();
            LanguageService::syncWithBuild();

            return Files::putJson(self::file(), self::data());
        } catch (\Throwable $e) {
            Log::warning('[Kodmob dil] yapılandırma yazılamadı: '.$e->getMessage());

            return false;
        }
    }
}
