<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabloların satırları olduğu gibi (BackupPart; yedek). Geri yüklemede veritabanı türü değişebilir (yerelde SQLite,
 * canlıda MySQL / PostgreSQL): yalnızca hedefte olan sütunlar yazılır, mantıksal sütunlar bool olur, PostgreSQL'de
 * kimlik sayacı en büyük kimliğin üstüne alınır (DatabaseMover ile aynı kurallar).
 */
final class TableDump
{
    private const CHUNK = 200;

    /**
     * @param  list<string>  $tables
     * @return array<string,list<array<string,mixed>>>
     */
    public static function export(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            if (Schema::hasColumn($table, 'id')) {
                $query->orderBy('id');
            }
            $out[$table] = array_map(static fn ($row) => (array) $row, $query->get()->all());
        }

        return $out;
    }

    /**
     * Tabloları boşaltıp yedektekileri yazar (çağıran işlem içinde çağırır).
     *
     * @param  array<string,mixed>  $dump  tablo → satırlar
     * @param  list<string>  $tables  yazılacak tablolar (yedekte olmayan boş kalır)
     * @param  array<string,list<string>>  $contentLinks  tablo → içerik kimliği sütunları
     * @param  array<int,int>  $contentMap  yedekteki içerik kimliği → yeni kimlik (eşlenmeyen: null ya da 0)
     * @return int yazılan satır
     */
    public static function replace(array $dump, array $tables, array $contentLinks = [], array $contentMap = []): int
    {
        foreach (array_reverse($tables) as $table) {
            DB::table($table)->delete();
        }
        $written = 0;
        foreach ($tables as $table) {
            $columns = Schema::getColumns($table);
            $known = array_flip(array_column($columns, 'name'));
            $bools = array_column(array_filter($columns, static fn ($c) => in_array(strtolower((string) $c['type_name']), ['bool', 'boolean'], true)), 'name');
            $nullable = array_column($columns, 'nullable', 'name');
            $links = array_values(array_intersect($contentLinks[$table] ?? [], array_keys($known)));

            $batch = [];
            foreach ((array) ($dump[$table] ?? []) as $row) {
                $values = array_intersect_key((array) $row, $known);
                foreach ($bools as $name) {
                    if (isset($values[$name])) {
                        $values[$name] = (bool) $values[$name];
                    }
                }
                foreach ($links as $name) {
                    if (! empty($values[$name])) {
                        $values[$name] = $contentMap[(int) $values[$name]] ?? (($nullable[$name] ?? false) ? null : 0);
                    }
                }
                $batch[] = $values;
                if (count($batch) >= self::CHUNK) {
                    DB::table($table)->insert($batch);
                    $written += count($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                DB::table($table)->insert($batch);
                $written += count($batch);
            }
            self::resetSequence($table);
        }

        return $written;
    }

    /** PostgreSQL: kimlikle yazılan satırlardan sonra sayaç en büyük kimliğin üstüne (MySQL ve SQLite kendisi alır). */
    private static function resetSequence(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn($table, 'id')) {
            return;
        }
        $sequence = DB::selectOne('select pg_get_serial_sequence(?, ?) as s', [$table, 'id'])->s ?? null;
        if ($sequence) {
            DB::statement("select setval(?, coalesce((select max(id) from \"{$table}\"), 0) + 1, false)", [$sequence]);
        }
    }
}
