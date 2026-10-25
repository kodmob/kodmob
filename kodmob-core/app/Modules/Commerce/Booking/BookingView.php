<?php

namespace App\Modules\Commerce\Booking;

use App\Modules\Commerce\Money;

/**
 * Rezerve edilebilen kaydın sitedeki hali: rezervasyon kutusunun özeti (`booking`) ve sayfa bölümleri. Türün
 * `publicItem()`'ı çağırır:
 *
 *   return BookingView::item($this->bookingProfile(), $item, $locale) + ['title' => …];
 *
 * Kutu (kodmob-frontend features/shop.js shopBook) birime göre giriş çizer; fiyatı her seferinde sunucu hesaplar (/shop/bookings/quote).
 */
final class BookingView
{
    /** @return array<string,mixed> */
    public static function item(BookingProfile $profile, array $item, string $locale): array
    {
        $sections = [];
        foreach (['highlights', 'amenities', 'itinerary', 'includes', 'excludes', 'faq', 'meeting'] as $name) {
            if ($profile->section($name)) {
                $sections[$name] = $item[$name] ?? ($name === 'meeting' ? '' : []);
            }
        }
        if ($profile->section('languages')) {
            $sections['guideLanguages'] = array_values((array) ($item['guide_languages'] ?? []));
        }

        return $sections + [
            'duration' => (string) ($item['duration'] ?? ''),
            'booking' => self::describe($profile, $item, $locale),
        ];
    }

    /** Rezervasyon kutusunun özeti. @return array<string,mixed> */
    public static function describe(BookingProfile $profile, array $item, string $locale): array
    {
        $format = static fn (int $minor) => $minor > 0 ? Money::format($minor, null, $locale) : '';
        $price = static fn (string $field) => ['value' => (int) ($item[$field] ?? 0), 'text' => $format((int) ($item[$field] ?? 0))];
        $prices = match ($profile->unit) {
            BookingProfile::DAY => ['day' => $price('price_day')],
            BookingProfile::FIXED => ['fixed' => $price('price_fixed')],
            BookingProfile::NIGHT => ['night' => $price('price_night')],
            default => ['adult' => $price('price_adult'), 'child' => $price('price_child'), 'infant' => $price('price_infant')],
        };
        $main = (int) reset($prices)['value'];

        $packages = [];
        if ($profile->has('packages')) {
            foreach (BookingPricing::packages($item) as $package) {
                $text = (array) ($item['package_texts'][$package['index']] ?? []);
                $packages[] = [
                    'key' => $package['key'],
                    'name' => (string) (($text['name'] ?? '') ?: $package['key']),
                    'subtitle' => (string) ($text['subtitle'] ?? ''),
                    'price' => (int) $package['price'], 'priceText' => $format((int) $package['price']),
                    'priceChild' => (int) ($package['price_child'] ?? 0), 'priceChildText' => $format((int) ($package['price_child'] ?? 0)),
                    'compare' => (int) ($package['compare'] ?? 0), 'compareText' => $format((int) ($package['compare'] ?? 0)),
                ];
            }
        }
        // Gece birimi: oda tipleri ve her birinin pansiyon fiyatları (gecelik, oda başı).
        $rooms = [];
        $roomPrices = [];
        if ($profile->unit === BookingProfile::NIGHT) {
            foreach (BookingPricing::options($item) as $option) {
                $roomPrices[] = $option['price'];
                $key = $option['room'];
                if (! isset($rooms[$key])) {
                    $text = $option['index'] >= 0 ? (array) ($item['room_texts'][$option['index']] ?? []) : [];
                    $rooms[$key] = [
                        'key' => $key,
                        'name' => (string) (($text['name'] ?? '') ?: $key),
                        'subtitle' => (string) ($text['subtitle'] ?? ''),
                        'persons' => $option['persons'] ?: max(0, (int) ($item['room_persons'] ?? 0)),
                        'boards' => [],
                    ];
                }
                $rooms[$key]['boards'][] = ['board' => $option['board'], 'price' => $option['price'], 'priceText' => $format($option['price'])];
            }
            // Seçenekler (oda tipleri ya da tek başına gecelik fiyat) başlayan fiyatın tek kaynağı.
            $main = 0;
        }
        $candidates = array_filter([$main, ...array_column($packages, 'price'), ...$roomPrices], static fn ($v) => $v > 0);
        $from = $candidates ? min($candidates) : 0;

        $groups = [];
        if ($profile->has('groups')) {
            foreach ((array) ($item['group_discounts'] ?? []) as $rule) {
                if ((int) ($rule['min'] ?? 0) > 0 && (int) ($rule['percent'] ?? 0) > 0) {
                    $groups[] = ['min' => (int) $rule['min'], 'percent' => min(100, (int) $rule['percent'])];
                }
            }
            usort($groups, static fn ($a, $b) => $a['min'] <=> $b['min']);
        }

        return $profile->toArray() + [
            'currency' => Money::currency(),
            'prices' => $prices,
            'from' => $from,
            'fromText' => $format($from),
            'packages' => $packages,
            'groups' => $groups,
            'rooms' => array_values($rooms),
            'dates' => ! $profile->ranged() && $profile->has('dates') ? BookingLine::dates($item) : [],
            'minDays' => match ($profile->unit) {
                BookingProfile::DAY => max(1, (int) ($item['min_days'] ?? 1)),
                BookingProfile::NIGHT => max(1, (int) ($item['min_nights'] ?? 1)),
                default => 0,
            },
            'roomPersons' => $profile->unit === BookingProfile::NIGHT ? max(0, (int) ($item['room_persons'] ?? 0)) : 0,
            'checkIn' => (string) ($item['check_in'] ?? ''),
            'checkOut' => (string) ($item['check_out'] ?? ''),
            'maxPersons' => $profile->unit === BookingProfile::FIXED ? (int) ($item['max_persons'] ?? 0) : 0,
            'limited' => $profile->has('capacity') && (int) ($item['capacity'] ?? 0) > 0,
        ];
    }
}
