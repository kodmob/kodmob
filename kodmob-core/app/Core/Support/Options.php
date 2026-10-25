<?php

namespace App\Core\Support;

use App\Core\Exceptions\DomainException;
use App\Core\Sites\Sites;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Yapılandırılmış değerlerin anahtar-değer deposu (WordPress'teki
 * get_option/update_option'ın karşılığı): sayfa metinleri, teklif fiyatları,
 * Jarvis yapay zekâ ayarı gibi. Tanımlı tek değerli ayarlar SettingsService'tedir.
 *
 * Değerler JSON saklanır; istek boyunca bellekte tutulur.
 *
 * Paylaşılan sistem ayarları (`SHARED`: e-posta, SMS, yapay zekâ; docs/done-panel-yapisi-raporu.md P4) kurulumun
 * tamamınındır: alt projede ana projenin veritabanından okunur, alt projeden yazılamaz (Sistem Ayarları ana
 * projede). Gizli değerler APP_KEY ile mühürlü; kurulumdaki bütün projeler aynı anahtarı kullanır.
 */
final class Options
{
    /** Alt projelerin ana projeden aldığı ayarlar (MailSettings, SmsSettings, AiSettings). */
    public const SHARED = ['mail_settings', 'sms_settings', 'ai_settings', 'panel_languages'];

    /** @var array<string,mixed> */
    private static array $cache = [];

    public static function get(string $name, mixed $default = null): mixed
    {
        if (! array_key_exists($name, self::$cache)) {
            $raw = self::table($name)->where('name', $name)->value('value');
            self::$cache[$name] = $raw === null ? null : json_decode((string) $raw, true);
        }

        return self::$cache[$name] ?? $default;
    }

    public static function set(string $name, mixed $value): void
    {
        self::guard($name);
        DB::table('options')->updateOrInsert(['name' => $name], ['value' => Text::json($value), 'updated_at' => Clock::now()]);
        self::$cache[$name] = $value;
    }

    public static function forget(string $name): void
    {
        self::guard($name);
        DB::table('options')->where('name', $name)->delete();
        unset(self::$cache[$name]);
    }

    /** Testler ve uzun süren işler için: bellekteki kopyayı bırakır. */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Paylaşılan ayar mı ve şu an alt projede miyiz (ana projenin veritabanı okunur). */
    public static function inherited(string $name): bool
    {
        return in_array($name, self::SHARED, true) && ! Sites::isMain() && (string) config('kodmob.main_connection') !== '';
    }

    private static function table(string $name): Builder
    {
        return self::inherited($name) ? DB::connection((string) config('kodmob.main_connection'))->table('options') : DB::table('options');
    }

    private static function guard(string $name): void
    {
        if (self::inherited($name)) {
            throw new DomainException('km_shared_setting', 'Bu ayar ana projede yönetilir (Sistem Ayarları); alt projeler onu kullanır.', 409);
        }
    }
}
