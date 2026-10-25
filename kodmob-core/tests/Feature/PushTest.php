<?php

namespace Tests\Feature;

use App\Core\Push\WebPush;
use App\Modules\Notifications\NotificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Anlık bildirim: cihazda aç / kapat, yalnızca bilinen itme servisleri, bildirimle gönderim, biten abonelik silinir. */
class PushTest extends TestCase
{
    private function device(string $endpoint = 'https://fcm.googleapis.com/fcm/send/cihaz-1'): array
    {
        $keys = WebPush::generateKeys();

        return ['endpoint' => $endpoint, 'p256dh' => WebPush::encode($keys['public']), 'auth' => WebPush::encode(random_bytes(16))];
    }

    public function test_subscribe_accepts_only_known_push_services_and_valid_keys(): void
    {
        $admin = $this->admin();
        $device = $this->device();

        $this->getJson('/v1/push?endpoint='.urlencode($device['endpoint']), $admin)->assertOk()
            ->assertJsonPath('available', true)->assertJsonPath('subscribed', false)
            ->assertJsonPath('public_key', fn ($k) => strlen(WebPush::decode($k)) === 65);

        // Sunucu bu adrese istek atar: başka adrese yönlendirilemez.
        foreach (['https://ornek.test/x', 'http://fcm.googleapis.com/x', 'https://fcm.googleapis.com.ornek.test/x', 'https://127.0.0.1/x'] as $bad) {
            $this->postJson('/v1/push/subscribe', ['endpoint' => $bad] + $device, $admin)->assertStatus(422)->assertJsonPath('details.fields.endpoint', fn ($m) => $m !== '');
        }
        $this->postJson('/v1/push/subscribe', ['p256dh' => 'kisa'] + $device, $admin)->assertStatus(422)->assertJsonPath('details.fields.p256dh', 'Abonelik anahtarı geçersiz.');

        $this->postJson('/v1/push/subscribe', $device, $admin)->assertOk()->assertJsonPath('devices', 1);
        $this->postJson('/v1/push/subscribe', $device, $admin)->assertOk()->assertJsonPath('devices', 1);
        $this->getJson('/v1/push?endpoint='.urlencode($device['endpoint']), $admin)->assertJsonPath('subscribed', true);

        // Aynı tarayıcıda başka kişi oturum açarsa abonelik ona geçer.
        $editor = $this->as('editor');
        $this->postJson('/v1/push/subscribe', $device, $editor)->assertOk();
        $this->getJson('/v1/push?endpoint='.urlencode($device['endpoint']), $admin)->assertJsonPath('subscribed', false)->assertJsonPath('devices', 0);

        $this->postJson('/v1/push/unsubscribe', ['endpoint' => $device['endpoint']], $editor)->assertOk()->assertJsonPath('devices', 0);
        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }

    public function test_notification_is_pushed_encrypted_and_gone_subscription_is_removed(): void
    {
        $admin = $this->admin();
        $me = $this->userId('yonetici');
        $first = $this->device();
        $second = $this->device('https://updates.push.services.mozilla.com/wpush/v2/cihaz-2');
        $this->postJson('/v1/push/subscribe', $first, $admin)->assertOk();
        $this->postJson('/v1/push/subscribe', $second, $admin)->assertOk();

        Http::fake([
            'fcm.googleapis.com/*' => Http::response('', 201),
            'updates.push.services.mozilla.com/*' => Http::response('', 410),
        ]);
        (new NotificationService)->create($me, 'brief.received', 'Yeni talep: Ayşe', 'Web sitesi', '/panel/talepler/5');

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($first) {
            if ($request->url() !== $first['endpoint']) {
                return false;
            }
            $this->assertSame('aes128gcm', $request->header('Content-Encoding')[0]);
            $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]+$/', $request->header('Authorization')[0]);
            // Gövde şifreli: başlık (86 bayt) + şifreli metin; düz metin görünmez.
            $this->assertStringNotContainsString('Ayşe', $request->body());
            $this->assertGreaterThan(86 + 16, strlen($request->body()));

            return true;
        });
        // Mozilla 410 dedi: o abonelik silindi; diğeri kullanıldı.
        $this->assertSame([$first['endpoint']], DB::table('push_subscriptions')->pluck('endpoint')->all());
        $this->assertNotNull(DB::table('push_subscriptions')->value('last_used_at'));

        $this->postJson('/v1/push/test', [], $admin)->assertOk()->assertJsonPath('sent', 1);
    }

    public function test_without_devices_nothing_is_sent(): void
    {
        Http::fake();
        $admin = $this->admin();
        (new NotificationService)->create($this->userId('yonetici'), 'brief.received', 'Yeni talep');
        Http::assertNothingSent();
        $this->postJson('/v1/push/test', [], $admin)->assertStatus(409)->assertJsonPath('code', 'km_push_none');
    }
}
