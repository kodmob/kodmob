<?php

namespace App\Modules\Commerce\Payments;

use App\Core\Settings\SettingsService;
use App\Modules\Commerce\CommerceModule;
use App\Modules\Commerce\CommerceText;

/** Kapıda ödeme: yalnızca kargolanan üründe; isteğe bağlı ek ücret. Teslimde panelde "Ödendi" işaretlenir. */
final class CashOnDelivery implements PaymentMethod
{
    public function key(): string
    {
        return 'cash_on_delivery';
    }

    public function label(string $locale): string
    {
        return CommerceText::get('pay.cash_on_delivery', $locale);
    }

    public function available(bool $shippable): bool
    {
        return $shippable && (bool) SettingsService::instance()->get(CommerceModule::COD_ENABLED);
    }

    public function fee(): int
    {
        return CommerceModule::money(CommerceModule::COD_FEE);
    }

    public function online(): bool
    {
        return false;
    }

    public function start(array $order, string $returnUrl): array
    {
        return ['instructions' => [CommerceText::get('pay.cod_note', $order['locale']) => '']];
    }
}
