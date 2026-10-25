<?php

namespace App\Modules\Notifications;

use App\Core\Events\EventRecorded;
use App\Core\Health\Health;
use App\Core\Module;
use App\Core\Push\PushSubscriptions;
use App\Core\Push\VapidKeys;
use App\Core\Security\Capability;
use App\Core\Support\PanelUrl;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * Panel bildirimleri (üst bardaki zil): tablo, uçlar ve olay dinleyicileri.
 * Başka modüller `NotificationService::notifyCapable()` ile bildirimi yetkisi olanlara bırakır.
 * Uçlar: NotificationController.
 */
final class NotificationsModule extends Module
{
    public function boot(): void
    {
        Event::listen(EventRecorded::class, static function (EventRecorded $recorded) {
            $event = $recorded->event;
            if ($event->type !== 'brief.received') {
                return;
            }
            $p = $event->payload;
            $services = implode(', ', (array) ($p['services'] ?? []));
            NotificationService::notifyCapable(
                Capability::VIEW_BRIEFS,
                'brief.received',
                'Yeni talep: '.($p['name'] ?? 'Ziyaretçi'),
                trim(($p['company'] ?? '').($services ? ' · '.$services : ''), ' ·'),
                PanelUrl::to('briefs', ['id' => $event->subjectId]),
                'success',
                ['brief_id' => $event->subjectId]
            );
        });
    }

    /** Genel bakış: kişinin okunmamış bildirimleri. */
    public function widgets(): array
    {
        return [
            ['key' => 'notifications', 'label' => 'Bildirimler', 'icon' => 'bell', 'type' => 'list', 'size' => 'sm', 'order' => 15,
                'link' => ['route' => 'notifications', 'label' => 'Tümü'],
                'data' => static function (array $ctx) {
                    $page = (new NotificationService)->page((int) $ctx['user'], 1, 5, 'unread');

                    return ['empty' => 'Okunmamış bildiriminiz yok.', 'note' => $page['unread'] ? $page['unread'].' okunmamış bildirim.' : '', 'items' => array_map(static fn ($n) => [
                        'title' => $n['title'], 'meta' => $n['message'], 'time' => $n['created_at'], 'url' => $n['url'] ?: null,
                    ], $page['items'])];
                }],
        ];
    }

    /** Sistem Sağlığı: anlık bildirim (anahtar üretilebiliyor mu, kaç cihaz). */
    public function health(): array
    {
        $flags = [];
        $ready = VapidKeys::publicKey() !== null;
        if (! $ready) {
            $flags[] = Health::flag('warn', "Anlık bildirim anahtarı üretilemedi: sunucunun openssl'i P-256 eğrisini desteklemiyor.", 'Hosting firmasından PHP openssl eklentisinin güncel olmasını isteyin; zildeki bildirimler çalışmaya devam eder.');
        }
        $https = str_starts_with((string) config('app.url'), 'https://');
        if ($ready && ! $https) {
            $flags[] = Health::flag('info', 'Anlık bildirim yalnızca https adresinde çalışır.', '.env APP_URL https:// ile başlamalı (yerel 127.0.0.1 hariç).');
        }

        return [Health::section('push', 'Anlık bildirim', 'bell', [
            ['Durum', $ready ? 'Hazır' : 'Kullanılamıyor'],
            ['Açık cihaz (panel)', (string) PushSubscriptions::count('user')],
        ], $flags, ['link' => ['route' => 'notifications', 'label' => 'Bildirimler']])];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => (new NotificationService)->prune())->name('notifications:prune')->dailyAt('03:20');
    }
}
