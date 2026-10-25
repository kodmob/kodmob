<?php

namespace Tests\Feature;

use App\Core\Mail\SiteMail;
use App\Core\Settings\SettingsService;
use App\Core\Support\Secret;
use App\Modules\Members\GoogleLogin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Site üyeliği: kayıt, doğrulama, giriş, şifre yenileme, profil, hesabı silme, Google ile giriş, paneldeki üyeler. */
class MembersTest extends TestCase
{
    private const ENV = 'blog,briefs,members';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
        $this->withCredentials();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000A:1\n", 200)]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    private function register(array $over = []): TestResponse
    {
        return $this->postJson('/v1/members/register', array_replace([
            'name' => 'Ayşe Yılmaz', 'email' => 'Ayse@Ornek.test', 'password' => 'Guclu-sifre-2026', 'consent' => true, 'website' => '',
            'started_at' => (int) (microtime(true) * 1000) - 10000, 'locale' => 'tr',
        ], $over));
    }

    /** Son giden e-postanın düğme adresindeki anahtar. */
    private function tokenFromMail(string $param): string
    {
        $token = '';
        Mail::assertSent(SiteMail::class, function (SiteMail $mail) use ($param, &$token) {
            parse_str((string) parse_url((string) ($mail->action['url'] ?? ''), PHP_URL_QUERY), $query);
            if (! empty($query[$param])) {
                $token = (string) $query[$param];
            }

            return true;
        });

        return $token;
    }

