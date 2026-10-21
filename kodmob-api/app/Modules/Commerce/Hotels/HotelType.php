<?php

namespace App\Modules\Commerce\Hotels;

use App\Core\Content\ContentType;
use App\Core\Support\Text;
use App\Modules\Commerce\Booking\Bookable;
use App\Modules\Commerce\Booking\BookingFields;
use App\Modules\Commerce\Booking\BookingPricing;
use App\Modules\Commerce\Booking\BookingProfile;
use App\Modules\Commerce\Booking\BookingView;
use App\Modules\Commerce\Money;

/**
 * Oteller, pansiyonlar ve villalar (içerik türü `hotels`, kayıt `hotel`; HotelsModule::contentTypes). Rezervasyon
 * profili gece başı oda (BookingProfile::NIGHT): giriş – çıkış, oda sayısı, yetişkin / çocuk; oda tipi × pansiyon
 * fiyatları, oda tipi başına adet, toplam oda sayısı, sezon ve erken rezervasyon. Sayfa bölümleri öne çıkanlar,
 * olanaklar, fiyata dahil olanlar, SSS. Sitede kendi sayfası: profilin rotasında `dynamic: 'hotel'` (hotelRoutes).
 */
final class HotelType extends ContentType implements Bookable
{
    public const POST_TYPE = 'km_hotel';

    public const STARS = ['' => 'Yok', '1' => '1 yıldız', '2' => '2 yıldız', '3' => '3 yıldız', '4' => '4 yıldız', '5' => '5 yıldız'];

    public function postType(): string
    {
        return self::POST_TYPE;
    }

    public function labels(): array
    {
        return ['Oteller', 'Otel'];
    }

    public function capabilityLabel(): string
    {
        return 'Otelleri, pansiyonları ve odalarını ekleme ve düzenleme';
    }

    public function bookingProfile(): BookingProfile
    {
        return new BookingProfile(BookingProfile::NIGHT, ['seasons', 'early', 'capacity'], ['highlights', 'amenities', 'includes', 'faq']);
    }

    public function shared(): array
    {
        return BookingFields::shared($this->bookingProfile()) + [
            'stars' => ['type' => 'select', 'label' => 'Yıldız', 'options' => self::STARS, 'section' => 'İçerik', 'width' => 'third'],
            'location' => ['type' => 'text', 'label' => 'Konum', 'max' => 120, 'help' => 'Ör. "Göreme, Nevşehir". Listede ve sayfada görünür.', 'section' => 'İçerik', 'width' => 'half'],
            'cover' => ['type' => 'image', 'label' => 'Ana görsel', 'section' => 'Görseller'],
            'gallery' => ['type' => 'gallery', 'label' => 'Diğer görseller', 'section' => 'Görseller'],
        ];
    }

    public function localized(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Ad', 'help' => 'Boşsa otel bu dilde rezerve edilmez.', 'section' => 'İçerik', 'width' => 'half'],
            'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title', 'section' => 'İçerik', 'width' => 'half'],
            'summary' => ['type' => 'textarea', 'label' => 'Kısa açıklama', 'max' => 400, 'section' => 'İçerik'],
            'body' => ['type' => 'html', 'label' => 'Açıklama', 'section' => 'İçerik'],
        ] + BookingFields::localized($this->bookingProfile());
    }

    protected function listSubtitle(array $data): string
    {
        $prices = array_column(BookingPricing::options($data), 'price');
        $parts = [($prices ? Money::format(min($prices)) : Money::format(0)).' / gece'];
        $rooms = count(array_unique(array_column(BookingPricing::options($data), 'room')));
        if ($rooms > 1) {
            $parts[] = $rooms.' oda tipi';
        }
        if ((string) ($data['location'] ?? '') !== '') {
            $parts[] = (string) $data['location'];
        }

        return implode(' · ', $parts);
    }

    protected function publicItem(array $item, string $locale): array
    {
        return BookingView::item($this->bookingProfile(), $item, $locale) + [
            'id' => $item['id'],
            'order' => $item['order'],
            'slug' => $item['slug'],
            'slugs' => $item['slugs'],
            'languages' => array_keys($item['slugs']),
            'title' => $item['title'],
            'summary' => $item['summary'],
            'body' => $item['body'],
            'cover' => $item['cover'],
            'gallery' => $item['gallery'],
            'stars' => (int) $item['stars'],
            'location' => (string) $item['location'],
            'currency' => Money::currency(),
            'date' => $item['date'],
            'modified' => $item['modified'],
        ];
    }

    public function seoText(array $entry): string
    {
        return trim((string) ($entry['summary'] ?? '').' '.Text::stripTags((string) ($entry['body'] ?? '')));
    }

    /** schema.org Hotel: yıldız, konum, giriş / çıkış saati, olanaklar, başlayan fiyat. */
    public function structuredData(array $entry, array $page): array
    {
        $booking = (array) ($entry['booking'] ?? []);
        $from = (int) ($booking['from'] ?? 0);

        return [
            '@type' => 'Hotel',
            'name' => $entry['title'] ?? '',
            'description' => $page['description'],
            'url' => $page['url'],
            'image' => $page['image'] ?: null,
            'starRating' => (int) ($entry['stars'] ?? 0) > 0 ? ['@type' => 'Rating', 'ratingValue' => (int) $entry['stars']] : null,
            'address' => (string) ($entry['location'] ?? '') !== '' ? ['@type' => 'PostalAddress', 'addressLocality' => (string) $entry['location']] : null,
            'checkinTime' => ($booking['checkIn'] ?? '') ?: null,
            'checkoutTime' => ($booking['checkOut'] ?? '') ?: null,
            'amenityFeature' => array_map(static fn ($name) => ['@type' => 'LocationFeatureSpecification', 'name' => (string) $name, 'value' => true], array_values((array) ($entry['amenities'] ?? []))) ?: null,
            'priceRange' => $from > 0 ? Money::format($from, null, $page['locale'] ?: null).'+' : null,
        ];
    }

    public function searchItem(array $entry): array
    {
        return ['slug' => (string) ($entry['slug'] ?? ''), 'title' => (string) ($entry['title'] ?? ''), 'text' => trim(((string) ($entry['location'] ?? '')).' '.((string) ($entry['summary'] ?? '')))];
    }
}
