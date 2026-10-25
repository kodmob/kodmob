<?php

namespace App\Core\Events;

/**
 * Olay defterine yazılan olay, COMMIT'ten sonra Laravel olayı olarak yayılır.
 * Dinleyiciler (bildirim, canlı nabız) `KodmobServiceProvider`'da bağlanır.
 */
final class EventRecorded
{
    public function __construct(public readonly Event $event) {}
}
