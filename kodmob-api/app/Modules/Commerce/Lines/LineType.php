<?php

namespace App\Modules\Commerce\Lines;

use App\Core\Exceptions\ValidationException;
use App\Modules\Site\Render\Router;

/**
 * Sepet ve sipariş satırının türü. Mağaza ürün satırını getirir (ProductLine); Mağaza'ya takılı modüller kendi
 * satırlarını `CartLines::register()` ile ekler (Rezervasyon: tarih, kişi sayıları, kapasite). Sepet, hesap,
 * ödeme, e-posta ve sipariş akışı her türde aynıdır; tür yalnızca satırın ne olduğunu ve neyi ayırdığını bilir.
 *
 * Satır: `['key' => 'product:12', 'type' => 'product', 'id' => 12, 'quantity' => 2, 'data' => [...]]`.
 */
interface LineType
{
    /** Türün anahtarı (satırda ve sipariş kaleminde saklanır). */
    public function key(): string;

    /**
     * Sepete eklenirken: satırı doğrular ve normalleştirir (satışta mı, stok / kapasite yetiyor mu).
     *
     * @param  array<string,mixed>  $data  ziyaretçinin gönderdiği ek bilgi
     * @return array{quantity:int,data:array<string,mixed>}
     *
     * @throws ValidationException
     */
    public function prepare(int $id, int $quantity, array $data, string $locale): array;

    /**
     * Satırın o anki hali (sepette her gösterilişte, siparişte bir kez); satıştan kalktıysa null.
     *
     * @param  array<string,mixed>  $line
     * @return array{title:string,sku:string,price:int,tax_rate:int,quantity:int,digital:bool,url:string,image:string,available:bool}|null
     */
    public function resolve(array $line, string $locale, ?Router $router): ?array;

    /**
     * Sipariş verilirken (işlem içinde): stok / kapasite kilitlenip ayrılır. Yetmiyorsa hata; tükendiyse true
     * (sayfa önbelleği temizlenir).
     *
     * @param  array<string,mixed>  $line
     */
    public function take(array $line, string $title, int $orderId, int $itemId, string $locale): bool;

    /** İptal / iade: kalemin ayırdığı stok / kapasite geri (bir kez çağrılır). */
    public function release(object $item): void;
}
