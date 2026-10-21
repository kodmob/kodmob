<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        User::query()->create(['username' => 'test', 'email' => 'test@kodmob.test', 'display_name' => 'Test', 'password' => ' boşluklu şifre ', 'role' => User::ADMIN]);
    }

    public function test_login_returns_jwt_kodmob_shape(): void
    {
        $response = $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => ' boşluklu şifre ']);

        $response->assertOk()->assertJsonStructure(['token', 'expires_in', 'user_display_name', 'user_email', 'user_nicename', 'user_id'])->assertJsonMissingPath('refresh_token');
        // Yenileme jetonu JavaScript'in okuyamadığı çerezde.
        $cookie = $response->getCookie('km_refresh', false);
        $this->assertNotNull($cookie);
        $this->assertSame(64, strlen($cookie->getValue()));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api/v1/auth', $cookie->getPath());
        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$response->json('token')])->assertOk()->assertJsonPath('username', 'test');
    }

    public function test_login_by_email_and_wrong_password(): void
    {
        $this->postJson('/v1/auth/token', ['username' => 'test@kodmob.test', 'password' => ' boşluklu şifre '])->assertOk();
        // Büyük harfle yazılsa da girer (PostgreSQL'de eşitlik harfe duyarlı; arama küçük harfle).
        $this->postJson('/v1/auth/token', ['username' => 'TEST', 'password' => ' boşluklu şifre '])->assertOk();
        $this->postJson('/v1/auth/token', ['username' => 'Test@Kodmob.Test', 'password' => ' boşluklu şifre '])->assertOk();
        $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => 'boşluklu şifre'])->assertStatus(403)->assertJsonPath('code', 'jwt_auth_failed');
    }

    public function test_lockout_after_repeated_failures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => 'yanlış'])->assertStatus(403);
        }
        $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => ' boşluklu şifre '])
            ->assertStatus(429)->assertJsonPath('code', 'jwt_auth_too_many_attempts');
    }

    public function test_refresh_token_is_single_use_and_revoke_ends_session(): void
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        $refresh = fn (string $token) => $this->withCredentials()->withUnencryptedCookie('km_refresh', $token)->postJson('/v1/auth/token/refresh');
        $first = $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => ' boşluklu şifre '])->getCookie('km_refresh', false)->getValue();

        $second = $refresh($first)->assertOk()->getCookie('km_refresh', false)->getValue();
        $this->assertNotSame($first, $second);
        // Yanıtı yolda kaybolan istemci: önceki jeton 60 sn daha geçer (yeniden döndürür).
        Carbon::setTestNow('2026-10-16 10:00:30');
        $third = $refresh($first)->assertOk()->getCookie('km_refresh', false)->getValue();
        // Süre geçince önceki jetonlar reddedilir; güncel olan geçer.
        Carbon::setTestNow('2026-10-16 10:01:31');
        $refresh($first)->assertStatus(401);
        $refresh($second)->assertStatus(401);
        $fourth = $refresh($third)->assertOk()->getCookie('km_refresh', false)->getValue();
        // Eski panel sekmeleri (çerezsiz): gövdedeki jeton da kabul edilir (geçiş).
        $this->withCredentials = false;
        $third = $this->postJson('/v1/auth/token/refresh', ['refresh_token' => $fourth])->assertOk();
        Carbon::setTestNow();

        $headers = ['Authorization' => 'Bearer '.$third->json('token')];
        $revoked = $this->postJson('/v1/auth/token/revoke', [], $headers)->assertOk();
        $this->assertSame('', (string) $revoked->getCookie('km_refresh', false)?->getValue(), 'çıkışta çerez silinir');
        $this->getJson('/v1/me', $headers)->assertStatus(401)->assertJsonPath('code', 'km_session_ended');
    }

    public function test_panel_requires_session(): void
    {
        $this->getJson('/v1/admin/overview')->assertStatus(401)->assertJsonStructure(['code', 'message', 'details']);
    }
}
