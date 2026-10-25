<?php

namespace App\Core\Search;

use App\Core\Database\Sql;
use Illuminate\Database\Query\Builder;

/** Sağlayıcılar için ortak arama: büyük/küçük harften bağımsız "içerir" (Sql::like; SQLite, MySQL, PostgreSQL). */
final class SearchQuery
{
    /** Sütunlardan herhangi birinde geçen. */
    public static function anyLike(Builder $query, array $columns, string $term): Builder
    {
        $value = Sql::pattern($term);

        return $query->where(static function (Builder $q) use ($columns, $value) {
            foreach ($columns as $column) {
                $q->orWhereRaw(Sql::like($column, $q->getConnection()), [$value]);
            }
        });
    }

    /** Yayın durumu rozeti (içerik): taslak ve zamanlanmış görünür, yayında boş. */
    public static function statusBadge(string $status): string
    {
        return ['draft' => 'Taslak', 'future' => 'Zamanlandı', 'private' => 'Gizli'][$status] ?? '';
    }
}
