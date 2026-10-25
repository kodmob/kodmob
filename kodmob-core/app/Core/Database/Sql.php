<?php

namespace App\Core\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Veritabanından bağımsız SQL parçaları. Kodmob SQLite, MySQL/MariaDB ve PostgreSQL'de çalışır
 * (testler üçünde: kodmob-frontend `npm run test:db`); sürüme özgü bir şey gerekiyorsa burada toplanır.
 *
 *   ->whereRaw(Sql::like('title'), [Sql::pattern($q)])     büyük/küçük harften bağımsız "içerir"
 */
final class Sql
{
    /**
     * Büyük/küçük harften bağımsız LIKE: MySQL ve SQLite'ta LIKE zaten öyle, PostgreSQL'de ILIKE.
     * Joker karakterler `pattern()` ile kaçırılır (`ESCAPE '!'`).
     */
    public static function like(string $column, ?ConnectionInterface $db = null): string
    {
        $operator = self::driver($db) === 'pgsql' ? 'ILIKE' : 'LIKE';

        return "{$column} {$operator} ? ESCAPE '!'";
    }

    /** "içerir" deseni: % ve _ düz metin sayılır. */
    public static function pattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    public static function driver(?ConnectionInterface $db = null): string
    {
        return ($db ?? DB::connection())->getDriverName();
    }
}
