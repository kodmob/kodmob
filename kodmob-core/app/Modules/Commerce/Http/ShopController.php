<?php

namespace App\Modules\Commerce\Http;

use App\Core\Content\Locales;
use App\Core\Http\Controller;
use App\Modules\Commerce\Cart;
use App\Modules\Commerce\Orders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Ziyaretçinin mağaza uçları (herkese açık, istek sınırlı; `module:commerce`):
 *
 *   GET    /shop/cart                  sepet (satırlar, tutarlar, ödeme seçenekleri)
 *   POST   /shop/cart/items            { product, quantity, type?, data? } ekle (type: product | booking…)
 *   PATCH  /shop/cart/items/{line}     { quantity } adet (0 siler); satır anahtarı `product:12`
 *   DELETE /shop/cart/items/{line}
 *   POST   /shop/cart/coupon           { code } uygula (boş kod kaldırır)
 *   POST   /shop/checkout              müşteri bilgileri, ödeme yöntemi, sözleşme onayı → sipariş
 *   GET    /shop/orders/{number}       ?token= müşterinin bağlantısıyla sipariş
 *   POST   /shop/orders/{number}/pay   { token } online ödemeyi (yeniden) başlat → { payment: { redirect } }
 *
 * Sağlayıcıların bildirimleri kendi modüllerinde (Stripe\StripeWebhook, Paytr\PaytrCallback).
 *
 * Sepet anahtarı HttpOnly `km_cart` çerezinde (yalnızca /api/v1/shop'a gider); dil `locale` parametresinden.
 * Yanıtlar önbelleğe alınmaz.
 */
final class ShopController extends Controller
{
    public function cart(Request $request): JsonResponse
    {
        return $this->respond(Cart::open($request->cookie(Cart::COOKIE)), $request, false);
    }

    public function add(Request $request): JsonResponse
    {
        $input = $this->input($request);
        $cart = Cart::open($request->cookie(Cart::COOKIE));
        $cart->add((string) ($input['type'] ?? 'product'), (int) ($input['product'] ?? 0), (int) ($input['quantity'] ?? 1), is_array($input['data'] ?? null) ? $input['data'] : [], self::locale($request));
        $cart->save();

        return $this->respond($cart, $request);
    }

    public function update(Request $request, string $line): JsonResponse
    {
        $cart = Cart::open($request->cookie(Cart::COOKIE));
        $cart->set($line, (int) ($this->input($request)['quantity'] ?? 0), self::locale($request));
        $cart->save();

        return $this->respond($cart, $request);
    }

    public function remove(Request $request, string $line): JsonResponse
    {
        $cart = Cart::open($request->cookie(Cart::COOKIE));
        $cart->set($line, 0, self::locale($request));
        $cart->save();

        return $this->respond($cart, $request);
    }

    public function coupon(Request $request): JsonResponse
    {
        $cart = Cart::open($request->cookie(Cart::COOKIE));
        $cart->coupon(trim((string) ($this->input($request)['code'] ?? '')));
        $cart->save();

        return $this->respond($cart, $request);
    }

    public function checkout(Request $request): JsonResponse
    {
        $cart = Cart::open($request->cookie(Cart::COOKIE));
        $result = (new Orders)->place($cart, $this->input($request), self::locale($request));
        $order = (new Orders)->forCustomer($result['order']['number'], $result['token']);

        return $this->noStore(['order' => $order, 'token' => $result['token'], 'payment' => $result['payment']], 201)
            ->withCookie(self::cookie('', $request, true));
    }

    public function order(Request $request, string $number): JsonResponse
    {
        return $this->noStore((new Orders)->forCustomer($number, (string) $request->query('token', '')));
    }

    /** Online ödemeyi yeniden başlatır (sağlayıcının sayfası açılamamıştı ya da müşteri vazgeçmişti). */
    public function pay(Request $request, string $number): JsonResponse
    {
        return $this->noStore(['payment' => (new Orders)->pay($number, (string) ($this->input($request)['token'] ?? ''))]);
    }

    private function respond(Cart $cart, Request $request, bool $setCookie = true): JsonResponse
    {
        $response = $this->noStore($cart->view(self::locale($request), $request->query('payment') ? (string) $request->query('payment') : null));

        return $setCookie ? $response->withCookie(self::cookie($cart->token, $request)) : $response;
    }

    private static function locale(Request $request): string
    {
        $locale = (string) ($request->query('locale') ?: ($request->input('locale') ?? ''));

        return in_array($locale, Locales::active(), true) ? $locale : Locales::default();
    }

    private static function cookie(string $token, Request $request, bool $forget = false): Cookie
    {
        return new Cookie(Cart::COOKIE, $token, $forget ? 1 : time() + 30 * 86400, '/api/v1/shop', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }
}
