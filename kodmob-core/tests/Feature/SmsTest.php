<?php

namespace Tests\Feature;

use App\Core\Mail\SiteMail;
use App\Core\Sms\LogGateway;
use App\Core\Sms\NetgsmGateway;
use App\Core\Sms\SmsGateway;
use App\Core\Sms\SmsProvider;
use App\Core\Sms\SmsProviders;
use App\Core\Sms\SmsText;
use App\Core\Support\Options;
use App\Core\Support\Secret;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** SMS (sağlayıcı kaydı, NetGSM) ve kullanıcılara SMS / e-posta. */
class SmsTest extends TestCase
{
    public function test_text_and_number_rules_match_the_shared_vectors(): void
    {
        $vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/sms.json')), true);
        foreach ($vectors['texts'] as $v) {
            $text = isset($v['repeat']) ? str_repeat($v['repeat'], $v['count']) : $v['text'];
            $info = SmsText::analyze($text);
            $label = mb_substr($text, 0, 20);
            if (isset($v['clean'])) {
                $this->assertSame($v['clean'], $info['text'], "clean: $label");
                $this->assertSame($v['unsupported'], $info['unsupported'], "unsupported: $label");
            }
            $this->assertSame([$v['encoding'], $v['length'], $v['parts']], [$info['encoding'], $info['length'], $info['parts']], $label);
        }
        foreach ($vectors['numbers'] as [$raw, $expected]) {
            $this->assertSame($expected, SmsText::number($raw), "numara: $raw");
        }
        foreach ($vectors['masks'] as [$number, $expected]) {
            $this->assertSame($expected, SmsText::mask($number));
        }
    }

