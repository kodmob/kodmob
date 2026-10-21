<?php

namespace App\Core\Events;

use App\Core\Support\UserNames;

/**
 * Olay türü → ekranda okunur başlık, açıklama ve ton. Modüller kendi
 * türlerini `events()` ile verir (Core\Module):
 *
 *     ['brief.received' => ['Yeni talep', 'success', fn (array $p) => $p['name'] ?? '']]
 *
 * Tanımsız tür teknik adıyla gösterilir; kayıt yine okunabilir kalır.
 */
final class EventCatalog
{
    /** @var array<string,array{title:string,tone:string,text:?callable}> */
    private static array $types = [];

    public static function define(string $type, string $title, string $tone = 'primary', ?callable $text = null): void
    {
        self::$types[$type] = ['title' => $title, 'tone' => $tone, 'text' => $text];
    }

    public static function has(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    /**
     * @param  list<Event>  $events
     * @return list<array>
     */
    public static function present(array $events): array
    {
        $names = UserNames::map(array_map(static fn (Event $e) => $e->userId, $events));

        return array_map(static function (Event $event) use ($names) {
            $def = self::$types[$event->type] ?? null;
            $text = '';
            if ($def && $def['text']) {
                try {
                    $text = (string) ($def['text'])($event->payload, $event);
                } catch (\Throwable) {
                    $text = '';
                }
            }

            return $event->toArray() + [
                'title' => $def['title'] ?? $event->type,
                'text' => $text,
                'tone' => $def['tone'] ?? 'neutral',
                'user_name' => $event->userId ? ($names[$event->userId] ?? null) : null,
                'at' => $event->occurredAt,
            ];
        }, $events);
    }
}
