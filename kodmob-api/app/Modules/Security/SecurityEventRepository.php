<?php

namespace App\Modules\Security;

use App\Core\Database\TableRepository;
use App\Core\Http\ListQuery;
use App\Core\Http\ListResult;
use App\Core\Support\Clock;
use App\Core\Support\UserAgent;
use App\Core\Support\UserNames;
use App\Modules\History\HistoryRow;

/** Güvenlik Kayıtları listesi (ortak tablo): sekme `type`, süzgeç IP ve tarih, arama. */
final class SecurityEventRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'security_events';
    }

    protected function entityClass(): string
    {
        return HistoryRow::class;
    }

    protected function sortable(): array
    {
        return ['id' => 't.id', 'created_at' => 't.created_at', 'ip' => 't.ip', 'type' => 't.type'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    protected function filterable(): array
    {
        return [
            'type' => ['column' => 't.type', 'type' => 'in'],
            'ip' => ['column' => 't.ip', 'type' => 'eq'],
            'created_at_from' => ['column' => 't.created_at', 'type' => 'from'],
            'created_at_to' => ['column' => 't.created_at', 'type' => 'to'],
        ];
    }

    protected function searchable(): array
    {
        return ['t.ip', 't.username', 't.details', 't.user_agent'];
    }

    protected function facetable(): array
    {
        return ['type'];
    }

    protected function listRow(array $row): array
    {
        return $row;
    }

    public function page(ListQuery $query): ListResult
    {
        $result = $this->paginate($query);
        $names = UserNames::map(array_column($result->items, 'user_id'));
        $types = SecurityLog::types();

        return $result->map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'type_label' => $types[$r['type']]['label'] ?? (string) $r['type'],
            'tone' => $types[$r['type']]['tone'] ?? 'neutral',
            'ip' => (string) $r['ip'],
            'username' => (string) $r['username'],
            'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'user_name' => $r['user_id'] ? ($names[(int) $r['user_id']] ?? null) : null,
            'details' => (string) $r['details'],
            'device' => $r['user_agent'] !== '' ? UserAgent::describe((string) $r['user_agent']) : '',
            'user_agent' => (string) $r['user_agent'],
            'at' => Clock::local((string) $r['created_at']),
        ]);
    }
}
