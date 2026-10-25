<?php

namespace Tests\Feature;

use App\Core\Settings\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Security\SecurityModule;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Güvenlik: saldırı kalıbı süzgeci, IP engelleri, kilitli girişler, denetim. */
class SecurityTest extends TestCase
{
    public function test_waf_blocks_visitor_requests_and_logs(): void
    {
        $this->getJson('/v1/page-data?route=home&locale=tr&q='.urlencode("1' UNION SELECT password FROM users"))
            ->assertForbidden()->assertJsonPath('code', 'km_request_blocked');
        $this->getJson('/v1/search-index?locale=../../etc/passwd')->assertForbidden();
        $this->getJson('/v1/page-data?route=home&locale=tr')->assertOk();

        // Panelde arama kutusuna yazılan metin engellenmez.
        $this->getJson('/v1/history?q='.urlencode('../eski'), $this->admin())->assertOk();

        $events = $this->getJson('/v1/security/events', $this->admin())->assertOk();
        $events->assertJsonPath('total', 2)->assertJsonPath('items.0.type', 'waf_blocked')->assertJsonPath('facets.type.waf_blocked', 2);
    }

    public function test_setup_token_guesses_are_limited(): void
    {
        config(['kodmob.setup_token' => str_repeat('a1', 16)]);
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/v1/setup?token=yanlis')->assertNotFound();
        }
        $this->getJson('/v1/setup?token=yanlis')->assertStatus(429);
        // Doğru jeton sınırdan etkilenmez.
        $this->get('/v1/setup?token='.str_repeat('a1', 16))->assertOk();
    }

    public function test_ip_blocks(): void
    {
        $admin = $this->admin();
        $this->postJson('/v1/security/blocks', ['ip' => '127.0.0.1'], $admin)->assertStatus(422);
        $this->postJson('/v1/security/blocks', ['ip' => 'yanlis'], $admin)->assertStatus(422);
        $id = $this->postJson('/v1/security/blocks', ['ip' => '203.0.113.0/24', 'reason' => 'Tarama'], $admin)->assertCreated()->json('id');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/v1/site')->assertForbidden()->assertJsonPath('code', 'km_ip_blocked');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->getJson('/v1/site')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

        $this->getJson('/v1/security/blocks', $admin)->assertJsonPath('items.0.hits', 1)->assertJsonPath('items.0.source_label', 'Elle');
        $this->deleteJson("/v1/security/blocks/$id", [], $admin)->assertNoContent();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/v1/site')->assertOk();
    }

    public function test_lockouts_are_listed_and_unlocked(): void
    {
        User::query()->create(['username' => 'test', 'email' => 'test@kodmob.test', 'display_name' => 'Test', 'password' => 'Test-sifre-2026', 'role' => User::ADMIN]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => 'yanlis'])->assertStatus(403);
        }
        $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => 'Test-sifre-2026'])->assertStatus(429);

        $admin = $this->admin();
        $lockouts = $this->getJson('/v1/security/lockouts', $admin)->assertOk()->json('items');
        $this->assertSame([['user', 'test', '127.0.0.1']], array_map(fn ($l) => [$l['kind'], $l['username'], $l['ip']], $lockouts));

        $this->postJson('/v1/security/lockouts/unlock', ['username' => 'test', 'ip' => '127.0.0.1'], $admin)->assertOk();
        $this->postJson('/v1/auth/token', ['username' => 'test', 'password' => 'Test-sifre-2026'])->assertOk();
        $this->assertSame(5, DB::table('security_events')->where('type', 'login_failed')->count());
        $this->assertSame(1, DB::table('security_events')->where('type', 'login_locked')->count());
    }

    public function test_hostile_ip_is_blocked_automatically(): void
    {
        SettingsService::instance()->set(SecurityModule::AUTO_BLOCK, 3);
        $ip = ['REMOTE_ADDR' => '192.0.2.50'];
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables($ip)->getJson('/v1/site?x=<script>')->assertForbidden();
        }
        $this->withServerVariables($ip)->getJson('/v1/site')->assertForbidden()->assertJsonPath('code', 'km_ip_blocked');
        $this->assertSame('auto', DB::table('ip_blocks')->where('ip', '192.0.2.50')->value('source'));
    }

    public function test_overview_and_audit(): void
    {
        $overview = $this->getJson('/v1/security', $this->admin())->assertOk();
        $overview->assertJsonStructure(['stats' => ['sessions', 'users', 'failed_logins', 'blocked_requests', 'ip_blocks', 'lockouts'], 'audit', 'key' => ['version', 'fingerprint'], 'types']);
        $keys = array_column($overview->json('audit'), 'key');
        foreach (['debug', 'https', 'hsts', 'waf', 'jwt_secret', 'setup_token', 'admins', 'passwords'] as $key) {
            $this->assertContains($key, $keys);
        }
    }
}
