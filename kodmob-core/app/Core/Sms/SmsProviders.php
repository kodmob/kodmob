<?php

namespace App\Core\Sms;

/**
 * SMS sağlayıcılarının kaydı. NetGSM ve deneme sağlayıcısı (LogProvider) çatıyla gelir; başka sağlayıcılar (İleti Merkezi,
 * Twilio…) araç paketi olarak modülünün `boot()`'unda kaydolur: `SmsProviders::register(new …Provider)`. Kayıt kodla
 * tanımlıdır, siteye göre değişmez.
 */
final class SmsProviders
{
    /** @var array<string,SmsProvider> */
    private static array $providers = [];

    public static function register(SmsProvider $provider): void
    {
        self::all();
        self::$providers[$provider->key()] = $provider;
    }

    /** Açılışta liste baştan kurulur (modüller yeniden yüklenince). */
    public static function reset(): void
    {
        self::$providers = ['netgsm' => new NetgsmProvider, 'log' => new LogProvider];
    }

    /** @return array<string,SmsProvider> */
    public static function all(): array
    {
        if (! self::$providers) {
            self::reset();
        }

        return self::$providers;
    }

    public static function get(string $key): ?SmsProvider
    {
        return self::all()[$key] ?? null;
    }
}
