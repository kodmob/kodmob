<?php

namespace App\Core\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Zaman biçimleri. Veritabanı tarihleri site saatiyle (APP_TIMEZONE) yazılır;
 * API iki biçimde döner (tv-core ile aynı):
 *
 * - `local()`: `2026-10-06T12:00:00` (saat dilimsiz, site saati — WordPress'in mysql_to_rfc3339'u)
 * - `atom()`:  `2026-10-06T09:00:00+00:00` (UTC, ATOM — içerik tarihleri)
 */
final class Clock
{
    /** Şimdi, veritabanı biçiminde (site saati). */
    public static function now(): string
    {
        return Carbon::now()->format('Y-m-d H:i:s');
    }

    /** Bugün (site saatiyle): `2026-10-20`. */
    public static function today(): string
    {
        return Carbon::now()->format('Y-m-d');
    }

    public static function local(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::carbon($value)->format('Y-m-d\TH:i:s');
    }

    public static function atom(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::carbon($value)->utc()->format(DATE_ATOM);
    }

    public static function carbon(DateTimeInterface|string $value): Carbon
    {
        return $value instanceof DateTimeInterface ? Carbon::instance($value)->setTimezone(config('app.timezone')) : Carbon::parse($value, config('app.timezone'));
    }
}
