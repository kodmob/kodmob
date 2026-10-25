<?php

namespace App\Modules\History;

use App\Core\Activity\EventGroups;
use App\Core\Database\TableRepository;
use App\Core\Events\Event;
use App\Core\Events\EventCatalog;
use App\Core\Exceptions\NotFoundException;
use App\Core\Http\ListQuery;
use App\Core\Http\ListResult;
use App\Core\Support\Clock;

/**
 * İşlem Geçmişi: olay defterinin (`events`) sayfalı, süzgeçli okuması.
 *
 * Süzgeçler: `group` (sekme, sayılarıyla), `user_id`, `type`, `created_at_from` /
 * `created_at_to` (YYYY-AA-GG), `q` (işlem türü, kaydın adı, ayrıntılar).
 * Görünürlük `HistoryScope`'tan (kendi işlemleri / bütün işlemler / bölüm).
 */
final class HistoryRepository extends TableRepository
{
    private ?HistoryScope $scope = null;

    public function scoped(HistoryScope $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    protected function tableName(): string
    {
        return 'events';
    }

    protected function entityClass(): string
    {
        return HistoryRow::class;
    }

    protected function sortable(): array
    {
        return ['id' => 't.id', 'created_at' => 't.created_at'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    protected function filterable(): array
    {
        return [
            'group' => ['column' => 't.group_key', 'type' => 'in'],
            'user_id' => ['column' => 't.user_id', 'type' => 'int'],
            'type' => ['column' => 't.event_type', 'type' => 'in'],
            'subject_type' => ['column' => 't.subject_type', 'type' => 'eq'],
            'subject_id' => ['column' => 't.subject_id', 'type' => 'int'],
            'created_at_from' => ['column' => 't.created_at', 'type' => 'from'],
            'created_at_to' => ['column' => 't.created_at', 'type' => 'to'],
        ];
    }

    protected function searchable(): array
    {
        return ['t.event_type', 't.subject_label', 't.payload'];
    }

    protected function facetable(): array
    {
        return ['group'];
    }

    protected function baseWhere(): array
    {
        return $this->scope?->sql() ?? ['1=1', []];
    }

    /** Sayfalı liste; satırlar tek seferde okunur hale getirilir (kullanıcı adları tek sorgu). */
    public function page(ListQuery $query): ListResult
    {
        $result = $this->paginate($query);

        return new ListResult(self::present($result->items), $result->total, $result->query, $result->totals, $result->facets);
    }

    protected function listRow(array $row): array
    {
        return $row;
    }

    /**
     * Olay satırlarını okunur hale getirir: başlık, açıklama, ton (EventCatalog),
     * kullanıcı adı, grup, kayıt bağlantısı.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array>
     */
    public static function present(array $rows): array
    {
        $events = array_map(static fn ($row) => Event::fromRow($row), $rows);
        $presented = EventCatalog::present($events);
        foreach ($presented as $i => &$item) {
            $row = $rows[$i];
            $group = (string) ($row['group_key'] ?? EventGroups::of($item['type']));
            $item['group'] = $group;
            $item['group_label'] = EventGroups::label($group);
            $item['subject'] = SubjectLinks::of((string) $row['subject_type'], (int) $row['subject_id'], (string) ($row['subject_label'] ?? ''));
            $item['ip'] = (string) ($row['ip'] ?? '');
            $item['at'] = Clock::local((string) $row['created_at']);
            $item['user_name'] ??= null;
        }
        unset($item);

        return $presented;
    }

    public function detail(int $id): array
    {
        $row = $this->first('SELECT t.* FROM '.$this->table().' t WHERE t.id = ?', [$id]);
        if (! $row) {
            throw NotFoundException::message('İşlem bulunamadı.');
        }
        $this->scope?->assertAllows($row);
        $item = self::present([$row])[0];

        // Aynı kaydın diğer işlemleri (görünürlük kapsamı içinde).
        $others = [];
        if ((int) $row['subject_id'] > 0) {
            [$where, $params] = $this->scope?->sql() ?? ['1=1', []];
            $others = $this->rows(
                'SELECT t.* FROM '.$this->table().' t WHERE t.subject_type = ? AND t.subject_id = ? AND '.$where.' ORDER BY t.created_at DESC, t.id DESC LIMIT 30',
                array_merge([(string) $row['subject_type'], (int) $row['subject_id']], $params)
            );
        }
        $item['subject_events'] = array_map(static fn ($e) => [
            'id' => $e['id'],
            'title' => $e['title'],
            'text' => $e['text'],
            'at' => $e['at'],
            'user_name' => $e['user_name'],
            'current' => (int) $e['id'] === $id,
        ], self::present($others));

        return $item;
    }

    /**
     * Gösterge sayıları: aralıktaki işlem sayısı ve önceki eşit aralık; gruplara göre.
     *
     * @return array{total:array{count:int,previous:int},groups:array<string,int>,users:int}
     */
    public function stats(?string $from, ?string $to): array
    {
        $to = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : now()->format('Y-m-d');
        $from = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : now()->subDays(6)->format('Y-m-d');
        $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
        $prevTo = date('Y-m-d', strtotime($from.' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo.' -'.($days - 1).' days'));
        [$where, $params] = $this->scope?->sql() ?? ['1=1', []];
        $count = fn (string $a, string $b) => (int) $this->scalar(
            'SELECT COUNT(*) FROM '.$this->table().' t WHERE '.$where.' AND t.created_at >= ? AND t.created_at <= ?',
            array_merge($params, [$a.' 00:00:00', $b.' 23:59:59'])
        );
        $groups = [];
        foreach ($this->rows(
            'SELECT t.group_key AS g, COUNT(*) AS n FROM '.$this->table().' t WHERE '.$where.' AND t.created_at >= ? AND t.created_at <= ? GROUP BY t.group_key',
            array_merge($params, [$from.' 00:00:00', $to.' 23:59:59'])
        ) as $r) {
            $groups[(string) $r['g']] = (int) $r['n'];
        }
        $users = (int) $this->scalar(
            'SELECT COUNT(DISTINCT t.user_id) FROM '.$this->table().' t WHERE '.$where.' AND t.user_id IS NOT NULL AND t.created_at >= ? AND t.created_at <= ?',
            array_merge($params, [$from.' 00:00:00', $to.' 23:59:59'])
        );

        return [
            'range' => ['from' => $from, 'to' => $to],
            'total' => ['count' => $count($from, $to), 'previous' => $count($prevFrom, $prevTo)],
            'groups' => (object) $groups,
            'users' => $users,
        ];
    }
}
