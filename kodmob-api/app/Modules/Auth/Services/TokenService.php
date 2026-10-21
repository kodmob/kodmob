<?php

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\DomainException;
use App\Core\Support\Clock;
use App\Modules\Auth\Models\User;
use App\Modules\Security\SecurityLog;
use App\Modules\Security\SecurityModule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Oturum jetonları (jwt-kodmob eklentisinin karşılığı).
 *
 * Giriş yanıtı jwt-kodmob ile aynı biçimdedir: `{ token, refresh_token,
 * expires_in, user_display_name, user_email, user_nicename, user_id }`;
 * ön yüzdeki auth servisi değişmeden çalışır.
 *
 * Oturum = cihaz: `refresh_tokens` satırı. Satırın `session_id`'si yenilemede
 * değişmez; erişim jetonu bu kimliği `sid` iddiasında taşır. Her istekte oturum
 * satırının varlığına bakılır, böylece Güvenlik ya da Profilim'den kapatılan
 * oturum (ve pasife alınan kullanıcı) hemen düşer; jetonun süresi beklenmez.
 */
final class TokenService
{
    /** `last_seen_at` en çok bu aralıkla yazılır. */
    private const SEEN_EVERY = 300;

    /**
     * Döndürmeden sonra bir önceki yenileme jetonunun geçtiği süre (sn): yanıtı yolda kaybolan
     * istemci (sayfa geçişi, bağlantı kopması) yeni çerezi alamasa da oturumu düşmez.
     */
    private const GRACE = 60;

    public function __construct(private readonly Jwt $jwt) {}

    /** Giriş/yenileme yanıtı. */
    public function response(User $user, Request $request, ?string $sessionId = null): array
    {
        return $this->issue($user, $request, $sessionId) + [
            'user_display_name' => $user->display_name,
            'user_email' => $user->email,
            'user_nicename' => $user->username,
            'user_id' => $user->id,
        ];
    }

    /**
     * Yeni oturum açar (giriş).
     *
     * @return array{token:string,refresh_token:string,expires_in:int}
     */
    public function issue(User $user, Request $request, ?string $sessionId = null): array
    {
        $sessionId ??= bin2hex(random_bytes(16));
        [$token, $jti, $accessTtl] = $this->accessToken($user, $sessionId);
        $refresh = bin2hex(random_bytes(32));
        $now = Clock::now();
        DB::table('refresh_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $refresh),
            'jti' => $jti,
            'session_id' => $sessionId,
            'started_at' => $now,
            'expires_at' => $this->refreshExpiry(),
            'last_seen_at' => $now,
            'ip' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at' => $now,
        ]);
        if (random_int(1, 50) === 1) {
            $this->prune();
        }

