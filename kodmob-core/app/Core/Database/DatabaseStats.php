<?php

namespace App\Core\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Veritabanının yüzeysel özeti (Sistem Sağlığı): sürüm, toplam boyut, tablo sayısı, boş alan ve
 * en büyük tablolar. Yalnızca okur. Sürüme özgü sorgular burada (SQLite dosya boyutu, MySQL
 * information_schema, PostgreSQL pg_total_relation_size); satır sayıları tahminidir.
 */
final class DatabaseStats
{
    /** @return array{driver:string,version:string,bytes:int,tables:int,free_bytes:int,largest:list<array{table:string,bytes:int,rows:int}>} */
    public static function summary(?ConnectionInterface $db = null): array
    {
        $db ??= DB::connection();
        $driver = Sql::driver($db);
        $rows = match ($driver) {
            'mysql', 'mariadb' => self::mysql($db),
            'pgsql' => self::pgsql($db),
            'sqlite' => self::sqlite($db),
            default => [],
        };
        usort($rows, static fn ($a, $b) => $b['bytes'] <=> $a['bytes'] ?: $b['rows'] <=> $a['rows']);
        $bytes = $driver === 'sqlite' ? self::sqliteFile($db) : array_sum(array_column($rows, 'bytes'));

        return [
            'driver' => $driver,
            'version' => self::version($db, $driver),
            'bytes' => $bytes,
            'tables' => count($rows),
            'free_bytes' => array_sum(array_column($rows, 'free')),
            'largest' => array_map(static fn ($r) => ['table' => $r['table'], 'bytes' => $r['bytes'], 'rows' => $r['rows']], array_slice($rows, 0, 6)),
        ];
    }

    private static function version(ConnectionInterface $db, string $driver): string
    {
        try {
            $sql = match ($driver) {
                'sqlite' => 'select sqlite_version() as v',
                'pgsql' => 'show server_version',
                default => 'select version() as v',
            };
            $row = (array) $db->selectOne($sql);

            return (string) (reset($row) ?: '');
        } catch (Throwable) {
            return '';
        }
    }

    /** @return list<array{table:string,bytes:int,rows:int,free:int}> */
    private static function mysql(ConnectionInterface $db): array
    {
        $rows = $db->select('select table_name as name, coalesce(data_length, 0) + coalesce(index_length, 0) as bytes, coalesce(data_free, 0) as free, coalesce(table_rows, 0) as estimate
            from information_schema.tables where table_schema = database()');

        return array_map(static fn ($r) => ['table' => (string) $r->name, 'bytes' => (int) $r->bytes, 'rows' => (int) $r->estimate, 'free' => (int) $r->free], $rows);
    }

    /** @return list<array{table:string,bytes:int,rows:int,free:int}> */
    private static function pgsql(ConnectionInterface $db): array
    {
        $rows = $db->select("select c.relname as name, pg_total_relation_size(c.oid) as bytes, greatest(c.reltuples, 0) as estimate
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where c.relkind = 'r' and n.nspname = current_schema()");

        return array_map(static fn ($r) => ['table' => (string) $r->name, 'bytes' => (int) $r->bytes, 'rows' => (int) $r->estimate, 'free' => 0], $rows);
    }

    /** SQLite tablo boyutu tutmaz: satır sayısı (en çok 50 tablo sayılır), boyut dosyanınki. */
    private static function sqlite(ConnectionInterface $db): array
    {
        $names = array_map(static fn ($r) => (string) $r->name, $db->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"));

        return array_map(static function (string $name, int $i) use ($db) {
            $rows = 0;
            if ($i < 50) {
                try {
                    $rows = (int) $db->table($name)->count();
                } catch (Throwable) {
                }
            }

            return ['table' => $name, 'bytes' => 0, 'rows' => $rows, 'free' => 0];
        }, $names, array_keys($names));
    }

    private static function sqliteFile(ConnectionInterface $db): int
    {
        $file = $db instanceof Connection ? (string) $db->getConfig('database') : '';

        return $file !== '' && $file !== ':memory:' && is_file($file) ? (int) filesize($file) : 0;
    }
}
