<?php

namespace App\Modules\Members;

use App\Core\Settings\SettingsService;
use App\Core\Support\Secret;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google ile giriş (OAuth 2.0 yetki kodu akışı, OpenID Connect): Proje Ayarları › Üyelik'te istemci kimliği ve sırrı
 * girilince kayıt ve giriş formunda "Google ile devam et" görünür. Google Cloud Console'da yetkili yönlendirme adresi:
 * `<site>/api/v1/members/google/callback`.
 *
 * - `state` rastgele ve kısa ömürlü çerezde: geri dönüşte eşleşmezse giriş yapılmaz (CSRF).
 * - Kimlik jetonu sunucunun kendi isteğiyle (TLS, istemci sırrıyla) Google'dan alınır; bu yüzden imzası ayrıca
 *   denetlenmez (Google'ın belgesi). Kitle (`aud`) bizim istemci kimliği, veren Google, e-posta doğrulanmış olmalı.
 */
final class GoogleLogin
{
    public const CLIENT_ID = 'members.google_client_id';

    public const CLIENT_SECRET = 'members.google_client_secret';

    public const STATE_COOKIE = 'km_member_state';

    public static function enabled(): bool
    {
        $settings = SettingsService::instance();

        return trim((string) $settings->get(self::CLIENT_ID)) !== '' && Secret::open($settings->get(self::CLIENT_SECRET)) !== '';
    }

    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/members/google/callback';
    }

    public static function authorizeUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => trim((string) SettingsService::instance()->get(self::CLIENT_ID)),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /** @return array{sub:string,email:string,name:string} */
    public static function exchange(string $code): array
    {
        $settings = SettingsService::instance();
        $clientId = trim((string) $settings->get(self::CLIENT_ID));
        $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => Secret::open($settings->get(self::CLIENT_SECRET)),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        $idToken = (string) $response->json('id_token', '');
        $parts = explode('.', $idToken);
        if (! $response->successful() || count($parts) !== 3) {
            throw new RuntimeException('Google kimlik jetonu vermedi.');
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
        $issuer = (string) ($claims['iss'] ?? '');
        if (($claims['aud'] ?? '') !== $clientId || ! in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)
            || (int) ($claims['exp'] ?? 0) < time() || empty($claims['sub']) || empty($claims['email']) || ($claims['email_verified'] ?? false) !== true) {
            throw new RuntimeException('Google kimlik jetonu geçersiz.');
        }

        return ['sub' => (string) $claims['sub'], 'email' => (string) $claims['email'], 'name' => (string) ($claims['name'] ?? '')];
    }
}
