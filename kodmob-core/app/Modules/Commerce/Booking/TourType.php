<?php

namespace App\Modules\Commerce\Booking;

use App\Core\Content\ContentType;
use App\Core\Content\Locales;
use App\Core\Support\Text;
use App\Modules\Commerce\Money;

/**
 * Rezerve edilen turlar ve etkinlikler (içerik türü `tours`, kayıt `tour`; BookingModule::contentTypes). Rezervasyon
 * profili kişi başı (yetişkin, çocuk, bebek) ve bütün özellikler: paketler, grup / sezon / erken rezervasyon, günlük
 * kapasite, tarih listesi (boşsa her gün); sayfa bölümleri öne çıkanlar, program, dahil / hariç, SSS, buluşma
 * noktası, rehberlik dilleri. Fiyat, alanlar ve sitedeki kutu profilden (Bookable). Sitede kendi sayfası: profilin
 * rotasında `dynamic: 'tour'` (bookingRoutes).
 */
final class TourType extends ContentType implements Bookable
{
    public const POST_TYPE = 'km_tour';

    public function postType(): string
    {
        return self::POST_TYPE;
    }

    public function labels(): array
    {
        return ['Turlar', 'Tur'];
    }

    public function capabilityLabel(): string
    {
        return 'Turları ve etkinlikleri ekleme ve düzenleme';
    }

    public function bookingProfile(): BookingProfile
    {
        return new BookingProfile(BookingProfile::PERSON, BookingProfile::FEATURES, ['highlights', 'itinerary', 'includes', 'excludes', 'faq', 'meeting', 'languages']);
    }

    public function shared(): array
    {
        return BookingFields::shared($this->bookingProfile()) + [
            'cover' => ['type' => 'image', 'label' => 'Ana görsel', 'section' => 'Görseller'],
            'gallery' => ['type' => 'gallery', 'label' => 'Diğer görseller', 'section' => 'Görseller'],
        ];
    }

    public function localized(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Ad', 'help' => 'Boşsa tur bu dilde rezerve edilmez.', 'section' => 'İçerik', 'width' => 'half'],
            'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title', 'section' => 'İçerik', 'width' => 'half'],
            'summary' => ['type' => 'textarea', 'label' => 'Kısa açıklama', 'max' => 400, 'section' => 'İçerik'],
            'body' => ['type' => 'html', 'label' => 'Açıklama', 'section' => 'İçerik'],
        ] + BookingFields::localized($this->bookingProfile());
    }

    protected function listSubtitle(array $data): string
    {
        $parts = [Money::format((int) ($data['price_adult'] ?? 0)).' / kişi'];
        if ((int) ($data['capacity'] ?? 0) > 0) {
            $parts[] = 'günde '.(int) $data['capacity'].' kişi';
        }
        $dates = BookingLine::dates($data);
        if ($dates) {
            $parts[] = count($dates).' tarih';
        }

        return implode(' · ', $parts);
    }

    protected function publicItem(array $item, string $locale): array
    {
        $format = static fn (int $minor) => $minor > 0 ? Money::format($minor, null, $locale) : '';

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
            // Liste kartı ve yapısal veri için (kutu `booking`'i kullanır).
            'priceAdult' => (int) $item['price_adult'],
            'priceAdultText' => $format((int) $item['price_adult']),
            'currency' => Money::currency(),
            'limited' => (int) $item['capacity'] > 0,
            'dates' => BookingLine::dates($item),
            'date' => $item['date'],
            'modified' => $item['modified'],
        ];
    }

    public function seoText(array $entry): string
    {
        return trim((string) ($entry['summary'] ?? '').' '.Text::stripTags((string) ($entry['body'] ?? '')));
    }

    /** schema.org TouristTrip + Offer (başlayan fiyat). */
    public function structuredData(array $entry, array $page): array
    {
        return [
            '@type' => 'TouristTrip',
            'name' => $entry['title'] ?? '',
            'description' => $page['description'],
            'url' => $page['url'],
            'image' => $page['image'] ?: null,
            'inLanguage' => $page['locale'] ?: Locales::default(),
            'offers' => [
                '@type' => 'Offer',
                'price' => Money::decimal((int) ($entry['booking']['from'] ?? $entry['priceAdult'] ?? 0)),
                'priceCurrency' => $entry['currency'] ?? Money::currency(),
                'url' => $page['url'],
            ],
        ];
    }

    public function searchItem(array $entry): array
    {
        return ['slug' => (string) ($entry['slug'] ?? ''), 'title' => (string) ($entry['title'] ?? ''), 'text' => (string) ($entry['summary'] ?? '')];
    }
}
