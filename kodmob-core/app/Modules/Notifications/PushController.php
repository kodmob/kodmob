<?php

namespace App\Modules\Notifications;

use App\Core\Exceptions\DomainException;
use App\Core\Http\Controller;
use App\Core\Push\PushSubscriptions;
use App\Core\Push\SendPush;
use App\Core\Push\VapidKeys;
use Illuminate\Http\Request;

/**
 * Panel kullanıcısının anlık bildirimleri (bu cihazda aç / kapat); yetki gerekmez, herkes kendi cihazını yönetir:
 *
 *   GET  /push?endpoint=…        { available, public_key, subscribed (bu cihaz), devices }
 *   POST /push/subscribe         { endpoint, p256dh, auth } → { subscribed: true, devices }
 *   POST /push/unsubscribe       { endpoint } (boşsa bütün cihazlar) → { subscribed: false, devices }
 *   POST /push/test              kişinin cihazlarına deneme bildirimi → { sent, failed }
 */
final class PushController extends Controller
{
    public function show(Request $request): array
    {
        $userId = (int) $request->user()->id;
        $key = VapidKeys::publicKey();
        $endpoint = (string) $request->query('endpoint', '');

        return [
            'available' => $key !== null,
            'public_key' => $key,
            'subscribed' => $endpoint !== '' && PushSubscriptions::has('user', $userId, $endpoint),
            'devices' => count(PushSubscriptions::forOwner('user', $userId)),
        ];
    }

    public function subscribe(Request $request): array
    {
        $userId = (int) $request->user()->id;
        PushSubscriptions::save('user', $userId, $this->input($request), (string) $request->userAgent());

        return ['subscribed' => true, 'devices' => count(PushSubscriptions::forOwner('user', $userId))];
    }

    public function unsubscribe(Request $request): array
    {
        $userId = (int) $request->user()->id;
        $endpoint = trim((string) ($this->input($request)['endpoint'] ?? ''));
        PushSubscriptions::remove('user', $userId, $endpoint !== '' ? $endpoint : null);

        return ['subscribed' => false, 'devices' => count(PushSubscriptions::forOwner('user', $userId))];
    }

    public function test(Request $request): array
    {
        $userId = (int) $request->user()->id;
        if (! PushSubscriptions::has('user', $userId)) {
            throw new DomainException('km_push_none', 'Bu hesapta anlık bildirimi açık cihaz yok. Önce "Bu cihazda aç".', 409);
        }

        return (new SendPush('user', $userId, ['title' => 'Deneme bildirimi', 'body' => 'Anlık bildirimler bu cihazda çalışıyor.', 'url' => '/panel/bildirimler', 'tag' => 'push-test']))->handle();
    }
}
