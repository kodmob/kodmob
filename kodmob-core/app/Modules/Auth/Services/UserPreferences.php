<?php

namespace App\Modules\Auth\Services;

use App\Core\Support\Text;
use App\Modules\Auth\Models\User;

/**
 * Kişiye özel panel tercihleri (`users.preferences`, JSON): her cihazda aynı olması gereken
 * küçük seçimler. Anahtar başına bir değer; hiç yazılmamış anahtar null döner (panel varsayılanı
 * gösterir). Doğrulama tercihi kullanan uçtadır (ör. PreferencesController::saveQuickActions).
 *
 *   UserPreferences::get($user, 'quick_actions');            // ['posts', 'briefs'] ya da null
 *   UserPreferences::set($user, 'quick_actions', [...]);
 *   UserPreferences::forget($user, 'quick_actions');         // varsayılana dön
 *
 * Tarayıcıya özgü kolaylıklar (tablo sütunları, açık sekme) burada değil, localStorage'dadır.
 */
final class UserPreferences
{
    public static function get(User $user, string $key): mixed
    {
        return self::all($user)[$key] ?? null;
    }

    public static function set(User $user, string $key, mixed $value): void
    {
        $all = self::all($user);
        $all[$key] = $value;
        self::store($user, $all);
    }

    public static function forget(User $user, string $key): void
    {
        $all = self::all($user);
        unset($all[$key]);
        self::store($user, $all);
    }

    /** @return array<string,mixed> */
    private static function all(User $user): array
    {
        $raw = $user->getAttribute('preferences');
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }

    private static function store(User $user, array $all): void
    {
        // Güncelleme tarihi ve işlem kaydı değişmesin: yalnızca bu sütun yazılır.
        $user->forceFill(['preferences' => $all ? Text::json($all) : null])->saveQuietly();
    }
}
