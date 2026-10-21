<?php

namespace App\Core\Database;

use App\Core\Exceptions\NotFoundException;
use App\Core\Http\ListQuery;
use App\Core\Http\ListResult;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tek tablo üzerinde güvenli okuma/yazma tabanı.
 *
 * Güvenlik kuralı: istekten gelen hiçbir anahtar SQL'e doğrudan girmez.
 * Sıralama `sortable()`, filtreler `filterable()`, arama `searchable()`
 * beyaz listesinden geçer; değerler her zaman bağlanır (`?`).
 *
 *     final class BriefRepository extends TableRepository
 *     {
 *         protected function tableName(): string   { return 'briefs'; }
 *         protected function entityClass(): string { return Brief::class; }
 *         protected function sortable(): array     { return ['id' => 't.id', 'created_at' => 't.created_at']; }
 *         protected function filterable(): array   { return ['status' => ['column' => 't.status', 'type' => 'in']]; }
 *     }
 *
 * SQL MySQL ve SQLite'ta (testler) aynı çalışacak biçimde yazılır.
 */
abstract class TableRepository
{
    protected ConnectionInterface $db;

    public function __construct(?ConnectionInterface $db = null)
    {
        $this->db = $db ?? DB::connection();
    }

    /** Öneksiz tablo adı, ör. `briefs`. */
    abstract protected function tableName(): string;

    /** @return class-string<Entity> */
    abstract protected function entityClass(): string;

    /** API sıralama anahtarı => SQL ifadesi. */
    protected function sortable(): array
    {
        return ['id' => 't.id'];
    }

    protected function defaultSort(): string
    {
        return 'id';
    }

    /**
     * Filtre anahtarı => ['column' => 't.status', 'type' => …]
     *
     * Türler: `eq` (dizi gelirse IN olur), `in` (virgüllü ya da dizi), `int`,
     * `bool`, `like`, `from` / `to` (>= / <=; `to` yalnızca tarih ise gün
     * sonuna tamamlanır), `null` ('1' → IS NULL, '0' → IS NOT NULL).
     *
     * `in` filtresine takma değer verilebilir: `'aliases' => ['found' => '(t.status IN (…))']`.
     * Değer takma adsa sütun yerine o koşul uygulanır; facet sayımında da ayrıca sayılır.
     */
    protected function filterable(): array
    {
        return [];
    }

    /** `q` ile LIKE aranacak SQL sütunları. */
    protected function searchable(): array
    {
        return [];
    }

    /** Filtreye uyan bütün kümenin toplamları: takma ad => SQL (ör. `SUM(t.total)`). */
    protected function aggregates(): array
    {
        return [];
    }

    /** Değer başına sayı dönecek filtre anahtarları (sekme sayıları). */
    protected function facetable(): array
    {
        return [];
    }

    protected function joins(): string
    {
        return '';
    }

    protected function selectColumns(): string
    {
        return 't.*';
    }

    /** Her sorguya eklenen sabit koşul. @return array{0:string,1:array} */
    protected function baseWhere(): array
    {
        return ['1=1', []];
    }

    /** Liste satırını API gösterimine çevirir. */
    protected function listRow(array $row): array
    {
        return $this->hydrate($row)->toArray();
    }

    // ------------------------------------------------------------------ okuma

    public function table(): string
    {
        return $this->t($this->tableName());
    }

    public function find(int $id): ?Entity
    {
        $row = $this->first("SELECT t.* FROM {$this->table()} t WHERE t.id = ?", [$id]);

        return $row ? $this->hydrate($row) : null;
    }

    public function get(int $id): Entity
    {
        return $this->find($id) ?? throw NotFoundException::for($this->tableName(), $id);
    }

    /** Transaction içinde satırı kilitleyerek okur (MySQL; SQLite zaten tek yazıcılıdır). */
    public function getForUpdate(int $id): Entity
    {
        $row = $this->first("SELECT t.* FROM {$this->table()} t WHERE t.id = ?".$this->lockClause(), [$id]);
        if (! $row) {
            throw NotFoundException::for($this->tableName(), $id);
        }

        return $this->hydrate($row);
    }

