<?php

namespace App\Modules\Commerce;

use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SettingsService;

/**
 * Mağazanın parası: tutarlar en küçük birimde (kuruş) tam sayı. Para birimi mağazanın ayarı (`commerce.currency`);
 * gösterim dile göre (tr: 1.234,56 ₺ · diğerleri: ₺1,234.56).
 */
final class Money
{
    public const CURRENCIES = ['TRY' => 'Türk lirası (₺)', 'EUR' => 'Euro (€)', 'USD' => 'ABD doları ($)', 'GBP' => 'İngiliz sterlini (£)'];

    private const SYMBOLS = ['TRY' => '₺', 'EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    /** Mağazanın para birimi; Mağaza kapalıyken (ayar tanımsız; projenin rezervasyonlu türü yine açılır) TRY. */
    public static function currency(): string
    {
        $code = SettingsRegistry::get(CommerceModule::CURRENCY) ? (string) SettingsService::instance()->get(CommerceModule::CURRENCY) : '';

        return isset(self::CURRENCIES[$code]) ? $code : 'TRY';
    }

    public static function format(int $minor, ?string $currency = null, string $locale = 'tr'): string
    {
        $currency ??= self::currency();
        $symbol = self::SYMBOLS[$currency] ?? $currency;
        $negative = $minor < 0 ? '-' : '';
        $amount = abs($minor) / 100;

        return $locale === 'tr'
            ? $negative.number_format($amount, 2, ',', '.').' '.$symbol
            : $negative.$symbol.number_format($amount, 2, '.', ',');
    }

    /** schema.org ve ödeme sağlayıcıları için: "1234.56". */
    public static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
