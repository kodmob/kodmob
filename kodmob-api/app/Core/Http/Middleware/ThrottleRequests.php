<?php

namespace App\Core\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as LaravelThrottle;

/**
 * `throttle:N,1` yol başına sayar. Laravel'in varsayılanı kullanıcı (girişsizde alan adı + IP) başına
 * tek sayaç tutar: aramada yazarken harcanan istekler Site Sihirbazı'nın 6/dk sınırını doldurup
 * "Öneri oluştur"u 429'a düşürüyor, ziyaretçinin sayfa istekleri talep formunun sınırını yiyordu.
 */
class ThrottleRequests extends LaravelThrottle
{
    protected function resolveRequestSignature($request)
    {
        return sha1($request->method().'|'.($request->route()?->uri() ?? $request->path()).'|'.parent::resolveRequestSignature($request));
    }
}
