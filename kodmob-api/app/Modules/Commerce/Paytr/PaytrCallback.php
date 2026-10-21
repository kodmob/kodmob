<?php

namespace App\Modules\Commerce\Paytr;

use App\Core\Http\Controller;
use App\Core\Settings\SettingsService;
use App\Modules\Commerce\Orders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PayTR'nin bildirimi (`POST /api/v1/shop/payments/paytr`; herkese açık, hash denetimli).
 *
 * hash = base64(HMAC-SHA256(merchant_oid + merchant_salt + status + total_amount, merchant_key)). Tutmazsa 400.
 * `status=success` siparişi "Ödendi" yapar (Orders::markPaid; `total_amount` kuruş, taksit farkıyla siparişten
 * büyük olabilir). PayTR yanıtın düz metin "OK" olmasını bekler; değilse bildirimi tekrarlar.
 */
final class PaytrCallback extends Controller
{
    public function __invoke(Request $request): Response
    {
        $s = SettingsService::instance();
        $oid = (string) $request->input('merchant_oid', '');
        $status = (string) $request->input('status', '');
        $total = (string) $request->input('total_amount', '');
        $expected = PaytrGateway::sign($oid.$s->string(PaytrModule::MERCHANT_SALT).$status.$total, $s->string(PaytrModule::MERCHANT_KEY));
        if ($s->string(PaytrModule::MERCHANT_KEY) === '' || ! hash_equals($expected, (string) $request->input('hash', ''))) {
            return new Response('PAYTR bildirimi geçersiz (hash)', 400, ['Content-Type' => 'text/plain']);
        }
        if ($status === 'success') {
            (new Orders)->markPaid('paytr', $oid, (int) $total);
        }

        return new Response('OK', 200, ['Content-Type' => 'text/plain']);
    }
}
