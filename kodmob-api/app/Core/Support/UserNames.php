<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\DB;

/**
 * Kullanıcı kimliği → görünen ad (listelerde kimlik değil ad gösterilir).
 * Tek sorguda toplu çözer ve istek boyunca önbellekte tutar.
 */
final class UserNames
{
    /** @var array<int,string> */
    private static array $cache = [];

    /** @param iterable<int|string|null> $ids @return array<int,string> */
    public static function map(iterable $ids): array
    {
        $want = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! isset(self::$cache[$id])) {
                $want[$id] = true;
            }
        }
        if ($want) {
            foreach (DB::table('users')->whereIn('id', array_keys($want))->get(['id', 'display_name']) as $user) {
                self::$cache[(int) $user->id] = (string) $user->display_name;
            }
            foreach (array_keys($want) as $id) {
                self::$cache[$id] ??= 'Silinmiş kullanıcı';
            }
        }

        return self::$cache;
    }

    public static function of(?int $id): ?string
    {
        return $id ? (self::map([$id])[$id] ?? null) : null;
    }

    /** Testler ve uzun süren işler için. */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
