<?php

namespace App\Modules\Notifications;

use App\Core\Http\Controller;
use Illuminate\Http\Request;

/**
 *   GET  /notifications          { items, unread } (son 30)
 *   POST /notifications/read     { ids: [1, 2] } ya da { all: true } → { unread }
 */
final class NotificationController extends Controller
{
    public function index(Request $request): array
    {
        return (new NotificationService)->forUser((int) $request->user()->id, (int) $request->query('limit', 30));
    }

    public function read(Request $request): array
    {
        $body = $this->input($request);
        $service = new NotificationService;
        $userId = (int) $request->user()->id;
        $service->markRead($userId, ! empty($body['all']) ? null : (array) ($body['ids'] ?? []));

        return ['unread' => $service->unread($userId)];
    }
}