    public function paginate(ListQuery $query): ListResult
    {
        [$where, $params] = $this->buildWhere($query);
        $from = "FROM {$this->table()} t {$this->joins()} WHERE {$where}";

        $total = (int) $this->scalar("SELECT COUNT(*) {$from}", $params);
        $rows = $this->rows(
            "SELECT {$this->selectColumns()} {$from} ORDER BY {$this->orderBy($query)} LIMIT ? OFFSET ?",
            array_merge($params, [$query->perPage, $query->offset()])
        );

        return new ListResult(
            array_map([$this, 'listRow'], $rows),
            $total,
            $query,
            $total > 0 ? $this->computeTotals($from, $params) : [],
            $this->computeFacets($query)
        );
    }

    /**
     * Filtreye uyan bütün satırları sayfa sayfa verir (CSV dışa aktarma).
     *
     * @return \Generator<array<string,mixed>>
     */
    public function export(ListQuery $query, int $limit = 10000, int $chunk = 500): \Generator
    {
        $yielded = 0;
        for ($page = 1; $yielded < $limit; $page++) {
            $result = $this->paginate($query->withPage($page, $chunk));
            foreach ($result->items as $item) {
                yield $item;
                if (++$yielded >= $limit) {
                    return;
                }
            }
            if (count($result->items) < $chunk) {
                return;
            }
        }
    }

    // ------------------------------------------------------------------ yazma

    public function save(Entity $entity): Entity
    {
        $row = $entity->toRow();
        unset($row['id']);

        if ($entity->isNew()) {
            $entity->assignId((int) $this->db->table($this->tableName())->insertGetId($row));

            return $entity;
        }
        $this->db->table($this->tableName())->where('id', $entity->id())->update($row);

        return $entity;
    }

    // ------------------------------------------------------------ yardımcılar

    protected function t(string $name): string
    {
        return $this->db->getTablePrefix().$name;
    }

    protected function hydrate(array $row): Entity
    {
        $class = $this->entityClass();
        $entity = $class::fromRow($row);
        $entity->assignId((int) $row['id']);

        return $entity;
    }

    /** @return list<array<string,mixed>> */
    protected function rows(string $sql, array $params = []): array
    {
        return array_map(static fn ($row) => (array) $row, $this->db->select($sql, $params));
    }

    /** @return array<string,mixed>|null */
    protected function first(string $sql, array $params = []): ?array
    {
        $row = $this->db->selectOne($sql, $params);

        return $row ? (array) $row : null;
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $row = $this->first($sql, $params);

        return $row ? reset($row) : null;
    }

    protected function lockClause(): string
    {
        return in_array($this->db->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true) ? ' FOR UPDATE' : '';
    }

    /** LIKE değeri: joker karakterler kaçırılır (Sql::like ile kullanılır). */
    protected function like(string $value): string
    {
        return Sql::pattern($value);
    }

    /** Sütun => değer eşitliğiyle tek kayıt. */
    protected function findOneBy(array $criteria, bool $forUpdate = false): ?Entity
    {
        [$where, $params] = $this->criteria($criteria);
        $row = $this->first("SELECT t.* FROM {$this->table()} t WHERE {$where} LIMIT 1".($forUpdate ? $this->lockClause() : ''), $params);

        return $row ? $this->hydrate($row) : null;
    }

