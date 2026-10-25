<?php

namespace App\Modules\Media;

use App\Core\Module;
use App\Core\Settings\SettingDefinition;

/**
 * Görseller: yükleme, ara boyutlar (srcset), çok dilli alt metin.
 * Uçlar: MediaController (/admin/media).
 */
final class MediaModule extends Module
{
    public const WEBP = 'media.webp';

    public function settings(): array
    {
        return [
            new SettingDefinition(self::WEBP, 'bool', true, 'Ara boyutları WebP üret', 'Görseller',
                'JPEG ve PNG yüklemelerinin küçük boyutları WebP olarak kaydedilir (daha küçük dosya). Mevcut görseller etkilenmez.'),
        ];
    }
}
