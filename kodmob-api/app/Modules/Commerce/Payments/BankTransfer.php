<?php

namespace App\Modules\Commerce\Payments;

use App\Core\Settings\SettingsService;
use App\Modules\Commerce\CommerceModule;
use App\Modules\Commerce\CommerceText;

/** Havale / EFT: sipariş "ödeme bekliyor"da kalır; ödeme gelince panelde "Ödendi" işaretlenir. */
final class BankTransfer implements PaymentMethod
{
    public function key(): string
    {
        return 'bank_transfer';
    }

    public function label(string $locale): string
    {
        return CommerceText::get('pay.bank_transfer', $locale);
    }

    public function available(bool $shippable): bool
    {
        $s = SettingsService::instance();

        return (bool) $s->get(CommerceModule::BANK_ENABLED) && trim((string) $s->get(CommerceModule::BANK_IBAN)) !== '';
    }

    public function fee(): int
    {
        return 0;
    }

    public function online(): bool
    {
        return false;
    }

    public function start(array $order, string $returnUrl): array
    {
        $s = SettingsService::instance();

        return ['instructions' => array_filter([
            CommerceText::get('pay.bank_name', $order['locale']) => (string) $s->get(CommerceModule::BANK_NAME),
            CommerceText::get('pay.bank_holder', $order['locale']) => (string) $s->get(CommerceModule::BANK_HOLDER),
            'IBAN' => (string) $s->get(CommerceModule::BANK_IBAN),
            CommerceText::get('pay.reference', $order['locale']) => $order['number'],
        ], static fn ($v) => $v !== '')];
    }
}