    /** @return list<Entity> */
    protected function findBy(array $criteria, string $orderColumn = 'id', string $direction = 'ASC', int $limit = 0): array
    {
        [$where, $params] = $this->criteria($criteria);
        $this->assertIdentifier($orderColumn);
        $sql = "SELECT t.* FROM {$this->table()} t WHERE {$where} ORDER BY t.{$orderColumn} ".(strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        if ($limit > 0) {
            $sql .= ' LIMIT '.(int) $limit;
        }

        return array_map([$this, 'hydrate'], $this->rows($sql, $params));
    }

    protected function existsBy(array $criteria, ?int $exceptId = null): bool
    {
        [$where, $params] = $this->criteria($criteria);
        if ($exceptId !== null) {
            $where .= ' AND t.id <> ?';
            $params[] = $exceptId;
        }

        return (int) $this->scalar("SELECT COUNT(*) FROM {$this->table()} t WHERE {$where}", $params) > 0;
    }

    /** @return array{0:string,1:array} */
    protected function buildWhere(ListQuery $query): array
    {
        [$base, $params] = $this->baseWhere();
        $clauses = [$base];

        foreach ($this->filterable() as $key => $spec) {
            $value = $query->filter($key);
            if ($value === null) {
                continue;
            }
            $column = $spec['column'];
            $type = $spec['type'] ?? 'eq';

            if ($type === 'eq' && is_array($value)) {
                $type = 'in';
            }
            if (is_array($value) && $type !== 'in') {
                $value = (string) reset($value);
            }

            switch ($type) {
                case 'in':
                    $values = is_array($value) ? $value : array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
                    if (! $values) {
                        break;
                    }
                    $aliases = $spec['aliases'] ?? [];
                    $or = [];
                    foreach ($values as $i => $v) {
                        if (isset($aliases[$v])) {
                            $or[] = $aliases[$v];
                            unset($values[$i]);
                        }
                    }
                    if ($values) {
                        $or[] = $column.' IN ('.implode(',', array_fill(0, count($values), '?')).')';
                        array_push($params, ...array_values($values));
                    }
                    $clauses[] = count($or) === 1 ? $or[0] : '('.implode(' OR ', $or).')';
                    break;
                case 'int':
                    $clauses[] = "{$column} = ?";
                    $params[] = (int) $value;
                    break;
                case 'bool':
                    $clauses[] = "{$column} = ?";
                    $params[] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                    break;
                case 'like':
                    $clauses[] = Sql::like($column, $this->db);
                    $params[] = $this->like($value);
                    break;
                case 'from':
                    $clauses[] = "{$column} >= ?";
                    $params[] = $value;
                    break;
                case 'to':
                    $clauses[] = "{$column} <= ?";
                    $params[] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value.' 23:59:59' : $value;
                    break;
                case 'null':
                    $clauses[] = $column.(filter_var($value, FILTER_VALIDATE_BOOLEAN) ? ' IS NULL' : ' IS NOT NULL');
                    break;
                default:
                    $clauses[] = "{$column} = ?";
                    $params[] = $value;
            }
        }

        $searchable = $this->searchable();
        if ($query->search !== '' && $searchable) {
            $like = $this->like($query->search);
            $clauses[] = '('.implode(' OR ', array_map(fn ($c) => Sql::like($c, $this->db), $searchable)).')';
            array_push($params, ...array_fill(0, count($searchable), $like));
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function orderBy(ListQuery $query): string
    {
        $sortable = $this->sortable();
        $key = ($query->sort !== null && isset($sortable[$query->sort])) ? $query->sort : $this->defaultSort();
        $expr = $sortable[$key] ?? 't.id';
        $dir = $query->order === 'asc' ? 'ASC' : 'DESC';

        return $expr === 't.id' ? "t.id {$dir}" : "{$expr} {$dir}, t.id DESC";
    }

    private function computeTotals(string $from, array $params): array
    {
        $aggregates = $this->aggregates();
        if (! $aggregates) {
            return [];
        }
        $select = [];
        foreach ($aggregates as $alias => $expr) {
            $this->assertIdentifier($alias);
            $select[] = "{$expr} AS {$alias}";
        }
        $row = $this->first('SELECT '.implode(', ', $select)." {$from}", $params) ?? [];

        return array_map(static fn ($v) => $v === null ? 0 : $v + 0, $row);
    }

    private function computeFacets(ListQuery $query): array
    {
        $facets = [];
        $filterable = $this->filterable();
        foreach ($this->facetable() as $key) {
            if (! isset($filterable[$key])) {
                continue;
            }
            [$where, $params] = $this->buildWhere($query->withoutFilter($key));
            $column = $filterable[$key]['column'];
            $counts = [];
            foreach ($this->rows("SELECT {$column} AS v, COUNT(*) AS c FROM {$this->table()} t {$this->joins()} WHERE {$where} GROUP BY {$column}", $params) as $row) {
                $counts[(string) $row['v']] = (int) $row['c'];
            }
            foreach ($filterable[$key]['aliases'] ?? [] as $alias => $condition) {
                $counts[(string) $alias] = (int) $this->scalar("SELECT COUNT(*) FROM {$this->table()} t {$this->joins()} WHERE {$where} AND {$condition}", $params);
            }
            $facets[$key] = $counts;
        }

        return $facets;
    }

    /** @return array{0:string,1:array} */
    private function criteria(array $criteria): array
    {
        if (! $criteria) {
            return ['1=1', []];
        }
        $clauses = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            $this->assertIdentifier($column);
            if ($value === null) {
                $clauses[] = "t.{$column} IS NULL";
            } else {
                $clauses[] = "t.{$column} = ?";
                $params[] = is_bool($value) ? (int) $value : $value;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function assertIdentifier(string $name): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Geçersiz sütun adı: '.$name);
        }
    }
}
