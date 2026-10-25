<?php

namespace App\Modules\Commerce\Stripe;

use App\Core\Exceptions\DomainException;
use App\Core\Modules;
use App\Core\Settings\SettingsService;
use App\Modules\Commerce\Orders;
use App\Modules\Commerce\Payments\PaymentMethod;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout: sipariş tutarı tek satırlık bir ödeme oturumuyla Stripe'ın sayfasında ödenir.
 *
 *   POST https://api.stripe.com/v1/checkout/sessions   (form; Bearer gizli anahtar)
 *   → { id: cs_…, url: https://checkout.stripe.com/… }   ödeme kaydına `id`, müşteri `url`'ye
 *
 * Tutar kuruş (Stripe'ın "en küçük birim"i; TRY destekli). Sonuç StripeWebhook'tan (Orders::markPaid).
 */
final class StripeGateway implements PaymentMethod
{
    public const API = 'https://api.stripe.com/v1/checkout/sessions';

    public function key(): string
    {
        return 'stripe';
    }

    public function label(string $locale): string
    {
        return $locale === 'tr' ? 'Kartla öde (Stripe)' : 'Pay by card (Stripe)';
    }

    public function available(bool $shippable): bool
    {
        return Modules::enabled('stripe') && SettingsService::instance()->string(StripeModule::SECRET_KEY) !== '';
    }

    public function fee(): int
    {
        return 0;
    }

    public function online(): bool
    {
        return true;
    }

    public function start(array $order, string $returnUrl): array
    {
        $response = Http::asForm()->timeout(20)->withToken(SettingsService::instance()->string(StripeModule::SECRET_KEY))->post(self::API, [
            'mode' => 'payment',
            'client_reference_id' => $order['number'],
            'customer_email' => $order['customer_email'],
            'metadata' => ['order' => $order['number']],
            'locale' => in_array($order['locale'], ['tr', 'en', 'de'], true) ? $order['locale'] : 'auto',
            'success_url' => $returnUrl.'&odeme=tamam',
            'cancel_url' => $returnUrl.'&odeme=iptal',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($order['currency']),
                    'unit_amount' => $order['amounts']['total'],
                    'product_data' => ['name' => $order['number']],
                ],
            ]],
        ]);
        $session = $response->json();
        if (! $response->successful() || empty($session['id']) || empty($session['url'])) {
            throw new DomainException('km_payment_failed', 'Stripe ödeme oturumu açılamadı: '.($session['error']['message'] ?? $response->status()), 502);
        }
        Orders::reference($order['id'], $this->key(), (string) $session['id']);

        return ['redirect' => (string) $session['url']];
    }
}
