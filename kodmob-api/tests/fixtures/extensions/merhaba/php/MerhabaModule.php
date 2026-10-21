<?php

namespace KodmobTools\Merhaba;

use App\Core\Module;
use App\Core\Settings\SettingDefinition;

/**
 * Merhaba aracının modülü: yetkileri ve ayarları (Core\Module kancaları; events(), boot(), schedule(),
 * health() de kullanılabilir). Araç Pazarı'nda kapatılınca hiçbiri yüklenmez, uçları 404 döner.
 */
final class MerhabaModule extends Module
{
    public const USE = 'km_use_merhaba';

    public function capabilities(): array
    {
        return [self::USE => ['label' => 'Merhaba aracını kullanma', 'group' => 'Araçlar']];
    }

    public function settings(): array
    {
        return [
            // Proje Ayarları'nda kendiliğinden görünür (kapsam: proje).
            new SettingDefinition('merhaba.greeting', 'string', 'Merhaba!', 'Karşılama metni', 'Merhaba', 'Aracın sayfasında görünen metin.', scope: 'project'),
        ];
    }
}
