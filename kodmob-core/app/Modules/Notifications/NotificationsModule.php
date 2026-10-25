<?php

namespace App\Modules\Notifications;

use App\Core\Events\EventRecorded;
use App\Core\Module;
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

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => (new NotificationService)->prune())->name('notifications:prune')->dailyAt('03:20');
    }
}
