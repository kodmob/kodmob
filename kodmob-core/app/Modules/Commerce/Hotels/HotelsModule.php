<?php

namespace App\Modules\Commerce\Hotels;

use App\Core\Module;

/**
 * Oteller (isteğe bağlı araç `hotels`, Rezervasyon'a takılı: `requires: ['booking']`). Otel, pansiyon ve villalar
 * içerik türü (HotelType; gece birimi, oda tipi × pansiyon). Sepet, fiyat ucu, kapasite ve paneldeki Rezervasyonlar
 * Rezervasyon modülünün (BookingLine, BookingsController); bu modül yalnızca türü getirir. Sitede sayfaları
 * profilin rotalarında (vite/commerce-routes.js hotelRoutes).
 */
final class HotelsModule extends Module
{
    public function contentTypes(): array
    {
        return [HotelType::class];
    }
}