    public function test_settings_keep_the_password_secret(): void
    {
        $h = $this->admin();
        $view = $this->getJson('/v1/system/sms', $h)->assertOk()->assertJsonPath('provider', 'off')->assertJsonPath('enabled', false)->json();
        $this->assertSame(['netgsm', 'log'], array_column($view['providers'], 'key'), 'NetGSM çatıda; deneme sağlayıcısı');
        $this->assertSame(['username', 'password', 'sender', 'iys_filter', 'app_name'], array_column($view['providers'][0]['fields'], 'key'));
        $this->putJson('/v1/system/sms', ['provider' => 'netgsm', 'values' => ['username' => '']], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.username', 'NetGSM kullanıcı adını (abone numarası ya da alt kullanıcı) yazın.')
            ->assertJsonPath('details.fields.sender', 'Onaylı gönderici başlığını yazın.');

        $view = $this->putJson('/v1/system/sms', ['provider' => 'netgsm', 'values' => ['username' => '8503020000', 'password' => 'çok-gizli', 'sender' => 'KODMOB', 'iys_filter' => '0']], $h)->assertOk()->json();
        $netgsm = $view['providers'][0];
        $this->assertTrue($netgsm['secrets']['password']);
        $this->assertTrue($view['enabled']);
        $this->assertArrayNotHasKey('password', $netgsm['values']);
        $this->assertStringStartsWith('enc:', Options::get('sms_settings')['values']['netgsm']['password']);
        $this->assertStringNotContainsString('çok-gizli', (string) DB::table('events')->get()->toJson());

        $this->putJson('/v1/system/sms', ['values' => ['password' => '']], $h)->assertJsonPath('providers.0.secrets.password', true);
        $this->putJson('/v1/system/sms', ['clear' => ['password']], $h)->assertJsonPath('providers.0.secrets.password', false)->assertJsonPath('enabled', false)
            ->assertJsonPath('problem', 'NetGSM api şifresi eksik (Sistem Ayarları › SMS).');
        $this->putJson('/v1/system/sms', ['values' => ['iys_filter' => '99']], $h)->assertStatus(422);
        $this->putJson('/v1/system/sms', ['provider' => 'yok'], $h)->assertStatus(422);
        $this->getJson('/v1/system/sms', $this->as('editor'))->assertForbidden();
    }

    public function test_settings_from_3_0_are_read_and_a_removed_provider_is_explained(): void
    {
        $h = $this->admin();
        // 3.0: NetGSM alanları düz saklanıyordu.
        Options::set('sms_settings', ['provider' => 'netgsm', 'username' => '8503020000', 'password' => Secret::seal('eski'), 'sender' => 'KODMOB', 'iys_filter' => '0', 'app_name' => '']);
        $this->getJson('/v1/system/sms', $h)->assertJsonPath('enabled', true)->assertJsonPath('providers.0.values.username', '8503020000')->assertJsonPath('providers.0.secrets.password', true);

        // Kayıtta olmayan (paketi kaldırılmış) sağlayıcı: ne yapılacağı söylenir.
        Options::set('sms_settings', ['provider' => 'ileti', 'values' => ['ileti' => ['key' => 'x']]]);
        $this->getJson('/v1/system/sms', $h)->assertJsonPath('provider', 'off')->assertJsonPath('problem', fn ($m) => str_contains($m, 'kodmob:add ileti'));
        $this->postJson('/v1/system/sms/test', ['to' => '05321234567'], $h)->assertStatus(422)->assertJsonPath('code', 'km_sms_off');
    }

    public function test_a_package_can_register_its_own_provider(): void
    {
        $h = $this->admin();
        SmsProviders::register(new class implements SmsProvider
        {
            public function key(): string
            {
                return 'deneme';
            }

            public function label(): string
            {
                return 'Deneme sağlayıcı';
            }

            public function help(): string
            {
                return '';
            }

            public function fields(): array
            {
                return [['key' => 'token', 'label' => 'Anahtar', 'type' => 'secret', 'required' => true]];
            }

            public function gateway(array $values): SmsGateway
            {
                return new LogGateway('PAKET');
            }
        });
        $this->putJson('/v1/system/sms', ['provider' => 'deneme', 'values' => ['token' => 'gizli-anahtar']], $h)->assertOk()->assertJsonPath('enabled', true);
        $this->postJson('/v1/system/sms/test', ['to' => '05321234567'], $h)->assertOk()->assertJsonPath('sent', 1);
        SmsProviders::reset();
    }

    public function test_netgsm_request_and_errors(): void
    {
        $h = $this->admin();
        $this->netgsm($h);
        Http::fake([
            NetgsmGateway::URL.'/sms/rest/v2/msgheader*' => Http::response(['code' => '00', 'msgheaders' => ['KODMOB', 'KODMOB AS']]),
            NetgsmGateway::URL.'/sms/rest/v2/send' => Http::sequence()
                ->push(['code' => '00', 'jobid' => '17377215342605050417149344', 'description' => 'queued'])
                ->push(['code' => '40', 'description' => 'header'], 406),
        ]);

        $this->postJson('/v1/system/sms/senders', [], $h)->assertOk()->assertJsonPath('senders', ['KODMOB', 'KODMOB AS']);

        $this->postJson('/v1/system/sms/test', ['to' => '0532 123 45 67', 'message' => 'Toplantı “yarın” 15:00’te…'], $h)
            ->assertOk()->assertJsonPath('sent', 1)->assertJsonPath('parts', 1)->assertJsonPath('encoding', 'tr')
            ->assertJsonPath('log.0.recipient', '532*****67')->assertJsonPath('log.0.status', 'sent')->assertJsonPath('log.0.job', '17377215342605050417149344');
        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/sms/rest/v2/send')) {
                return false;
            }

            return $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Basic '.base64_encode('8503020000:api-sifre'))
                && $r['msgheader'] === 'KODMOB' && $r['encoding'] === 'tr' && $r['iysfilter'] === '0'
                && $r['messages'] === [['msg' => 'Toplantı "yarın" 15:00\'te...', 'no' => '5321234567']];
        });

        // NetGSM hata kodu: Türkçe açıklama, kayıtta "gitmedi".
        $this->postJson('/v1/system/sms/test', ['to' => '05321234567'], $h)
            ->assertStatus(422)->assertJsonPath('code', 'km_sms_failed')->assertJsonPath('message', fn ($m) => str_contains($m, 'başlığı') && str_contains($m, '40'));
        $this->assertSame('failed', DB::table('sms_log')->orderByDesc('id')->value('status'));

        // Gönderilmeden reddedilenler: geçersiz numara, emoji, uzun metin.
        $this->postJson('/v1/system/sms/test', ['to' => '0212 123 45 67'], $h)->assertStatus(422)->assertJsonPath('details.fields.to', fn ($m) => str_contains($m, 'cep'));
        $this->postJson('/v1/system/sms/test', ['to' => '05321234567', 'message' => 'Selam 😀'], $h)->assertStatus(422)->assertJsonPath('details.fields.message', fn ($m) => str_contains($m, '😀'));
        $this->postJson('/v1/system/sms/test', ['to' => '05321234567', 'message' => str_repeat('a', 919)], $h)->assertStatus(422);
        Http::assertSentCount(3);
        $this->assertStringNotContainsString('Toplantı', (string) DB::table('sms_log')->get()->toJson(), 'metin saklanmaz');
    }

    public function test_users_sms(): void
    {
        $h = $this->admin();
        $this->getJson('/v1/users/options', $h)->assertJsonPath('messaging.sms', false);
        $ids = $this->people();
        $this->postJson('/v1/users/sms', ['ids' => $ids, 'message' => 'Merhaba'], $h)->assertStatus(422)->assertJsonPath('code', 'km_sms_off');

        $this->netgsm($h);
        $this->getJson('/v1/users/options', $h)->assertJsonPath('messaging.sms', true);
        Http::fake([NetgsmGateway::URL.'/*' => Http::response(['code' => '00', 'jobid' => 'j1'])]);
        $res = $this->postJson('/v1/users/sms', ['ids' => $ids, 'message' => 'Sunucu bakımı 22:00\'de.'], $h)->assertOk()->json();
        $this->assertSame(2, $res['sent'], 'aynı numara bir kez gider');
        $this->assertEqualsCanonicalizing(['Telefonu yok', 'Telefon numarası geçersiz (0212 000 00 00)'], array_column($res['skipped'], 'reason'));
        Http::assertSent(fn (HttpRequest $r) => array_column($r['messages'], 'no') === ['5321111111', '5322222222'] || array_column($r['messages'], 'no') === ['5322222222', '5321111111']);

        $event = DB::table('events')->where('event_type', 'users.sms_sent')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('bakımı', (string) $event->payload, 'mesaj işlem geçmişine yazılmaz');

        // Yetki: km_message_users (Editör'de yok).
        $this->postJson('/v1/users/sms', ['ids' => $ids, 'message' => 'x'], $this->as('editor'))->assertForbidden();
        $this->postJson('/v1/users/sms', ['ids' => [], 'message' => 'x'], $h)->assertStatus(422)->assertJsonPath('details.fields.ids', 'Alıcı seçin.');
    }

    public function test_users_mail(): void
    {
        Mail::fake();
        $h = $this->admin();
        $ids = $this->people();
        $this->postJson('/v1/users/mail', ['ids' => $ids, 'subject' => '', 'message' => ''], $h)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['subject', 'message']]]);

        // Tek kişi: hemen; yanıt gönderene gider.
        $one = $this->userId('ayse');
        $this->postJson('/v1/users/mail', ['ids' => [$one], 'subject' => 'Yeni rol', 'message' => "Merhaba Ayşe,\n\nArtık editörsün."], $h)
            ->assertOk()->assertJsonPath('sent', 1)->assertJsonPath('queued', false);
        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('ayse@kodmob.test') && $m->hasReplyTo('yonetici@kodmob.test') && $m->subjectLine === 'Yeni rol' && end($m->lines) === '— Yönetici');

        // Çok kişi: arka planda (testte kuyruk eşzamanlı), e-postası olmayan atlanır.
        $res = $this->postJson('/v1/users/mail', ['ids' => $ids, 'subject' => 'Duyuru', 'message' => 'Panel yarın kapalı.'], $h)->assertOk()->json();
        $this->assertSame([4, true], [$res['sent'], $res['queued']]);
        $this->assertSame(['E-postası yok'], array_column($res['skipped'], 'reason'));
        $this->assertSame(4, DB::table('mail_log')->where('kind', 'user.message')->where('subject', 'Duyuru')->where('status', 'sent')->count());
        $this->assertSame(1, DB::table('events')->where('event_type', 'users.mail_sent')->where('subject_id', $one)->count());
    }

    /** NetGSM ayarı (deneme hesabı). */
    private function netgsm(array $h): void
    {
        $this->putJson('/v1/system/sms', ['provider' => 'netgsm', 'values' => ['username' => '8503020000', 'password' => 'api-sifre', 'sender' => 'KODMOB']], $h)->assertOk();
    }

    /** Beş kullanıcı: iki geçerli telefon (biri iki kişide), biri telefonsuz, biri sabit hat; biri e-postasız. */
    private function people(): array
    {
        $rows = [
            ['ayse', 'Ayşe', '0532 111 11 11', 'ayse@kodmob.test'],
            ['ali', 'Ali', '+90 532 222 22 22', 'ali@kodmob.test'],
            ['can', 'Can', '05322222222', 'can@kodmob.test'],
            ['ece', 'Ece', '', 'ece@kodmob.test'],
            ['naz', 'Naz', '0212 000 00 00', ''],
        ];
        $ids = [];
        foreach ($rows as [$username, $name, $phone, $email]) {
            $ids[] = (int) User::query()->create(['username' => $username, 'display_name' => $name, 'phone' => $phone, 'email' => $email, 'password' => 'gizli-sifre-123', 'role' => 'editor', 'status' => User::ACTIVE])->id;
        }

        return $ids;
    }
}
