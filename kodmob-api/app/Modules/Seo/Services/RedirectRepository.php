<?php

namespace App\Modules\Seo\Services;

use App\Core\Database\Row;
use App\Core\Database\TableRepository;

/** Yönlendirme listesi (ortak tablo): sekme `code`, süzgeç regex/aktif, arama kaynak/hedef/not. */
final class RedirectRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'seo_redirects';
    }

    protected function entityClass(): string
    {
        return Row::class;
    }

    protected function sortable(): array
    {
        return ['id' => 't.id', 'source' => 't.source', 'hits' => 't.hits', 'last_hit_at' => 't.last_hit_at', 'created_at' => 't.created_at', 'position' => 't.position'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    protected function filterable(): array
    {
        return [
            'code' => ['column' => 't.code', 'type' => 'in'],
            'is_regex' => ['column' => 't.is_regex', 'type' => 'bool'],
            'active' => ['column' => 't.active', 'type' => 'bool'],
        ];
    }

    protected function searchable(): array
    {
        return ['t.source', 't.target', 't.note'];
    }

    protected function facetable(): array
    {
        return ['code'];
    }

    protected function listRow(array $row): array
    {
        return Redirects::present($row);
    }
}
