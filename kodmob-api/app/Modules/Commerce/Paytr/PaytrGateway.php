<?php

namespace App\Modules\Commerce\Paytr;

use App\Core\Exceptions\DomainException;
use App\Core\Modules;
use App\Core\Settings\SettingsService;
use App\Core\Support\Text;
use App\Modules\Commerce\Money;
use App\Modules\Commerce\Orders;
use App\Modules\Commerce\Payments\PaymentMethod;
use Illuminate\Support\Facades\Http;

/**
 * PayTR iFrame API: önce ödeme jetonu alınır, müşteri PayTR'nin güvenli ödeme sayfasına gider.
 *
 *   POST https://www.paytr.com/odeme/api/get-token   (form)
 *   paytr_token = base64(HMAC-SHA256(merchant_id + user_ip + merchant_oid + email + payment_amount + user_basket
 *                 + no_installment + max_installment + currency + test_mode + merchant_salt, merchant_key))
 *   → { status: success, token }   müşteri https://www.paytr.com/odeme/guvenli/<token> adresine
 *
 * `merchant_oid` yalnızca harf ve rakam: sipariş numarası + rastgele ek (her denemede yeni); ödeme kaydına
 * yazılır, bildirim onunla eşleşir. Tutar kuruş (PayTR: tutar × 100). Sonuç PaytrCallback'ten.
 */
final class PaytrGateway implements PaymentMethod
{
    public const TOKEN_API = 'https://www.paytr.com/odeme/api/get-token';

    public const PAGE = 'https://www.paytr.com/odeme/guvenli/';

    public function key(): string
    {
        return 'paytr';
    }

    public function label(string $locale): string
    {
        return $locale === 'tr' ? 'Kartla öde (PayTR)' : 'Pay by card (PayTR)';
    }

    public function available(bool $shippable): bool
    {
        $s = SettingsService::instance();

        return Modules::enabled('paytr') && $s->string(PaytrModule::MERCHANT_ID) !== '' && $s->string(PaytrModule::MERCHANT_KEY) !== '' && $s->string(PaytrModule::MERCHANT_SALT) !== '';
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
        $s = SettingsService::instance();
        $merchantId = $s->string(PaytrModule::MERCHANT_ID);
        $oid = preg_replace('/[^A-Za-z0-9]/', '', $order['number']).strtoupper(bin2hex(random_bytes(3)));
        $basket = base64_encode(Text::json(array_map(
            static fn ($item) => [$item['title'], Money::decimal($item['unit_price']), $item['quantity']],
            $order['items']
        )));
        $ip = (string) request()->ip();
        $noInstallment = $s->bool(PaytrModule::INSTALLMENTS) ? '0' : '1';
        $currency = $order['currency'] === 'TRY' ? 'TL' : $order['currency'];
        $test = $s->bool(PaytrModule::TEST_MODE) ? '1' : '0';
        $amount = (string) $order['amounts']['total'];
        $hash = $merchantId.$ip.$oid.$order['customer_email'].$amount.$basket.$noInstallment.'0'.$currency.$test;
        $token = self::sign($hash.$s->string(PaytrModule::MERCHANT_SALT), $s->string(PaytrModule::MERCHANT_KEY));
        $address = (array) $order['address'];

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_API, [
            'merchant_id' => $merchantId,
            'user_ip' => $ip,
            'merchant_oid' => $oid,
            'email' => $order['customer_email'],
            'payment_amount' => $amount,
            'paytr_token' => $token,
            'user_basket' => $basket,
            'debug_on' => '0',
            'no_installment' => $noInstallment,
            'max_installment' => '0',
            'user_name' => $order['customer_name'],
            'user_address' => trim(implode(' ', array_filter([$address['line'] ?? '', $address['district'] ?? '', $address['city'] ?? '']))) ?: '-',
            'user_phone' => $order['customer_phone'] ?: '-',
            'merchant_ok_url' => $returnUrl.'&odeme=tamam',
            'merchant_fail_url' => $returnUrl.'&odeme=iptal',
            'timeout_limit' => '30',
            'currency' => $currency,
            'test_mode' => $test,
            'lang' => $order['locale'] === 'tr' ? 'tr' : 'en',
        ]);
        $body = $response->json();
        if (! $response->successful() || ($body['status'] ?? '') !== 'success' || empty($body['token'])) {
            throw new DomainException('km_payment_failed', 'PayTR ödeme jetonu alınamadı: '.($body['reason'] ?? $response->status()), 502);
        }
        Orders::reference($order['id'], $this->key(), $oid);

        return ['redirect' => self::PAGE.$body['token']];
    }

    /** base64(HMAC-SHA256(metin, anahtar)) — jeton ve bildirim aynı imzayı kullanır. */
    public static function sign(string $text, string $key): string
    {
        return base64_encode(hash_hmac('sha256', $text, $key, true));
    }
}
