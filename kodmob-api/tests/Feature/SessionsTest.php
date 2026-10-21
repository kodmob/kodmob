<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use Tests\TestCase;

/** Oturumlar (cihazlar): jwt-kodmob'daki oturum yönetiminin karşılığı ve fazlası. */
class SessionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        User::query()->create(['username' => 'test', 'email' => 'test@kodmob.test', 'display_name' => 'Test', 'password' => 'Test-sifre-2026', 'role' => User::ADMIN, 'status' => User::ACTIVE]);
    }

    private function login(string $agent): array
    {
        $r = $this->withHeader('User-Agent', $agent)->postJson('/v1/auth/token', ['username' => 'test', 'password' => 'Test-sifre-2026'])->assertOk();

        return [['Authorization' => 'Bearer '.$r->json('token'), 'User-Agent' => $agent], $r->getCookie('km_refresh', false)->getValue()];
    }

    public function test_devices_are_listed_and_ended(): void
    {
        [$laptop] = $this->login('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36');
        [$phone, $phoneRefresh] = $this->login('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile Safari/604.1');

        $list = $this->getJson('/v1/me/sessions', $laptop)->assertOk()->json('items');
        $this->assertCount(2, $list);
        $current = collect($list)->firstWhere('current', true);
        $this->assertSame('Chrome · Windows', $current['device']);
        $this->assertSame('Safari · iPhone', collect($list)->firstWhere('current', false)['device']);

        // Yenileme oturumu değiştirmez (aynı satır, yeni jeton).
        $rotated = $this->withCredentials()->withUnencryptedCookie('km_refresh', $phoneRefresh)->postJson('/v1/auth/token/refresh')->assertOk();
        $this->assertCount(2, $this->getJson('/v1/me/sessions', $laptop)->json('items'));
        $phone = ['Authorization' => 'Bearer '.$rotated->json('token')];

        $this->postJson('/v1/me/sessions/end-others', [], $laptop)->assertOk()->assertJsonPath('ended', 1);
        $this->getJson('/v1/me', $phone)->assertStatus(401)->assertJsonPath('code', 'km_session_ended');
        $this->getJson('/v1/me', $laptop)->assertOk();
        $this->assertSame('sessions.ended_others', $this->getJson('/v1/history', $laptop)->json('items.0.type'));
    }

    public function test_password_change_keeps_this_device_only(): void
    {
        [$a] = $this->login('Firefox/131.0 (X11; Linux x86_64)');
        [$b] = $this->login('Edg/130.0 Windows');

        $this->putJson('/v1/me/password', ['current_password' => 'yanlis', 'password' => 'Yeni-sifre-2026', 'password_confirmation' => 'Yeni-sifre-2026'], $a)
            ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['current_password']]]);
        $this->putJson('/v1/me/password', ['current_password' => 'Test-sifre-2026', 'password' => 'test12345678', 'password_confirmation' => 'test12345678'], $a)
            ->assertStatus(422);
        $this->putJson('/v1/me/password', ['current_password' => 'Test-sifre-2026', 'password' => 'Yeni-sifre-2026', 'password_confirmation' => 'Yeni-sifre-2026'], $a)
            ->assertOk()->assertJsonPath('ended_sessions', 1);
        $this->getJson('/v1/me', $a)->assertOk();
        $this->getJson('/v1/me', $b)->assertStatus(401);
        $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => 'Yeni-sifre-2026'])->assertOk();
    }

    public function test_admin_ends_sessions_and_rotates_key(): void
    {
        [$admin] = $this->login('Chrome/130.0 Windows');
        $editor = $this->as('editor');
        $sessions = $this->getJson('/v1/security/sessions', $admin)->assertOk()->json('items');
        $this->assertCount(2, $sessions);
        $editorSession = collect($sessions)->firstWhere('user_name', 'Editor');
        $this->deleteJson('/v1/security/sessions/'.$editorSession['id'], [], $admin)->assertOk();
        $this->getJson('/v1/me', $editor)->assertStatus(401);

        $before = $this->getJson('/v1/security', $admin)->assertOk()->json('key');
        $rotated = $this->postJson('/v1/security/key/rotate', [], $admin)->assertOk();
        $this->assertNotSame($before['fingerprint'], $rotated->json('fingerprint'));
        $this->getJson('/v1/me', $admin)->assertStatus(401);
        $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => 'Test-sifre-2026'])->assertOk();
    }
}
