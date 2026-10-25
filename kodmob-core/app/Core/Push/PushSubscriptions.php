<?php

namespace App\Core\Push;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Anlık bildirim abonelikleri (tablo `push_subscriptions`): sahibi `user` (panel kullanıcısı) ya da `member` (site
 * üyesi). Tarayıcının verdiği adres yalnızca bilinen itme servislerinden olabilir: sunucu oraya istek atar, başka bir
 * adrese yönlendirilemez.
 */
final class PushSubscriptions
{
    public const OWNERS = ['user', 'member'];

    /** İtme servisleri (adresin alan adı bunlardan biriyle biter). */
    private const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'];

    /** Bu kadar art arda başarısız gönderimden sonra abonelik silinir. */
    private const MAX_FAILURES = 5;

    public static function schema(): Schema
    {
        return Schema::object([
            'endpoint' => Field::string('Abonelik adresi gerekli.')->max(1000)->describe('Tarayıcının itme servisi adresi (PushSubscription.endpoint).'),
            'p256dh' => Field::string('Abonelik anahtarı gerekli.')->max(120)->pattern('/^[A-Za-z0-9_-]+=*$/')->describe('Tarayıcının açık anahtarı (base64url).'),
            'auth' => Field::string('Abonelik anahtarı gerekli.')->max(40)->pattern('/^[A-Za-z0-9_-]+=*$/')->describe('Kimlik doğrulama sırrı (base64url).'),
        ]);
    }

    /** Aboneliği kaydeder (aynı adres başka sahipteyse ona geçer: cihazda başka kişi oturum açmış). */
    public static function save(string $ownerType, int $ownerId, array $input, string $userAgent = ''): void
    {
        [$clean, $errors] = self::schema()->check($input);
        if (! isset($errors['endpoint']) && ! self::allowed($clean['endpoint'])) {
            $errors['endpoint'] = 'Bu tarayıcının bildirim servisi desteklenmiyor.';
        }
        if (! isset($errors['p256dh']) && strlen(WebPush::decode($clean['p256dh'])) !== 65) {
            $errors['p256dh'] = 'Abonelik anahtarı geçersiz.';
        }
        if (! isset($errors['auth']) && strlen(WebPush::decode($clean['auth'])) !== 16) {
            $errors['auth'] = 'Abonelik anahtarı geçersiz.';
        }
        Schema::fail($errors);
        if (! in_array($ownerType, self::OWNERS, true)) {
            throw ValidationException::field('owner', 'Geçersiz abonelik sahibi.');
        }
        $hash = hash('sha256', $clean['endpoint']);
        $row = [
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'endpoint' => $clean['endpoint'],
            'p256dh' => $clean['p256dh'], 'auth' => $clean['auth'], 'user_agent' => mb_substr($userAgent, 0, 255) ?: null, 'failures' => 0,
        ];
        if (DB::table('push_subscriptions')->where('endpoint_hash', $hash)->exists()) {
            DB::table('push_subscriptions')->where('endpoint_hash', $hash)->update($row);
        } else {
            DB::table('push_subscriptions')->insert($row + ['endpoint_hash' => $hash, 'created_at' => Clock::now()]);
        }
    }

    /** Sahibin bu adresteki aboneliğini siler; adres verilmezse sahibin bütün abonelikleri. */
    public static function remove(string $ownerType, int $ownerId, ?string $endpoint = null): int
    {
        $query = DB::table('push_subscriptions')->where('owner_type', $ownerType)->where('owner_id', $ownerId);
        if ($endpoint !== null) {
            $query->where('endpoint_hash', hash('sha256', $endpoint));
        }

        return $query->delete();
    }

    public static function has(string $ownerType, int $ownerId, ?string $endpoint = null): bool
    {
        $query = DB::table('push_subscriptions')->where('owner_type', $ownerType)->where('owner_id', $ownerId);
        if ($endpoint !== null) {
            $query->where('endpoint_hash', hash('sha256', $endpoint));
        }

        return $query->exists();
    }

    /** @return list<array{id:int,endpoint:string,p256dh:string,auth:string}> */
    public static function forOwner(string $ownerType, int $ownerId): array
    {
        return DB::table('push_subscriptions')->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->get(['id', 'endpoint', 'p256dh', 'auth'])
            ->map(static fn ($r) => ['id' => (int) $r->id, 'endpoint' => (string) $r->endpoint, 'p256dh' => (string) $r->p256dh, 'auth' => (string) $r->auth])
            ->all();
    }

    public static function count(?string $ownerType = null): int
    {
        return DB::table('push_subscriptions')->when($ownerType, static fn ($q) => $q->where('owner_type', $ownerType))->count();
    }

    /** Gönderim sonucu: 2xx kullanıldı; 404 / 410 abonelik bitti (silinir); diğerleri sayılır, sınırı aşan silinir. */
    public static function result(int $id, int $status): void
    {
        $query = DB::table('push_subscriptions')->where('id', $id);
        if ($status >= 200 && $status < 300) {
            $query->update(['failures' => 0, 'last_used_at' => Clock::now()]);
        } elseif ($status === 404 || $status === 410) {
            $query->delete();
        } else {
            $query->increment('failures');
            DB::table('push_subscriptions')->where('id', $id)->where('failures', '>=', self::MAX_FAILURES)->delete();
        }
    }

    public static function allowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
