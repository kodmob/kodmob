<?php

namespace App\Modules\Notifications;

use App\Core\Http\Controller;
use Illuminate\Http\Request;

/**
 * Kişinin kendi bildirimleri (yetki gerekmez; her kullanıcı yalnızca kendisininkini görür):
 *
 *   GET  /notifications            { items, unread } (zil; son 30, `limit`)
 *   GET  /notifications?page=1     Bildirimler sayfası: { items, total, page, per_page, pages, unread, kinds }
 *                                  (`per_page`, `status` = unread | read, `kind` = türün ilk parçası)
 *   POST /notifications/read       { ids: [1, 2] } ya da { all: true } → { unread }
 *   POST /notifications/unread     { ids: [1, 2] } → { unread }
 *   POST /notifications/delete     { ids: [1, 2] } ya da { read: true } (okunmuşların hepsi) → { deleted, unread }
 */
final class NotificationController extends Controller
{
    public function index(Request $request): array
    {
        $service = new NotificationService;
        $userId = (int) $request->user()->id;
        if ($request->query->has('page')) {
            return $service->page($userId, (int) $request->query('page', 1), (int) $request->query('per_page', 20), (string) $request->query('status', ''), (string) $request->query('kind', ''));
        }

        return $service->forUser($userId, (int) $request->query('limit', 30));
    }

    public function read(Request $request): array
    {
        $body = $this->input($request);
        $service = new NotificationService;
        $userId = (int) $request->user()->id;
        $service->markRead($userId, ! empty($body['all']) ? null : (array) ($body['ids'] ?? []));

        return ['unread' => $service->unread($userId)];
    }

    public function unread(Request $request): array
    {
        $service = new NotificationService;
        $userId = (int) $request->user()->id;
        $service->markUnread($userId, (array) ($this->input($request)['ids'] ?? []));

        return ['unread' => $service->unread($userId)];
    }

    public function delete(Request $request): array
    {
        $body = $this->input($request);
        $service = new NotificationService;
        $userId = (int) $request->user()->id;
        $deleted = $service->delete($userId, ! empty($body['read']) ? null : (array) ($body['ids'] ?? []));

        return ['deleted' => $deleted, 'unread' => $service->unread($userId)];
    }
}
