<?php

namespace App\Modules\Commerce\Booking;

use App\Core\Database\BackupPart;
use App\Core\Module;
use App\Core\Settings\SettingDefinition;
use App\Modules\Commerce\Lines\CartLines;

/**
 * Rezervasyon (isteğe bağlı araç `booking`, Mağaza'ya takılı: `requires: ['commerce']`; WordPress
 * sürümünün rezervasyon modülünden). Turlar ve etkinlikler içerik türü (TourType), tarih + kişi sayılarıyla sepete
 * eklenen rezervasyon satırı (BookingLine; Mağaza'nın sepet, ödeme, e-posta ve sipariş akışını kullanır),
 * günlük kapasite ve fiyat kuralları (BookingPricing), panelde Rezervasyonlar listesi.
 *
 * Uçlar routes/api.php'de (`module:booking`): `/shop/bookings/{tour}/availability`, `/shop/bookings/quote`,
 * panel `/admin/commerce/bookings`.
 */
final class BookingModule extends Module
{
    public const VIEW_BOOKINGS = 'km_view_bookings';

    public const LEAD_DAYS = 'booking.lead_days';

    public function capabilities(): array
    {
        return [self::VIEW_BOOKINGS => ['label' => 'Rezervasyonları görme', 'group' => 'Mağaza']];
    }

    public function contentTypes(): array
    {
        return [TourType::class];
    }

    public function backup(): array
    {
        return [new BackupPart('booking', 'Rezervasyonlar', ['commerce_bookings'], personal: true, contentLinks: ['commerce_bookings' => ['tour_id']])];
    }

    public function settings(): array
    {
        return [
            new SettingDefinition(self::LEAD_DAYS, 'int', 1, 'En erken rezervasyon', 'Mağaza: Rezervasyon', 'Bugünden kaç gün sonrası rezerve edilebilir (0: bugün de).', 0, 365, 'gün', scope: 'project'),
        ];
    }

    public function boot(): void
    {
        CartLines::register(new BookingLine);
    }
}