    public function test_register_verify_login_and_profile(): void
    {
        Mail::fake();
        $this->register(['website' => 'http://spam'])->assertStatus(422)->assertJsonPath('code', 'km_member_rejected');
        $this->register(['consent' => false, 'password' => 'kisa'])->assertStatus(422)
            ->assertJsonPath('details.fields.consent', 'Üye olmak için onay kutusunu işaretleyin.')
            ->assertJsonStructure(['details' => ['fields' => ['password']]]);

        $res = $this->register()->assertOk()->assertJsonPath('member.email', 'ayse@ornek.test')->assertJsonPath('member.verified', false);
        $cookie = $res->getCookie('km_member', false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api/v1/members', $cookie->getPath());
        $this->assertArrayNotHasKey('id', $res->json('member'), 'iç alan ziyaretçiye gitmez');
        $this->register()->assertStatus(422)->assertJsonPath('details.fields.email', fn ($m) => str_contains($m, 'zaten'));

        // E-postadaki bağlantı: doğrular.
        $token = $this->tokenFromMail('dogrula');
        $this->assertSame(48, strlen($token));
        $this->withUnencryptedCookie('km_member', $cookie->getValue());
        $this->getJson('/v1/members/me')->assertJsonPath('member.name', 'Ayşe Yılmaz')->assertJsonPath('google', false);
        $this->postJson('/v1/members/verify', ['token' => $token])->assertOk()->assertJsonPath('member.verified', true);
        $this->postJson('/v1/members/verify', ['token' => $token])->assertStatus(422);

        $this->putJson('/v1/members/me', ['name' => 'Ayşe Y.'])->assertOk()->assertJsonPath('member.name', 'Ayşe Y.');
        $this->getJson('/v1/members/orders')->assertOk()->assertJsonPath('items', []);

        // Çıkış, hatalı ve doğru giriş.
        $this->postJson('/v1/members/logout')->assertOk();
        $this->assertSame(0, DB::table('member_sessions')->count());
        $this->postJson('/v1/members/login', ['email' => 'ayse@ornek.test', 'password' => 'yanlis'])->assertStatus(422)->assertJsonPath('details.fields.password', 'E-posta ya da şifre hatalı.');
        $this->postJson('/v1/members/login', ['email' => 'AYSE@ornek.test', 'password' => 'Guclu-sifre-2026'])->assertOk()->assertCookie('km_member', null, false);
    }

    public function test_forgot_reset_and_delete_account(): void
    {
        Mail::fake();
        $this->register()->assertOk();
        $this->postJson('/v1/members/forgot', ['email' => 'yok@ornek.test'])->assertOk();
        Mail::fake();
        $this->postJson('/v1/members/forgot', ['email' => 'ayse@ornek.test'])->assertOk();
        $token = $this->tokenFromMail('sifirla');

        $reset = $this->postJson('/v1/members/reset', ['token' => $token, 'password' => 'Yeni-sifre-2026'])->assertOk()->assertJsonPath('member.verified', true);
        $this->assertSame(1, DB::table('member_sessions')->count(), 'eski oturumlar kapandı, yenisi açıldı');
        $this->postJson('/v1/members/reset', ['token' => $token, 'password' => 'Baska-sifre-2026'])->assertStatus(422);

        $this->withUnencryptedCookie('km_member', $reset->getCookie('km_member', false)->getValue());
        $this->postJson('/v1/members/delete', ['password' => 'Yeni-sifre-2026', 'confirm' => 'hayır'])->assertStatus(422)->assertJsonPath('details.fields.confirm', 'Onaylamak için SİL yazın.');
        $this->postJson('/v1/members/delete', ['password' => 'Yeni-sifre-2026', 'confirm' => 'sil'])->assertOk();
        $this->assertSame(0, DB::table('members')->count());
        $this->getJson('/v1/members/me')->assertJsonPath('member', null);
    }

    public function test_google_login_creates_a_verified_member(): void
    {
        $settings = SettingsService::instance();
        $settings->set(GoogleLogin::CLIENT_ID, 'istemci.apps.googleusercontent.com');
        $settings->set(GoogleLogin::CLIENT_SECRET, Secret::seal('gizli'));
        $this->getJson('/v1/members/me')->assertJsonPath('google', true);

        $start = $this->get('/v1/members/google?locale=tr')->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $start->headers->get('Location'));
        $state = $start->getCookie('km_member_state', false)->getValue();

        $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'istemci.apps.googleusercontent.com', 'sub' => 'g-123', 'email' => 'mehmet@ornek.test', 'email_verified' => true, 'name' => 'Mehmet', 'exp' => time() + 600];
        $jwt = 'e30.'.rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=').'.imza';
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['id_token' => $jwt]), 'api.pwnedpasswords.com/*' => Http::response('')]);

        // Yanlış state: giriş yok.
        $this->withUnencryptedCookie('km_member_state', $state);
        $this->get('/v1/members/google/callback?state=baska&code=kod')->assertRedirectContains('google=hata');
        $done = $this->get('/v1/members/google/callback?state='.urlencode($state).'&code=kod')->assertRedirect();
        $this->assertStringNotContainsString('google=hata', (string) $done->headers->get('Location'));
        $this->assertNotNull($done->getCookie('km_member', false));
        $member = DB::table('members')->first();
        $this->assertSame(['mehmet@ornek.test', 'g-123'], [$member->email, $member->google_id]);
        $this->assertNotNull($member->email_verified_at);
        $this->assertNull($member->password);
    }

    public function test_panel_lists_and_manages_members_with_capabilities(): void
    {
        Mail::fake();
        $this->register()->assertOk();
        $admin = $this->admin();
        $list = $this->getJson('/v1/admin/members?search=ayse', $admin)->assertOk()->assertJsonPath('total', 1);
        $id = $list->json('items.0.id');
        $this->getJson('/v1/admin/members', $this->as('author'))->assertForbidden();

        $this->putJson("/v1/admin/members/{$id}", ['status' => 'disabled'], $admin)->assertOk();
        $this->assertSame(0, DB::table('member_sessions')->count(), 'kapatılan üyenin oturumu düşer');
        $this->postJson('/v1/members/login', ['email' => 'ayse@ornek.test', 'password' => 'Guclu-sifre-2026'])->assertStatus(403)->assertJsonPath('code', 'km_member_disabled');
        $this->deleteJson("/v1/admin/members/{$id}", [], $admin)->assertOk();
        $this->assertSame(0, DB::table('members')->count());
    }
}
