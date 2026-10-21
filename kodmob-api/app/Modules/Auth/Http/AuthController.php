<?php

namespace App\Modules\Auth\Http;

use App\Core\Events\Event;
use App\Core\Events\EventBus;
use App\Core\Exceptions\DomainException;
use App\Core\Http\Controller;
use App\Core\Support\Clock;
use App\Core\Support\UserAgent;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\Handoff;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Auth\Services\TokenService;
use App\Modules\Auth\Services\TwoFactor;
use App\Modules\Security\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Oturum uçları (ön yüz: tv/src/services/auth.js):
 *
 *   POST /auth/token           { username, password, code? } → { token, expires_in, user_* }
 *   POST /auth/token/refresh   (çerez) → aynı biçim (yenileme jetonu tek kullanımlık)
 *   POST /auth/token/revoke    (Bearer) → çıkış
 *
 * Yenileme jetonu yanıt gövdesinde değil `km_refresh` çerezindedir: HttpOnly (JavaScript
 * okuyamaz; bir XSS uzun ömürlü oturumu çalamaz), SameSite=Strict, yalnızca /api/v1/auth yolunda,
 * HTTPS'te Secure. Erişim jetonu (1 sa) panelde yalnızca bellekte tutulur. Geçiş için yenileme
 * gövdedeki `refresh_token`'ı da kabul eder (eski panel sekmeleri).
 *
 * Kullanıcı adı ya da e-posta ile giriş yapılabilir. Pasif hesap ve panele girme
 * yetkisi olmayan rol de "hatalı" yanıtı alır (hesabın varlığı sızdırılmaz);
 * gerçek neden güvenlik kaydına yazılır.
 */
final class AuthController extends Controller
{
    public const COOKIE = 'km_refresh';

    public function __construct(private readonly TokenService $tokens, private readonly LoginThrottle $throttle) {}

    public function token(Request $request): JsonResponse
    {
        $body = $this->input($request);
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new DomainException('jwt_auth_bad_request', 'Kullanıcı adı ve şifre gerekli.', 400);
        }
        $this->throttle->ensureNotLocked($username, $request);

        // Kullanıcı adı ve e-posta küçük harfle saklanır; arama da öyle (PostgreSQL'de eşitlik harfe duyarlı).
        $login = mb_strtolower($username);
        $user = User::query()->where('username', $login)->orWhere('email', $login)->first();
        if (! $user || ! Hash::check($password, $user->password)) {
            $this->throttle->failed($username, $request, $user ? 'Şifre hatalı' : 'Kullanıcı yok');
            throw new DomainException('jwt_auth_failed', 'Kullanıcı adı veya şifre hatalı.', 403);
        }
        if (! $user->canAccessPanel()) {
            $this->throttle->failed($username, $request, $user->isActive() ? 'Rolün panel yetkisi yok' : 'Hesap pasif');
            SecurityLog::record(SecurityLog::LOGIN_DISABLED, $request, $user->isActive() ? 'Rol: '.$user->role : 'Pasif hesap', (string) $user->username, (int) $user->id);
            throw new DomainException('jwt_auth_failed', 'Kullanıcı adı veya şifre hatalı.', 403);
        }
        // İki adımlı doğrulama: şifre doğruysa kod istenir (panel aynı isteği kodla yineler).
        // Kod istemek hatalı deneme sayılmaz; yanlış kod sayılır (kilit).
        if (TwoFactor::enabled($user)) {
            $code = trim((string) ($body['code'] ?? ''));
            if ($code === '') {
                return $this->noStore(['code' => 'otp_required', 'message' => 'Doğrulama uygulamanızdaki 6 haneli kodu girin.']);
            }
            $method = TwoFactor::check($user, $code);
            if ($method === null) {
                $this->throttle->failed($username, $request, 'İki adımlı doğrulama kodu hatalı');
                throw new DomainException('jwt_auth_otp_invalid', 'Doğrulama kodu hatalı ya da süresi geçmiş.', 403);
            }
            if ($method === 'recovery') {
                SecurityLog::record(SecurityLog::LOGIN_RECOVERY, $request, 'Kalan yedek kod: '.TwoFactor::remainingRecoveryCodes($user), (string) $user->username, (int) $user->id);
            }
        }
        $this->throttle->clear($username, $request);
        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
        }
        $user->last_login_at = Clock::now();
        $user->save();

        $response = $this->tokens->response($user, $request);
        (new EventBus)->publish(new Event('auth.login', 'user', (int) $user->id, ['device' => UserAgent::describe((string) $request->userAgent())], [], (int) $user->id, null, null, (string) $user->display_name));

        return $this->withSession($response, $request);
    }

    /**
     * Projeler arası geçiş (Auth\Services\Handoff): ana projenin verdiği tek kullanımlık anahtarla, bu projedeki
     * aynı e-postalı yöneticinin oturumu açılır. Anahtar geçersizse 403 (panel giriş ekranını gösterir).
     */
    public function handoff(Request $request): JsonResponse
    {
        ['user' => $user, 'from' => $from] = Handoff::redeem((string) ($this->input($request)['token'] ?? ''));
        $user->last_login_at = Clock::now();
        $user->save();
        $response = $this->tokens->response($user, $request);
        (new EventBus)->publish(new Event('auth.handoff', 'user', (int) $user->id, ['from' => $from, 'device' => UserAgent::describe((string) $request->userAgent())], [], (int) $user->id, null, null, (string) $user->display_name));

        return $this->withSession($response, $request);
    }

    public function refresh(Request $request): JsonResponse
    {
        $refresh = (string) ($request->cookie(self::COOKIE) ?: ($this->input($request)['refresh_token'] ?? ''));
        if ($refresh === '') {
            throw new DomainException('km_session_expired', 'Oturum bulunamadı.', 401);
        }

        return $this->withSession($this->tokens->rotate($refresh, $request), $request);
    }

    public function revoke(Request $request): JsonResponse
    {
        $this->tokens->revoke((array) $request->attributes->get('jwt', []));
        $user = $request->user();
        (new EventBus)->publish(new Event('auth.logout', 'user', (int) $user->id, ['device' => UserAgent::describe((string) $request->userAgent())], [], (int) $user->id, null, null, (string) $user->display_name));

        return $this->noStore(['code' => 'jwt_auth_revoked', 'message' => 'Oturum kapatıldı.'])->withCookie(self::cookie('', $request, true));
    }

    /** Yanıt: yenileme jetonu gövdeden çıkar, HttpOnly çereze yazılır. */
    private function withSession(array $data, Request $request): JsonResponse
    {
        $refresh = (string) ($data['refresh_token'] ?? '');
        unset($data['refresh_token']);

        return $this->noStore($data)->withCookie(self::cookie($refresh, $request));
    }

    public static function cookie(string $value, Request $request, bool $forget = false): Cookie
    {
        $expires = $forget ? 1 : Carbon::now()->addDays(max(1, (int) config('jwt.refresh_ttl_days', 30)));

        return new Cookie(self::COOKIE, $value, $expires, '/api/v1/auth', null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT);
    }

    private function noStore(array $data): JsonResponse
    {
        return (new JsonResponse($data))->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')->header('Pragma', 'no-cache');
    }
}
