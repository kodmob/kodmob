<?php

namespace App\Modules\Commerce\Stripe;

use App\Core\Module;
use App\Core\Settings\SettingDefinition;
use App\Modules\Commerce\Payments\Payments;

/**
 * Stripe ile kartla ödeme (isteğe bağlı araç `stripe`, Mağaza'ya takılı: `requires: ['commerce']`). Müşteri
 * Stripe'ın barındırılan ödeme sayfasına (Checkout) gider; kart bilgisi Kodmob'a hiç gelmez. Sonuç imzalı
 * bildirimle (webhook) gelir: `POST /api/v1/shop/payments/stripe`; Stripe panelinde bu adres
 * `checkout.session.completed` olayına eklenir, imza anahtarı (whsec_…) buraya yazılır.
 *
 * Tümleştirme Stripe'ın herkese açık API belgelerine göre yazıldı (SDK kullanılmaz). "Stripe", sahibinin ticari
 * markasıdır; Kodmob Stripe ile bağlantılı değildir.
 */
final class StripeModule extends Module
{
    public const SECRET_KEY = 'stripe.secret_key';

    public const WEBHOOK_SECRET = 'stripe.webhook_secret';

    public function settings(): array
    {
        $g = 'Mağaza: Stripe';

        return [
            new SettingDefinition(self::SECRET_KEY, 'string', '', 'Gizli anahtar', $g, 'Stripe paneli > Geliştiriciler > API anahtarları: sk_live_… (deneme için sk_test_…).', format: 'secret', scope: 'project'),
            new SettingDefinition(self::WEBHOOK_SECRET, 'string', '', 'Bildirim imza anahtarı', $g, 'Stripe paneli > Webhooks: adres https://<siteniz>/api/v1/shop/payments/stripe, olay checkout.session.completed; imza anahtarı whsec_….', format: 'secret', scope: 'project'),
        ];
    }

    public function boot(): void
    {
        Payments::register(new StripeGateway);
    }
}
