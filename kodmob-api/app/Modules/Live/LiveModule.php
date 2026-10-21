<?php

namespace App\Modules\Live;

use App\Core\Events\EventRecorded;
use App\Core\Module;
use Illuminate\Support\Facades\Event;

/**
 * Canlı güncelleme: bir şey değişince açık panel sekmelerinin haberdar olması.
 *
 * - Olay defterine düşen her olay konusunu (`brief`, `jarvis_job` …) işaretler.
 * - İçerik kayıtları `content` konusunu kendileri işaretler (ContentType, BlogPosts).
 * - Diğer modüller doğrudan `PulseFile::touch('notification')` çağırabilir.
 *
 * WebSocket/SSE kullanılmaz: paylaşımlı hostingde açık bağlantı başına bir PHP
 * süreci kilitlenir. Panel statik nabız dosyasını birkaç saniyede bir okur.
 *
 *   GET /live → { url, topics }: nabız dosyasının adresi ve şu anki değerleri.
 */
final class LiveModule extends Module
{
    public function boot(): void
    {
        Event::listen(EventRecorded::class, static fn (EventRecorded $e) => PulseFile::touch($e->event->subjectType, 'events'));
    }

    public static function info(): array
    {
        return ['url' => PulseFile::url(), 'topics' => (object) PulseFile::snapshot()];
    }
}
