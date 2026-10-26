<?php

namespace App\Core\Database;

use Illuminate\Database\Query\Builder;

/**
 * Basit sayfalı liste (sorgu oluşturucudan): `{ items, total, page, per_page, pages }`. Süzgeci, sırası ve sütunları
 * çağıran kurar; tablo deposu gerektirmeyen listeler için (bildirimler, üyeler). Panelin sayfa düğmeleri bu biçimi okur.
 *
 *   Paged::of(DB::table('members')->where(...)->orderByDesc('id'), $page, 25, fn ($row) => [...])
 *
 * @phpstan-type Page array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
 */
final class Paged
{
    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
    public static function of(Builder $query, int $page, int $perPage, callable $map): array
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $total = (clone $query)->count();
        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return [
            'items' => array_values($rows->map($map)->all()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
        ];
    }
}