        return ['token' => $token, 'refresh_token' => $refresh, 'expires_in' => $accessTtl];
    }

    /**
     * Yenileme jetonunu TEK KULLANIMLIK olarak harcar ve yeni çift verir. Oturum
     * satırı yerinde güncellenir (kimliği aynı kalır); aynı jetonla eşzamanlı iki
     * istek gelirse yalnızca satırı güncellemeyi başaran kazanır. Bir önceki jeton
     * GRACE saniye daha geçer (yeniden döndürür); sonra kullanılırsa reddedilir.
     */
    public function rotate(string $refreshToken, Request $request): array
    {
        $hash = hash('sha256', $refreshToken);
        $now = Clock::now();
        $row = DB::table('refresh_tokens')->where('token_hash', $hash)->where('expires_at', '>', $now)->first()
            ?? DB::table('refresh_tokens')->where('previous_hash', $hash)->where('expires_at', '>', $now)
                ->where('rotated_at', '>=', Carbon::parse($now)->subSeconds(self::GRACE)->format('Y-m-d H:i:s'))->first();
        if (! $row) {
            throw new DomainException('km_session_expired', 'Oturumunuz sona erdi. Lütfen yeniden giriş yapın.', 401);
        }
        $user = User::query()->find($row->user_id);
        if (! $user || ! $user->canAccessPanel()) {
            DB::table('refresh_tokens')->where('id', $row->id)->delete();
            throw new DomainException('km_account_disabled', 'Hesabınız kullanıma kapalı.', 401);
        }

        $sessionId = (string) $row->session_id ?: bin2hex(random_bytes(16));
        [$token, $jti, $accessTtl] = $this->accessToken($user, $sessionId);
        $refresh = bin2hex(random_bytes(32));
        $updated = DB::table('refresh_tokens')->where('id', $row->id)->where('token_hash', $row->token_hash)->update([
            'token_hash' => hash('sha256', $refresh),
            'previous_hash' => $row->token_hash,
            'rotated_at' => $now,
            'jti' => $jti,
            'session_id' => $sessionId,
            'expires_at' => $this->refreshExpiry(),
            'last_seen_at' => Clock::now(),
            'ip' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        if ($updated !== 1) {
            SecurityLog::record(SecurityLog::TOKEN_REUSED, $request, '', (string) $user->username, (int) $user->id);
            throw new DomainException('km_session_expired', 'Oturumunuz sona erdi. Lütfen yeniden giriş yapın.', 401);
        }

        return ['token' => $token, 'refresh_token' => $refresh, 'expires_in' => $accessTtl] + [
            'user_display_name' => $user->display_name,
            'user_email' => $user->email,
            'user_nicename' => $user->username,
            'user_id' => $user->id,
        ];
    }

    /**
     * Erişim jetonunu doğrular ve kullanıcıyı döndürür.
     *
     * @return array{0:User,1:array<string,mixed>}
     */
    public function authenticate(string $token): array
    {
        $claims = $this->jwt->decode($token, $error);
        if ($claims === null) {
            throw $error === 'expired'
                ? new DomainException('km_token_expired', 'Oturum süresi doldu.', 401)
                : new DomainException('km_unauthenticated', 'Geçersiz oturum. Lütfen yeniden giriş yapın.', 401);
        }

        $sid = (string) ($claims['sid'] ?? '');
        if ($sid !== '') {
            $session = DB::table('refresh_tokens')->where('session_id', $sid)->first(['id', 'last_seen_at']);
            if (! $session) {
                throw new DomainException('km_session_ended', 'Oturumunuz kapatıldı. Lütfen yeniden giriş yapın.', 401);
            }
            if (! $session->last_seen_at || strtotime((string) $session->last_seen_at) < time() - self::SEEN_EVERY) {
                DB::table('refresh_tokens')->where('id', $session->id)->update(['last_seen_at' => Clock::now()]);
            }
        } elseif (DB::table('revoked_tokens')->where('jti', (string) ($claims['jti'] ?? ''))->exists()) {
            throw new DomainException('km_unauthenticated', 'Oturum kapatılmış.', 401);
        }

        $user = User::query()->find((int) $claims['sub']);
        if (! $user) {
            throw new DomainException('km_unauthenticated', 'Hesap bulunamadı.', 401);
        }
        if (! $user->canAccessPanel()) {
            throw new DomainException('km_account_disabled', 'Hesabınız kullanıma kapalı.', 401);
        }

        return [$user, $claims];
    }

    /** Çıkış: bu cihazın oturumu kapanır; erişim jetonu süresi dolana kadar iptal listesinde. */
    public function revoke(array $claims): void
    {
        $jti = (string) ($claims['jti'] ?? '');
        if ($jti !== '') {
            DB::table('revoked_tokens')->insertOrIgnore([
                'jti' => $jti,
                'expires_at' => Carbon::createFromTimestamp((int) $claims['exp'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            ]);
            DB::table('refresh_tokens')->where('jti', $jti)->delete();
        }
        if (! empty($claims['sid'])) {
            DB::table('refresh_tokens')->where('session_id', (string) $claims['sid'])->delete();
        }
    }

    /** Kullanıcının bütün oturumlarını kapatır (pasife alma, şifre sıfırlama); `$except` açık kalır. */
    public function revokeAll(int $userId, ?string $except = null): int
    {
        $query = DB::table('refresh_tokens')->where('user_id', $userId);
        if ($except) {
            $query->where('session_id', '<>', $except);
        }

        return $query->delete();
    }

    /** Süresi dolan kayıtları siler. */
    public function prune(): void
    {
        $now = Clock::now();
        DB::table('refresh_tokens')->where('expires_at', '<', $now)->delete();
        DB::table('revoked_tokens')->where('expires_at', '<', $now)->delete();
    }

    /** @return array{0:string,1:string,2:int} jeton, jti, süre (sn) */
    private function accessToken(User $user, string $sessionId): array
    {
        $now = time();
        $ttl = SecurityModule::int(SecurityModule::ACCESS_TTL, (int) config('jwt.access_ttl_minutes', 60)) * 60;
        $jti = Str::random(32);

        return [$this->jwt->encode([
            'iss' => config('app.url'),
            'sub' => (string) $user->id,
            'sid' => $sessionId,
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]), $jti, $ttl];
    }

    private function refreshExpiry(): string
    {
        $days = SecurityModule::int(SecurityModule::REFRESH_TTL, (int) config('jwt.refresh_ttl_days', 30));

        return now()->addDays(max(1, $days))->format('Y-m-d H:i:s');
    }
}
