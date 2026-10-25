<?php

namespace App\Modules\Seo\Services;

use App\Core\Database\Row;
use App\Core\Database\TableRepository;

/** 404 listesi (ortak tablo): sekme `status`, süzgeç bot, iç bağlantı, tarih; arama adres/yönlendiren. */
final class NotFoundRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'seo_404';
    }

    protected function entityClass(): string
    {
        return Row::class;
    }

    protected function sortable(): array
    {
        return ['id' => 't.id', 'path' => 't.path', 'hits' => 't.hits', 'last_seen_at' => 't.last_seen_at', 'first_seen_at' => 't.first_seen_at'];
    }

    protected function defaultSort(): string
    {
        return 'last_seen_at';
    }

    protected function filterable(): array
    {
        return [
            'status' => ['column' => 't.status', 'type' => 'in'],
            'bot' => ['column' => 't.bot', 'type' => 'bool'],
            'internal' => ['column' => 't.internal', 'type' => 'bool'],
            'last_seen_at_from' => ['column' => 't.last_seen_at', 'type' => 'from'],
            'last_seen_at_to' => ['column' => 't.last_seen_at', 'type' => 'to'],
        ];
    }

    protected function searchable(): array
    {
        return ['t.path', 't.referrer'];
    }

    protected function facetable(): array
    {
        return ['status'];
    }

    protected function listRow(array $row): array
    {
        return NotFoundLog::present($row);
    }
}
