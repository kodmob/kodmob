<?php

namespace App\Core\Sms;

use Illuminate\Support\Facades\Log;

/** Deneme: SMS gönderilmez, laravel.log'a yazılır (numaralar maskeli, metin yok). */
final class LogGateway implements SmsGateway
{
    public function __construct(private readonly string $sender = '') {}

    public function send(array $numbers, string $text, string $encoding): string
    {
        $job = 'log-'.bin2hex(random_bytes(6));
        Log::info('SMS gönderilmedi (deneme yöntemi)', [
            'job' => $job,
            'to' => array_map(SmsText::mask(...), $numbers),
            'length' => mb_strlen($text),
            'encoding' => $encoding ?: 'gsm',
        ]);

        return $job;
    }

    public function senders(): array
    {
        return [$this->sender !== '' ? $this->sender : 'DENEME'];
    }
}
