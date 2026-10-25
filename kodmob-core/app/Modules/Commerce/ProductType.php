<?php

namespace App\Modules\Commerce;

use App\Core\Content\ContentType;
use App\Core\Content\Locales;
use App\Core\Support\Text;

/**
 * Mağazanın ürünleri (içerik türü `products`, kayıt `product`; CommerceModule::contentTypes). Panelde liste,
 * form, çeviri, yedek, arama ve yetki (`km_manage_products`) diğer türler gibi; sitede kendi sayfası profilin
 * rotasında `dynamic: 'product'` (commerceRoutes).
 *
 * Fiyat kuruş cinsinden (`money`); KDV oranı ürün başına; stok takibi isteğe bağlı (kapalıyken sınırsız).
 * Dijital ürün kargo ve adres istemez.
 */
final class ProductType extends ContentType
{
    public const POST_TYPE = 'km_product';

    public const TAX_RATES = ['20' => '%20', '10' => '%10', '1' => '%1', '0' => '%0 (KDV yok)'];

    public function postType(): string
    {
        return self::POST_TYPE;
    }

    public function labels(): array
    {
        return ['Ürünler', 'Ürün'];
    }

    public function capabilityLabel(): string
    {
        return 'Ürünleri ekleme ve düzenleme';
    }

    public function shared(): array
    {
        $currency = Money::currency();

        return [
            'price' => ['type' => 'money', 'label' => 'Fiyat', 'unit' => $currency, 'required' => true, 'help' => 'Mağaza ayarına göre KDV dahil ya da hariç.', 'section' => 'Fiyat ve stok', 'width' => 'third'],
            'compare_price' => ['type' => 'money', 'label' => 'İndirimsiz fiyat', 'unit' => $currency, 'help' => 'Fiyattan yüksekse sitede üstü çizili görünür.', 'section' => 'Fiyat ve stok', 'width' => 'third'],
            'tax_rate' => ['type' => 'select', 'label' => 'KDV oranı', 'options' => self::TAX_RATES, 'section' => 'Fiyat ve stok', 'width' => 'third'],
            'track_stock' => ['type' => 'bool', 'label' => 'Stok takibi', 'help' => 'Kapalıyken adet sınırı yok.', 'section' => 'Fiyat ve stok', 'width' => 'third'],
            'stock' => ['type' => 'int', 'label' => 'Stok adedi', 'min' => 0, 'help' => 'Sipariş verilince düşer, iptalde geri gelir.', 'section' => 'Fiyat ve stok', 'width' => 'third'],
            'digital' => ['type' => 'bool', 'label' => 'Dijital ürün', 'help' => 'Kargo ve teslimat adresi istenmez.', 'section' => 'Fiyat ve stok', 'width' => 'third'],
            'sku' => ['type' => 'text', 'label' => 'Stok kodu (SKU)', 'max' => 60, 'section' => 'Genel', 'width' => 'half'],
            'category' => ['type' => 'text', 'label' => 'Kategori', 'max' => 60, 'section' => 'Genel', 'width' => 'half'],
            'cover' => ['type' => 'image', 'label' => 'Ana görsel', 'help' => 'Listede ve ürün sayfasında. Kare ya da 4:3 önerilir.', 'section' => 'Görseller'],
            'gallery' => ['type' => 'gallery', 'label' => 'Diğer görseller', 'section' => 'Görseller'],
        ];
    }

    public function localized(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Ürün adı', 'help' => 'Boşsa ürün bu dilde satılmaz.', 'section' => 'İçerik', 'width' => 'half'],
            'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title', 'help' => 'Boşsa addan üretilir.', 'section' => 'İçerik', 'width' => 'half'],
            'summary' => ['type' => 'textarea', 'label' => 'Kısa açıklama', 'max' => 400, 'help' => 'Listede ve ürün sayfasının başında.', 'section' => 'İçerik'],
            'body' => ['type' => 'html', 'label' => 'Açıklama', 'section' => 'İçerik'],
        ];
    }

    /** Ürün adı: istenen dilde, yoksa ilk dolu dilde. */
    public static function titleOf(array $data, ?string $locale = null): string
    {
        foreach (array_unique(array_filter([$locale, ...Locales::all()])) as $code) {
            $title = (string) ($data['i18n'][$code]['title'] ?? '');
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    /** Satılabilir mi (stok takibi açıksa adet yeterli mi). */
    public static function available(array $data, int $quantity = 1): bool
    {
        return empty($data['track_stock']) || (int) ($data['stock'] ?? 0) >= $quantity;
    }

    protected function listSubtitle(array $data): string
    {
        $parts = [Money::format((int) ($data['price'] ?? 0))];
        if (! empty($data['track_stock'])) {
            $parts[] = (int) ($data['stock'] ?? 0).' adet';
        }
        if (($data['sku'] ?? '') !== '') {
            $parts[] = $data['sku'];
        }

        return implode(' · ', $parts);
    }

    protected function validate(array $data, int $id): array
    {
        if (($data['sku'] ?? '') === '') {
            return [];
        }
        foreach ($this->records(false) as $other) {
            if ($other->id !== $id && ($other->data['sku'] ?? '') === $data['sku']) {
                return ['sku' => 'Bu stok kodu başka bir üründe kullanılıyor.'];
            }
        }

        return [];
    }

    protected function publicItem(array $item, string $locale): array
    {
        $price = (int) $item['price'];
        $compare = (int) $item['compare_price'];

        return [
            'id' => $item['id'],
            'order' => $item['order'],
            'slug' => $item['slug'],
            'slugs' => $item['slugs'],
            'languages' => array_keys($item['slugs']),
            'title' => $item['title'],
            'summary' => $item['summary'],
            'body' => $item['body'],
            'sku' => $item['sku'],
            'category' => $item['category'],
            'cover' => $item['cover'],
            'gallery' => $item['gallery'],
            'price' => $price,
            'priceText' => Money::format($price, null, $locale),
            'comparePrice' => $compare > $price ? $compare : 0,
            'comparePriceText' => $compare > $price ? Money::format($compare, null, $locale) : '',
            'currency' => Money::currency(),
            'taxRate' => (int) $item['tax_rate'],
            'inStock' => self::available($item),
            'digital' => (bool) $item['digital'],
            'date' => $item['date'],
            'modified' => $item['modified'],
        ];
    }

    public function seoText(array $entry): string
    {
        return trim((string) ($entry['summary'] ?? '').' '.Text::stripTags((string) ($entry['body'] ?? '')));
    }

    /** schema.org Product + Offer (fiyat, para birimi, stok durumu). */
    public function structuredData(array $entry, array $page): array
    {
        return [
            '@type' => 'Product',
            'name' => $entry['title'] ?? '',
            'description' => $page['description'],
            'url' => $page['url'],
            'image' => $page['image'] ?: null,
            'sku' => ($entry['sku'] ?? '') ?: null,
            'category' => ($entry['category'] ?? '') ?: null,
            'inLanguage' => $page['locale'] ?: Locales::default(),
            'offers' => [
                '@type' => 'Offer',
                'price' => Money::decimal((int) ($entry['price'] ?? 0)),
                'priceCurrency' => $entry['currency'] ?? Money::currency(),
                'availability' => ! empty($entry['inStock']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => $page['url'],
            ],
        ];
    }

    public function searchItem(array $entry): array
    {
        return ['slug' => (string) ($entry['slug'] ?? ''), 'title' => (string) ($entry['title'] ?? ''), 'text' => trim(($entry['priceText'] ?? '').' · '.($entry['summary'] ?? ''), ' ·')];
    }
}
