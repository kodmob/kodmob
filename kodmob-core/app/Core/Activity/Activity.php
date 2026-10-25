<?php

namespace App\Core\Activity;

/**
 * İşlem kaydının istek içi bağlamı. Panelde her yazma isteği (POST/PUT/PATCH/DELETE)
 * kendiliğinden İşlem Geçmişi'ne yazılır (LogActivity); olay türü rotanın adıdır
 * (`users.update`), kayıt rotadaki `{id}`'dir. Controller ya da servis kaydı
 * okunur hale getirir:
 *
 *     Activity::type('project.updated');                 // olay türü (EventCatalog'da başlığı var)
 *     Activity::subject('project', $id, 'Harman');    // hangi kayıt
 *     Activity::with(['changes' => $changes]);           // ayrıntılar (şifre gibi gizli değer yazılmaz)
 *     Activity::skip();                                  // bu istek kaydedilmez (ör. olay zaten servis içinde yazıldı)
 *
 * Yeni bir uç eklendiğinde hiçbir şey yapmasa da kaydı düşer; bu çağrılar yalnızca
 * kaydı güzelleştirir.
 */
final class Activity
{
    private static ?string $type = null;

    private static ?array $subject = null;

    private static array $payload = [];

    private static bool $skip = false;

    private static bool $force = false;

    public static function reset(): void
    {
        self::$type = null;
        self::$subject = null;
        self::$payload = [];
        self::$skip = false;
        self::$force = false;
    }

    public static function type(string $type): void
    {
        self::$type = $type;
    }

    public static function subject(string $type, int $id, string $label = ''): void
    {
        self::$subject = ['type' => $type, 'id' => $id, 'label' => mb_substr($label, 0, 190)];
    }

    /** @param array<string,mixed> $payload */
    public static function with(array $payload): void
    {
        self::$payload = array_merge(self::$payload, $payload);
    }

    public static function skip(): void
    {
        self::$skip = true;
    }

    /** Okuma isteği de kaydedilsin (ör. yedek indirme). */
    public static function force(): void
    {
        self::$force = true;
    }

    /** @return array{type:?string,subject:?array,payload:array,skip:bool,force:bool} */
    public static function state(): array
    {
        return ['type' => self::$type, 'subject' => self::$subject, 'payload' => self::$payload, 'skip' => self::$skip, 'force' => self::$force];
    }

    /** İç istekten sonra dıştaki isteğin bağlamı geri konur (Core\Http\InternalRequest). */
    public static function restore(array $state): void
    {
        self::$type = $state['type'] ?? null;
        self::$subject = $state['subject'] ?? null;
        self::$payload = (array) ($state['payload'] ?? []);
        self::$skip = (bool) ($state['skip'] ?? false);
        self::$force = (bool) ($state['force'] ?? false);
    }
}
