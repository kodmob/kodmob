<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Ayarlarda saklanan gizli değerler (SMTP şifresi, SMS şifresi, API anahtarı, uzak depo gizli
 * anahtarı): APP_KEY ile şifrelenip `enc:` önekiyle yazılır, panele hiç geri gönderilmez.
 *
 *   Options::set('x', ['password' => Secret::seal($plain)]);
 *   $plain = Secret::open($stored['password']);   // APP_KEY değişmişse '' (yeniden girilmeli)
 */
final class Secret
{
    private const PREFIX = 'enc:';

    public static function seal(string $plain): string
    {
        return $plain !== '' ? self::PREFIX.Crypt::encryptString($plain) : '';
    }

    /** Şifresiz eski değer olduğu gibi döner. */
    public static function open(mixed $stored): string
    {
        $stored = (string) $stored;
        if (! str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        try {
            return Crypt::decryptString(substr($stored, strlen(self::PREFIX)));
        } catch (Throwable) {
            return '';
        }
    }
}
