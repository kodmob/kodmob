<?php

namespace App\Modules\Commerce\Lines;

use App\Core\Content\ContentRepository;
use App\Core\Content\Record;
use App\Core\Exceptions\ValidationException;
use App\Modules\Commerce\CommerceText;
use App\Modules\Commerce\ProductType;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;

/** Mağaza'nın ürün satırı: adet, stok takibi (isteğe bağlı), dijital ürün. */
final class ProductLine implements LineType
{
    public function key(): string
    {
        return 'product';
    }

    public function prepare(int $id, int $quantity, array $data, string $locale): array
    {
        $record = self::product($id);
        if ($record === null) {
            throw ValidationException::field('product', 'Ürün bulunamadı.');
        }
        if (! ProductType::available($record->data, $quantity)) {
            throw ValidationException::field('quantity', CommerceText::get('error.stock', $locale, ['title' => ProductType::titleOf($record->data, $locale)]));
        }

        return ['quantity' => $quantity, 'data' => []];
    }

    public function resolve(array $line, string $locale, ?Router $router): ?array
    {
        $record = self::product((int) $line['id']);
        $item = $record ? (new ProductType)->localize($record, $locale) : null;
        if ($item === null) {
            return null;
        }

        return [
            'title' => (string) $item['title'], 'sku' => (string) $item['sku'], 'price' => (int) $item['price'], 'tax_rate' => (int) $item['taxRate'],
            'quantity' => (int) $line['quantity'], 'digital' => (bool) $item['digital'],
            'url' => $item['slug'] !== '' ? (string) ($router?->url('product', $locale, $item['slug']) ?? '') : '',
            'image' => $item['cover']['src'] ?? '', 'available' => ProductType::available($record->data, (int) $line['quantity']),
        ];
    }

    public function take(array $line, string $title, int $orderId, int $itemId, string $locale): bool
    {
        $row = DB::table('contents')->where('id', $line['id'])->where('type', ProductType::POST_TYPE)->whereNull('deleted_at')->lockForUpdate()->first();
        if ($row === null || $row->status !== 'publish') {
            throw ValidationException::field('cart', CommerceText::get('error.unavailable', $locale, ['title' => $title]));
        }
        $data = (array) json_decode((string) $row->data, true);
        if (empty($data['track_stock'])) {
            return false;
        }
        $stock = (int) ($data['stock'] ?? 0);
        if ($stock < $line['quantity']) {
            throw ValidationException::field('cart', CommerceText::get('error.stock', $locale, ['title' => $title]));
        }
        $data['stock'] = $stock - $line['quantity'];
        ContentRepository::update((int) $row->id, ProductType::POST_TYPE, ['data' => $data]);

        return $data['stock'] === 0;
    }

    public function release(object $item): void
    {
        $product = $item->product_id ? DB::table('contents')->where('id', $item->product_id)->where('type', ProductType::POST_TYPE)->lockForUpdate()->first() : null;
        $data = $product ? (array) json_decode((string) $product->data, true) : [];
        if ($product && ! empty($data['track_stock'])) {
            $data['stock'] = (int) ($data['stock'] ?? 0) + (int) $item->quantity;
            ContentRepository::update((int) $product->id, ProductType::POST_TYPE, ['data' => $data]);
        }
    }

    /** Satıştaki ürün (yayında). */
    public static function product(int $id): ?Record
    {
        $record = ContentRepository::find($id, ProductType::POST_TYPE);

        return $record !== null && $record->status === 'publish' ? $record : null;
    }
}
