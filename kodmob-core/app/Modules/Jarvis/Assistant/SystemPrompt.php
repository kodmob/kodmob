<?php

namespace App\Modules\Jarvis\Assistant;

use App\Core\Support\SiteManifest;
use App\Modules\Jarvis\Skills\SkillRegistry;

/**
 * Jarvis'in sistem istemi. Değişmeyen kısım önbelleklenir (araç listesiyle
 * birlikte istem önbelleğinin öneki); tarih ayrı blokta, önbellek noktasından
 * sonra durur.
 */
final class SystemPrompt
{
    private const STATIC = <<<'TXT'

Dil ve üslup:
- Türkçe yanıt ver; kısa, net ve iş odaklı ol. Gereksiz giriş cümlesi kurma.
- Listeleri ve alan adlarını okunaklı biçimde ver; alan adlarını `ab.si` biçiminde yaz.

Araçların (açık modüllere göre; her birinin açıklaması araç tanımında):
- web_search (açıksa): güncel bilgi gerektiğinde.

Gizlilik: talep sahiplerinin kişisel verilerini isteme; gerekiyorsa panelde Talepler ekranına yönlendir.
TXT;

    /** Kim olduğu: sitenin adı ve tanımı derlemedeki site profilinden (kodmob-frontend/src/profiles/<ad>/site.config.js, sözlükler). */
    private static function identity(): string
    {
        $about = trim(SiteManifest::string('description'));

        return "Sen Jarvis'sin: ".SiteManifest::siteName().' yönetim panelindeki asistansın.'.($about !== '' ? ' Site hakkında: '.$about : '').' Seninle konuşan kişi panelin yetkili bir kullanıcısı.';
    }

    /** @return list<array> SDK biçiminde sistem blokları */
    public static function blocks(): array
    {
        return [
            // Modüllerin kuralları (SkillRegistry::guide) kayıt sırasıyla sabit: önbellek öneki bozulmaz.
            ['type' => 'text', 'text' => trim(self::identity()."\n\n".self::STATIC."\n\n".SkillRegistry::guidance()), 'cacheControl' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'Bugünün tarihi: '.date('j F Y, l').' (saat dilimi: '.config('app.timezone').').'],
        ];
    }
}
