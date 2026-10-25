<?php

namespace Tests\Feature;

use App\Core\Mail\SiteMail;
use App\Core\Settings\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Security\SecurityAudit;
use App\Modules\Security\SecurityModule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Sızmış şifre, yeni cihaz uyarısı, zorunlu iki adımlı doğrulama, güvenlik puanı. */
class SecurityFeaturesTest extends TestCase
{
    private function login(string $agent): TestResponse
    {
        return $this->withHeaders(['User-Agent' => $agent])->postJson('/v1/auth/token', ['username' => 'yonetici', 'password' => 'gizli-sifre-123']);
    }

    public function test_breached_password_is_rejected_and_outage_is_tolerated(): void
    {
        $admin = $this->admin();
        SettingsService::instance()->set(SecurityModule::BREACHED_PASSWORDS, true);
        $suffix = substr(strtoupper(sha1('Sizmis-sifre-2026')), 5);
        // Tek sahte servis, davranışı değişkene göre: sızıntı listesi, kesinti.
        $mode = 'list';
        $calls = 0;
        Http::fake(['api.pwnedpasswords.com/range/*' => function () use (&$mode, &$calls, $suffix) {
            $calls++;

            return $mode === 'down' ? Http::response('', 503) : Http::response("0123456789ABCDEF0123456789ABCDEF012:3
{$suffix}:4821
");
        }]);
        $this->postJson('/v1/users', ['username' => 'yeni1', 'email' => 'y1@ornek.test', 'display_name' => 'Yeni', 'role' => 'editor', 'password' => 'Sizmis-sifre-2026'], $admin)
            ->assertStatus(422)->assertJsonPath('details.fields.password', fn ($m) => str_contains($m, 'sızıntı'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/range/'.substr(strtoupper(sha1('Sizmis-sifre-2026')), 0, 5)));

        // Servis yanıt vermezse şifre kabul edilir; ayar kapalıysa sorgu yapılmaz.
        $mode = 'down';
        $this->postJson('/v1/users', ['username' => 'yeni2', 'email' => 'y2@ornek.test', 'display_name' => 'Yeni', 'role' => 'editor', 'password' => 'Sizmis-sifre-2026'], $admin)->assertCreated();
        SettingsService::instance()->set(SecurityModule::BREACHED_PASSWORDS, false);
        $before = $calls;
        $this->postJson('/v1/users', ['username' => 'yeni3', 'email' => 'y3@ornek.test', 'display_name' => 'Yeni', 'role' => 'editor', 'password' => 'Sizmis-sifre-2026'], $admin)->assertCreated();
        $this->assertSame($before, $calls, 'ayar kapalıyken sorgu yok');
    }

    public function test_new_device_sends_an_alert_but_first_login_does_not(): void
    {
        Http::fake();
        Mail::fake();
        $this->admin();
        $this->login('Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36')->assertOk();
        Mail::assertNotSent(SiteMail::class);
        $this->login('Mozilla/5.0 (Windows NT 10.0) Chrome/131.0 Safari/537.36')->assertOk();
        Mail::assertNotSent(SiteMail::class, 'tarayıcı güncellemesi yeni cihaz değil');
        $this->login('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile Safari/604.1')->assertOk();
        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('yonetici@kodmob.test') && str_contains($m->subjectLine, 'yeni bir cihaz'));
    }

    public function test_required_two_factor_limits_the_panel_to_profile(): void
    {
        Http::fake();
        $admin = $this->admin();
        $editor = $this->as('editor');
        SettingsService::instance()->set(SecurityModule::REQUIRE_2FA, 'admins');
        $this->getJson('/v1/admin/overview', $admin)->assertStatus(403)->assertJsonPath('code', 'km_two_factor_required');
        $this->getJson('/v1/me', $admin)->assertOk()->assertJsonPath('two_factor.required', true);
        $this->getJson('/v1/admin/overview', $editor)->assertOk();

        SettingsService::instance()->set(SecurityModule::REQUIRE_2FA, 'all');
        $this->getJson('/v1/admin/overview', $editor)->assertStatus(403);
        // Doğrulama açılınca serbest.
        $user = User::query()->where('username', 'editor')->first();
        $user->two_factor_secret = 'JBSWY3DPEHPK3PXP';
        $user->two_factor_confirmed_at = now();
        $user->save();
        $this->getJson('/v1/admin/overview', $editor)->assertOk();
    }

    public function test_security_score_reflects_the_audit(): void
    {
        Http::fake();
        $score = SecurityAudit::score();
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
        $this->getJson('/v1/security', $this->admin())->assertOk()->assertJsonPath('score', $score);
    }
}
