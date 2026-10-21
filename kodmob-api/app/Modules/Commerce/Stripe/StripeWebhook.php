<?php

namespace App\Modules\Commerce\Stripe;

use App\Core\Http\Controller;
use App\Core\Settings\SettingsService;
use App\Modules\Commerce\Orders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stripe'ın bildirimi (`POST /api/v1/shop/payments/stripe`; herkese açık, imza denetimli).
 *
 * İmza: `Stripe-Signature: t=<zaman>,v1=<imza>` — imza = HMAC-SHA256("<zaman>.<ham gövde>", whsec_…). Zaman 5
 * dakikadan eskiyse (tekrar saldırısı) ya da imza tutmazsa 400. `checkout.session.completed` ve ödemesi `paid`
 * olan oturum siparişi "Ödendi" yapar (Orders::markPaid; tutar denetimli, ikinci bildirim etkisiz).
 */
final class StripeWebhook extends Controller
{
    private const TOLERANCE = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        if (! self::verify($payload, (string) $request->header('Stripe-Signature', ''), SettingsService::instance()->string(StripeModule::WEBHOOK_SECRET))) {
            return new JsonResponse(['error' => 'imza geçersiz'], 400);
        }
        $event = json_decode($payload, true);
        $session = $event['data']['object'] ?? [];
        if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
            && ($session['payment_status'] ?? '') === 'paid') {
            (new Orders)->markPaid('stripe', (string) ($session['id'] ?? ''), (int) ($session['amount_total'] ?? 0));
        }

        return new JsonResponse(['received' => true]);
    }

    public static function verify(string $payload, string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $time = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't') {
                $time = (int) $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($time === null || abs(($now ?? time()) - $time) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $time.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
