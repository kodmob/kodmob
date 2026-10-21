<?php

namespace App\Core\Events;

use App\Core\Support\Clock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Servislerin yayınladığı olayları `events` tablosuna yazar ve dinleyicilere
 * iletir.
 *
 * - Satır, çağıran servisle **aynı transaction** içinde yazılır: işlem geri
 *   alınırsa olay da geri alınır.
 * - Dinleyiciler (bildirim, canlı nabız) COMMIT'ten sonra çalışır
 *   (`EventRecorded`). Transaction yoksa hemen.
 *
 *     $this->events->publish(new Event('brief.received', 'brief', $brief->id(), ['name' => …]));
 */
final class EventBus
{
    public function publish(Event $event): Event
    {
        $userId = $event->userId ?? (Auth::id() ? (int) Auth::id() : null);
        $now = Clock::now();
        $ip = $event->ip !== '' ? $event->ip : (app()->runningInConsole() ? '' : (string) request()->ip());
        $row = (new Event($event->type, $event->subjectType, $event->subjectId, $event->payload, $event->related, $userId, $now, null, $event->subjectLabel, $ip))->toRow();

        $eventId = (int) DB::table('events')->insertGetId($row);
        foreach ($event->related as $objectType => $ids) {
            foreach ((array) $ids as $objectId) {
                DB::table('event_links')->insertOrIgnore([
                    'event_id' => $eventId,
                    'object_type' => (string) $objectType,
                    'object_id' => (int) $objectId,
                ]);
            }
        }

        $stored = new Event($event->type, $event->subjectType, $event->subjectId, $event->payload, $event->related, $userId, $now, $eventId, $event->subjectLabel, $ip);
        DB::afterCommit(static fn () => event(new EventRecorded($stored)));

        return $stored;
    }

    /**
     * Son olaylar; tür önekleriyle (`brief.`, `jarvis.`) süzülebilir.
     *
     * @param  list<string>  $typePrefixes
     * @return list<Event>
     */
    public function recent(array $typePrefixes = [], ?string $since = null, int $limit = 50): array
    {
        $query = DB::table('events')->orderByDesc('created_at')->orderByDesc('id')->limit(max(1, min(500, $limit)));
        if ($typePrefixes) {
            $query->where(function ($q) use ($typePrefixes) {
                foreach ($typePrefixes as $prefix) {
                    // Önekler koddan gelir (ör. `brief.`); `_` joker olsa da sonuç değişmez.
                    $q->orWhere('event_type', 'like', $prefix.'%');
                }
            });
        }
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->get()->map(fn ($row) => Event::fromRow((array) $row))->all();
    }
}
