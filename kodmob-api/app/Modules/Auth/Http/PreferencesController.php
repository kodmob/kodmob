<?php

namespace App\Modules\Auth\Http;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Modules\Auth\Services\UserPreferences;
use Illuminate\Http\Request;

/**
 * Kişiye özel tercihler (UserPreferences). İşlem geçmişine yazılmaz.
 *
 *   GET    /me/quick-actions     { keys: [...] | null }   null: hiç düzenlemedi (panel varsayılanı gösterir)
 *   PUT    /me/quick-actions     { keys: [...] }          Diğer İşlemler > Hızlı İşlemler, sırasıyla, en fazla 12
 *   DELETE /me/quick-actions                              varsayılana dön
 *
 * Anahtarlar panelin işlem kataloğundadır (tv/src/panel/operations.js); sunucu yalnızca biçimi
 * denetler. Katalogdan kalkan ya da yetkisi olmayan anahtarı panel göstermez.
 */
final class PreferencesController extends Controller
{
    public const QUICK_ACTIONS = 'quick_actions';

    public const QUICK_ACTIONS_MAX = 12;

    public function quickActions(Request $request): array
    {
        return ['keys' => $this->keys($request)];
    }

    public function saveQuickActions(Request $request): array
    {
        $keys = $this->input($request)['keys'] ?? null;
        if (! is_array($keys)) {
            throw ValidationException::field('keys', 'İşlem listesi gönderin.');
        }
        $clean = [];
        foreach ($keys as $key) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9][a-z0-9:_-]{0,59}$/', $key)) {
                throw ValidationException::field('keys', 'Geçersiz işlem anahtarı.');
            }
            $clean[$key] = true;
        }
        if (count($clean) > self::QUICK_ACTIONS_MAX) {
            throw ValidationException::field('keys', 'Hızlı İşlemler\'e en fazla '.self::QUICK_ACTIONS_MAX.' işlem eklenir.');
        }
        UserPreferences::set($request->user(), self::QUICK_ACTIONS, array_keys($clean));

        return ['keys' => $this->keys($request)];
    }

    public function resetQuickActions(Request $request): array
    {
        UserPreferences::forget($request->user(), self::QUICK_ACTIONS);

        return ['keys' => null];
    }

    /** @return list<string>|null */
    private function keys(Request $request): ?array
    {
        $keys = UserPreferences::get($request->user(), self::QUICK_ACTIONS);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : null;
    }
}
