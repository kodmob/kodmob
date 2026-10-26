<?php

namespace App\Core\Sms;

/** Deneme sağlayıcısı: SMS gönderilmez, sunucu günlüğüne yazılır (kurulum denemesi ve uçtan uca testler). */
final class LogProvider implements SmsProvider
{
    public function key(): string
    {
        return 'log';
    }

    public function label(): string
    {
        return 'Gönderme, günlüğe yaz (deneme)';
    }

    public function help(): string
    {
        return 'SMS gönderilmez; sunucu günlüğüne yazılır. Deneme ve test için.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'sender', 'label' => 'Gönderici başlığı', 'type' => 'text', 'required' => false, 'max' => 20, 'placeholder' => 'DENEME', 'sender' => true],
        ];
    }

    public function gateway(array $values): SmsGateway
    {
        return new LogGateway((string) ($values['sender'] ?? ''));
    }
}
