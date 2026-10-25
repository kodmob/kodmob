<?php

namespace App\Core\Settings;

/**
 * Ayar tanımlarının tek listesi. Modüller `settings()` ile tanım verir;
 * KodmobServiceProvider açılışta buraya ekler.
 */
final class SettingsRegistry
{
    /** @var array<string,SettingDefinition> */
    private static array $definitions = [];

    public static function define(SettingDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            self::$definitions[$definition->key] = $definition;
        }
    }

    /** Uygulama açılışında (AppServiceProvider): kayıt yalnızca açık modüllerin tanımlarını taşısın. */
    public static function reset(): void
    {
        self::$definitions = [];
    }

    public static function get(string $key): ?SettingDefinition
    {
        return self::$definitions[$key] ?? null;
    }

    /** @return array<string,SettingDefinition> */
    public static function all(): array
    {
        $all = self::$definitions;
        ksort($all);

        return $all;
    }
}
