<?php

namespace App\Core\Content;

use App\Core\Support\Clock;
use App\Core\Support\Text;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * `contents` tablosu: projeler, hizmetler ve referanslar (`type` = km_project,
 * km_service, km_reference). Silinen kayıt çöpe gider (`deleted_at`).
 *
 * Tür başına kayıtlar istek boyunca bellekte tutulur; her yazma belleği bırakır.
 */
final class ContentRepository
{
    /** @var array<string,list<Record>> */
    private static array $cache = [];

    /** @return list<Record> sıraya göre (position, sonra yeniden eskiye) */
    public static function all(string $type, bool $publishedOnly): array
    {
        self::$cache[$type] ??= DB::table('contents')
            ->where('type', $type)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => Record::fromRow((array) $row))
            ->all();

        return $publishedOnly
            ? array_values(array_filter(self::$cache[$type], static fn (Record $r) => $r->status === 'publish'))
            : self::$cache[$type];
    }

    public static function find(int $id, string $type): ?Record
    {
        foreach (self::all($type, false) as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }

        return null;
    }

    public static function exists(int $id, string $type): bool
    {
        return $type !== '' && self::find($id, $type) !== null;
    }

    public static function insert(string $type, string $status, int $position, string $title, array $data, ?string $createdAt = null): int
    {
        $now = Clock::now();
        $id = (int) DB::table('contents')->insertGetId([
            'type' => $type,
            'status' => $status,
            'position' => $position,
            'title' => mb_substr($title, 0, 190),
            'data' => Text::json($data),
            'created_at' => $createdAt ?? $now,
            'updated_at' => $now,
        ]);
        self::forget($type);

        return $id;
    }

    public static function update(int $id, string $type, array $values): void
    {
        if (isset($values['data'])) {
            $values['data'] = Text::json($values['data']);
        }
        if (isset($values['title'])) {
            $values['title'] = mb_substr($values['title'], 0, 190);
        }
        DB::table('contents')->where('id', $id)->where('type', $type)->update($values + ['updated_at' => Clock::now()]);
        self::forget($type);
    }

    /** Çöpe taşır. */
    public static function trash(int $id, string $type): void
    {
        DB::table('contents')->where('id', $id)->where('type', $type)->update(['deleted_at' => Clock::now(), 'deleted_by' => Auth::id()]);
        self::forget($type);
    }

    /** Çöpten geri alır (Silinenler). */
    public static function untrash(int $id, string $type): bool
    {
        $done = DB::table('contents')->where('id', $id)->where('type', $type)->whereNotNull('deleted_at')->update(['deleted_at' => null, 'deleted_by' => null]);
        self::forget($type);

        return $done === 1;
    }

    public static function nextPosition(string $type): int
    {
        return 1 + (int) DB::table('contents')->where('type', $type)->whereNull('deleted_at')->max('position');
    }

    public static function forget(?string $type = null): void
    {
        if ($type === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$type]);
        }
    }
}
