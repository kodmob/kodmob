<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\TwoFactor;
use App\Modules\Security\SecurityAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** İki adımlı doğrulama: TOTP, kurulum ve onay, kodla giriş, tekrar koruması, yedek kod, sıfırlama. */
class TwoFactorTest extends TestCase
{
    private const PASSWORD = 'gizli-sifre-123';

    private function now(string $secret): string
    {
        return TwoFactor::code($secret, intdiv(Carbon::now()->getTimestamp(), 30));
    }

    private function login(array $extra = []): TestResponse
    {
        return $this->postJson('/v1/auth/token', ['username' => 'yonetici', 'password' => self::PASSWORD] + $extra);
    }

    public function test_codes_match_rfc_6238(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
        $this->assertSame(['287082', '081804', '279037'], [TwoFactor::code($secret, 1), TwoFactor::code($secret, intdiv(1111111109, 30)), TwoFactor::code($secret, intdiv(2000000000, 30))]);
    }

    public function test_enable_login_with_code_recovery_and_disable(): void
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        $h = $this->admin();

        $this->postJson('/v1/me/two-factor/setup', ['password' => 'yanlis'], $h)->assertStatus(422)->assertJsonPath('details.fields.password', 'Şifreniz hatalı.');
        $setup = $this->postJson('/v1/me/two-factor/setup', ['password' => self::PASSWORD], $h)->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_url']);
        $raw = (string) DB::table('users')->where('username', 'yonetici')->value('two_factor_secret');
        $this->assertStringNotContainsString($setup['secret'], $raw, 'anahtar şifreli saklanır');
        $this->assertSame($setup['secret'], User::query()->where('username', 'yonetici')->first()->two_factor_secret);
        $this->login()->assertOk()->assertJsonMissingPath('code');

        $this->postJson('/v1/me/two-factor/confirm', ['code' => '000000'], $h)->assertStatus(422);
        $codes = $this->postJson('/v1/me/two-factor/confirm', ['code' => $this->now($setup['secret'])], $h)->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->getJson('/v1/me', $h)->assertJsonPath('two_factor.enabled', true)->assertJsonPath('two_factor.recovery_left', 8);

        // Giriş: önce kod istenir (hatalı deneme sayılmaz), yanlış kod reddedilir.
        $this->login()->assertOk()->assertJsonPath('code', 'otp_required')->assertJsonMissingPath('token');
        $this->login(['code' => '123456'])->assertStatus(403)->assertJsonPath('code', 'jwt_auth_otp_invalid');
        // Onayda kullanılan kod (aynı zaman adımı) ikinci kez geçmez.
        $this->login(['code' => $this->now($setup['secret'])])->assertStatus(403);
        Carbon::setTestNow('2026-10-16 10:00:31');
        $this->assertNotEmpty($this->login(['code' => $this->now($setup['secret'])])->assertOk()->json('token'));

        // Yedek kod bir kez geçer.
        $this->assertNotEmpty($this->login(['code' => strtoupper($codes[0])])->assertOk()->json('token'));
        $this->login(['code' => $codes[0]])->assertStatus(403);
        $this->getJson('/v1/me', $h)->assertJsonPath('two_factor.recovery_left', 7);

        $this->postJson('/v1/me/two-factor/disable', ['password' => self::PASSWORD], $h)->assertOk()->assertJsonPath('enabled', false);
        $this->assertNotEmpty($this->login()->assertOk()->json('token'));
        Carbon::setTestNow();
    }

    public function test_admin_resets_a_users_two_factor_and_audit_warns(): void
    {
        $admin = $this->admin();
        $editor = $this->as('editor');
        $secret = $this->postJson('/v1/me/two-factor/setup', ['password' => self::PASSWORD], $editor)->json('secret');
        $this->postJson('/v1/me/two-factor/confirm', ['code' => $this->now($secret)], $editor)->assertOk();
        $id = $this->userId('editor');
        $this->getJson("/v1/users/{$id}", $admin)->assertJsonPath('two_factor', true);

        $this->postJson("/v1/users/{$id}/two-factor/reset", [], $editor)->assertForbidden();
        $this->postJson("/v1/users/{$id}/two-factor/reset", [], $admin)->assertOk()->assertJsonPath('two_factor', false);
        $this->getJson('/v1/me', $editor)->assertStatus(401);

        $check = collect(SecurityAudit::run())->firstWhere('key', 'two_factor');
        $this->assertSame('warning', $check['status']);
        $this->assertStringContainsString('Yönetici', $check['detail']);
    }
}
