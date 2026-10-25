<?php

namespace App\Modules\Content\Http;

use App\Core\Dashboard\Widgets;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Modules\Auth\Services\UserPreferences;
use Illuminate\Http\Request;

/**
 * Genel bakış kartları (Core\Dashboard\Widgets) ve kişinin düzeni. Düzen işlem geçmişine yazılmaz.
 *
 *   GET    /admin/dashboard     { widgets: [{ key, label, icon, type, size, link, data }], layout: [{ key, size, hidden }], custom }
 *   PUT    /me/dashboard        { items: [{ key, size, hidden }] } sırasıyla → GET'in yanıtı
 *   DELETE /me/dashboard        varsayılana dön → GET'in yanıtı
 */
final class DashboardController extends Controller
{
    public const PREFERENCE = 'dashboard';

    public function show(Request $request): array
    {
        $user = $request->user();
        $saved = UserPreferences::get($user, self::PREFERENCE);

        return Widgets::build(static fn (string $cap) => (bool) $user->can($cap), (int) $user->id, is_array($saved) ? $saved : null);
    }

    public function save(Request $request): array
    {
        $items = Widgets::clean($this->input($request)['items'] ?? null);
        if ($items === null) {
            throw ValidationException::field('items', 'Kart düzeni geçersiz.');
        }
        UserPreferences::set($request->user(), self::PREFERENCE, $items);

        return $this->show($request);
    }

    public function reset(Request $request): array
    {
        UserPreferences::forget($request->user(), self::PREFERENCE);

        return $this->show($request);
    }
}
