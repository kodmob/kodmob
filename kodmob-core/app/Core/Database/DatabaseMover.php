<?php

namespace App\Core\Database;

use App\Core\Exceptions\DomainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Bir sitenin verisini başka veritabanına taşır (`php artisan kodmob:db-move`): SQLite, MySQL/MariaDB ve
 * PostgreSQL arasında her yöne. Site küçük bir veritabanıyla başlar, büyüyünce taşınır.
 *
 * 1. Hedefe şema kurulur (migration'lar). Hedefte veri varsa durur; `force` ile baştan kurar.
 * 2. Tablolar yabancı anahtar sırasıyla (önce başvurulan) satır satır kopyalanır; kimlikler korunur.
 *    Doğru/yanlış sütunları hedefin türüne çevrilir (SQLite/MySQL 0-1 → PostgreSQL boolean).
 * 3. PostgreSQL'de kimlik sayaçları en büyük kimliğe çekilir; sonunda satır sayıları karşılaştırılır.
 *
 * Hedef adresi: `pgsql://kullanıcı:şifre@sunucu:5432/veritabanı`, `mysql://…/veritabanı`,
 * `sqlite:C:/yol/site.sqlite`.
 */
final class DatabaseMover
{
    public const TARGET = 'kodmob_move_target';

    private const CHUNK = 200;

    /** Hedef adresinden bağlantı ayarı (Laravel'in o sürücü için varsayılanları üstüne). */
    public static function connectionFromUrl(string $url): array
    {
        if (str_starts_with($url, 'sqlite:')) {
            return ['driver' => 'sqlite', 'database' => substr($url, 7), 'prefix' => '', 'foreign_key_constraints' => true];
        }
        $parts = parse_url($url);
        $driver = ['pgsql' => 'pgsql', 'postgres' => 'pgsql', 'postgresql' => 'pgsql', 'mysql' => 'mysql', 'mariadb' => 'mariadb'][$parts['scheme'] ?? ''] ?? null;
        if (! $driver || empty($parts['host']) || empty($parts['path']) || trim($parts['path'], '/') === '') {
            throw new DomainException('km_db_move_target', 'Hedef adresi anlaşılamadı. Örnek: pgsql://kullanici:sifre@127.0.0.1:5432/veritabani ya da sqlite:C:/yol/site.sqlite', 422);
        }

        return array_merge((array) config("database.connections.{$driver}"), [
            'driver' => $driver,
            'host' => $parts['host'],
            'port' => (string) ($parts['port'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
            'database' => trim(rawurldecode($parts['path']), '/'),
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
        ]);
    }

    /**
     * @param  callable(string $table, int $rows): void|null  $progress
     * @return array<string,int> tablo => kopyalanan satır
     */
    public function move(string $source, array $target, bool $force = false, ?callable $progress = null): array
    {
        config(['database.connections.'.self::TARGET => $target]);
        DB::purge(self::TARGET);
        $from = DB::connection($source);
        $to = DB::connection(self::TARGET);
        if ($from->getDriverName() === $to->getDriverName() && $from->getDatabaseName() === $to->getDatabaseName()) {
            throw new DomainException('km_db_move_same', 'Kaynak ve hedef aynı veritabanı.', 422);
        }

        if (! $force) {
            foreach ($this->tables($to) as $table) {
                if ($table !== 'migrations' && $to->table($table)->exists()) {
                    throw new DomainException('km_db_move_not_empty', "Hedef veritabanında veri var ({$table}). Baştan kurmak için --force verin.", 422);
                }
            }
        }
        Artisan::call($force ? 'migrate:fresh' : 'migrate', ['--database' => self::TARGET, '--force' => true], new NullOutput);

        $tables = $this->ordered($from);
        $targetTables = array_flip($this->tables($to));
        $tables = array_values(array_filter($tables, static fn ($t) => isset($targetTables[$t])));
        // Migration'ların eklediği başlangıç satırları (ör. diller) silinir: önce bağlı tablolar.
        foreach (array_reverse($tables) as $table) {
            $to->table($table)->delete();
        }

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $this->copy($from, $to, $table);
            if ($progress) {
                $progress($table, $counts[$table]);
            }
        }
        if ($to->getDriverName() === 'pgsql') {
            $this->resetSequences($to, $tables);
        }
        foreach ($tables as $table) {
            $copied = $to->table($table)->count();
            if ($copied !== $counts[$table]) {
                throw new DomainException('km_db_move_mismatch', "{$table}: kaynakta {$counts[$table]}, hedefte {$copied} satır.", 500);
            }
        }

        return $counts;
    }

    private function copy(Connection $from, Connection $to, string $table): int
    {
        $columns = Schema::connection(self::TARGET)->getColumns($table);
        $bools = array_column(array_filter($columns, static fn ($c) => in_array(strtolower((string) $c['type_name']), ['bool', 'boolean'], true)), 'name');
        $known = array_flip(array_column($columns, 'name'));
        $hasId = isset($known['id']);

        $rows = $hasId ? $from->table($table)->orderBy('id')->cursor() : $from->table($table)->cursor();
        $batch = [];
        $count = 0;
        foreach ($rows as $row) {
            $values = array_intersect_key((array) $row, $known);
            foreach ($bools as $name) {
                if (isset($values[$name])) {
                    $values[$name] = (bool) $values[$name];
                }
            }
            $batch[] = $values;
            $count++;
            if (count($batch) >= self::CHUNK) {
                $to->table($table)->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            $to->table($table)->insert($batch);
        }

        return $count;
    }

    /** Kopyalama sırası: başvurulan tablo önce (yabancı anahtarlar), kalanı ada göre. */
    private function ordered(Connection $db): array
    {
        $tables = array_values(array_filter($this->tables($db), static fn ($t) => $t !== 'migrations'));
        sort($tables);
        $references = $this->references($db, $tables);
        $deps = [];
        foreach ($tables as $table) {
            $deps[$table] = array_values(array_filter(array_unique($references[$table] ?? []), static fn ($t) => $t !== $table && in_array($t, $tables, true)));
        }
        $order = [];
        $visit = function (string $table, array $path = []) use (&$visit, &$order, $deps) {
            if (in_array($table, $order, true) || in_array($table, $path, true)) {
                return;
            }
            foreach ($deps[$table] ?? [] as $dep) {
                $visit($dep, [...$path, $table]);
            }
            $order[] = $table;
        };
        foreach ($tables as $table) {
            $visit($table);
        }

        return $order;
    }

    /**
     * Tablo → başvurduğu tablolar (yabancı anahtarlar). MySQL / MariaDB'de tek sorgu: tablo başına
     * `getForeignKeys()` information_schema'yı her seferinde tarar (kalabalık sunucuda tablo başına saniyeler).
     *
     * @param  list<string>  $tables
     * @return array<string,list<string>>
     */
    private function references(Connection $db, array $tables): array
    {
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $out = [];
            $rows = $db->select('select table_name as source, referenced_table_name as target from information_schema.key_column_usage where table_schema = ? and referenced_table_name is not null', [$db->getDatabaseName()]);
            foreach ($rows as $row) {
                $out[(string) $row->source][] = (string) $row->target;
            }

            return $out;
        }
        $schema = Schema::connection($db->getName());

        return array_combine($tables, array_map(static fn ($table) => array_map(static fn ($fk) => (string) $fk['foreign_table'], $schema->getForeignKeys($table)), $tables)) ?: [];
    }

    /** @return list<string> varsayılan şemadaki tablolar (PostgreSQL'de search_path, MySQL'de veritabanı) */
    private function tables(Connection $db): array
    {
        $schema = Schema::connection($db->getName());
        $default = match ($db->getDriverName()) {
            'pgsql' => (string) ($db->selectOne('select current_schema() as s')->s ?? 'public'),
            'sqlite' => 'main',
            default => $db->getDatabaseName(),
        };

        // Şema verilir: verilmezse MySQL sunucudaki bütün veritabanlarının tablolarını boyutlarıyla tarar (paylaşımlı
        // ya da MAMP sunucusunda dakikalar sürer).
        return array_values(array_map(static fn ($t) => (string) $t['name'], array_filter($schema->getTables($default), static fn ($t) => ($t['schema'] ?? $default) === $default)));
    }

    private function resetSequences(Connection $db, array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::connection($db->getName())->hasColumn($table, 'id')) {
                continue;
            }
            $sequence = $db->selectOne('select pg_get_serial_sequence(?, ?) as s', [$table, 'id'])->s ?? null;
            if ($sequence) {
                $db->statement("select setval(?, coalesce((select max(id) from \"{$table}\"), 0) + 1, false)", [$sequence]);
            }
        }
    }
}
