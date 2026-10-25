<?php

namespace App\Modules\Commerce\Payments;

/**
 * Ödeme yöntemlerinin kaydı. Havale ve kapıda ödeme Mağaza'yla gelir; online sağlayıcılar (Stripe, PayTR) kendi
 * isteğe bağlı modüllerinin `boot()`'unda kaydolur (Mağaza'ya takılı: `requires: ['commerce']`).
 */
final class Payments
{
    /** @var array<string,PaymentMethod> */
    private static array $methods = [];

    public static function register(PaymentMethod $method): void
    {
        self::$methods[$method->key()] = $method;
    }

    /** Açılışta (AppServiceProvider modülleri yeniden yüklerken) liste baştan kurulur. */
    public static function reset(): void
    {
        self::$methods = [];
        self::register(new BankTransfer);
        self::register(new CashOnDelivery);
    }

    /** @return array<string,PaymentMethod> */
    public static function all(): array
    {
        if (! self::$methods) {
            self::reset();
        }

        return self::$methods;
    }

    public static function get(string $key): ?PaymentMethod
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Ödeme adımındaki seçenekler.
     *
     * @return list<array{key:string,label:string,fee:int}>
     */
    public static function options(bool $shippable, string $locale): array
    {
        $out = [];
        foreach (self::all() as $method) {
            if ($method->available($shippable)) {
                $out[] = ['key' => $method->key(), 'label' => $method->label($locale), 'fee' => $method->fee()];
            }
        }

        return $out;
    }
}
