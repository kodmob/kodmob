<?php

namespace App\Core\Database;

/**
 * Modülün yedeğe giren tabloları (`Module::backup()`; Modules\Content\Backup). Satırlar kimlikleriyle taşınır
 * (sipariş numarası gibi kimlikten türeyen değerler aynı kalır); geri yüklemede tablolar yedektekiyle değişir.
 *
 * Kişisel veri taşıyan parça (`personal`: siparişler, talepler, aboneler) indirilen yedeğe yalnızca istenince girer,
 * geri yüklemede ayrıca seçilir (yoksa mevcut kayıtlar kalır); uzak depoya giden yedek her zaman şifrelidir.
 */
final class BackupPart
{
    /**
     * @param  list<string>  $tables  yazılış sırasıyla
     * @param  array<string,list<string>>  $contentLinks  tablo → içerik kaydına bağlanan sütunlar (ör. siparişteki
     *                                                    ürün): içerik geri yüklemede yeni kimlik alır, bağ ona taşınır
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $tables,
        public readonly bool $personal = false,
        public readonly array $contentLinks = [],
    ) {}
}
