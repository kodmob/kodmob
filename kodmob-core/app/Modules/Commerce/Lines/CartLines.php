<?php

namespace App\Modules\Commerce\Lines;

/**
 * Satır türlerinin kaydı (LineType). Ürün satırı Mağaza'yla gelir; Mağaza'ya takılı modüller kendi `boot()`'unda
 * ekler (`CartLines::register(new BookingLine)`). Kayıtsız türdeki satır sepette görünmez (modülü kapatılmış).
 */
final class CartLines
{
    /** @var array<string,LineType> */
    private static array $types = [];

    public static function register(LineType $type): void
    {
        self::$types[$type->key()] = $type;
    }

    /** Açılışta (CommerceModule::boot) liste baştan kurulur. */
    public static function reset(): void
    {
        self::$types = [];
        self::register(new ProductLine);
    }

    public static function get(string $key): ?LineType
    {
        if (! self::$types) {
            self::reset();
        }

        return self::$types[$key] ?? null;
    }
}
