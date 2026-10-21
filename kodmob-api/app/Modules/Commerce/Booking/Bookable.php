<?php

namespace App\Modules\Commerce\Booking;

/**
 * Rezerve edilebilen içerik türü (docs/done-onyuz-raporu.md §2). Tür bir rezervasyon profili verir; alanları
 * (BookingFields), fiyatı (BookingPricing), sepet satırı (BookingLine), sitedeki rezervasyon kutusu ve sayfa
 * bölümleri profilden türer:
 *
 *   final class YachtType extends ContentType implements Bookable
 *   {
 *       public function bookingProfile(): BookingProfile
 *       {
 *           return new BookingProfile(BookingProfile::FIXED, ['packages', 'capacity', 'dates'], ['highlights', 'faq']);
 *       }
 *
 *       public function shared(): array { return [...BookingFields::shared($this->bookingProfile()), 'cover' => …]; }
 *   }
 *
 * Kayıtlar Rezervasyon modülü açıkken sepete `type: booking` satırıyla eklenir; tür hangisi olursa olsun.
 */
interface Bookable
{
    public function bookingProfile(): BookingProfile;
}
