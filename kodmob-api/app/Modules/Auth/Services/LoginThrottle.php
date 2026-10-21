<?php

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\DomainException;
use App\Modules\Security\Firewall;
use App\Modules\Security\SecurityLog;
use App\Modules\Security\SecurityModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Hatalı giriş kilidi (jwt-kodmob'daki gibi), iki katman:
 *
 * - Kullanıcı adı + IP: `security.login_max_attempts` hatadan sonra
 *   `security.login_lockout_minutes` kilit. Yalnızca kullanıcı adına bağlı olsaydı
 *   saldırgan başkasını kilitleyebilirdi; yalnızca IP'ye bağlı olsaydı ortak çıkış
 *   IP'sindeki herkes kilitlenirdi.
 * - IP: bir saatte `security.ip_login_limit` hata (farklı kullanıcı adlarıyla
 *   tarama) → o IP'nin girişi bir saat kapanır. 0: kapalı.
 *
 * Her hata güvenlik kaydına yazılır; eşik aşılırsa IP otomatik engellenir.
 */
final class LoginThrottle
{
    public function ensureNotLocked(string $username, Request $request): void
    {
        $ipKey = $this->ipKey((string) $request->ip());
        $ipLimit = $this->ipLimit();
        if ($ipLimit > 0 && RateLimiter::tooManyAttempts($ipKey, $ipLimit)) {
            $this->tooMany(RateLimiter::availableIn($ipKey));
        }
        $key = $this->key($username, (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, $this->maxAttempts())) {
            $this->tooMany(RateLimiter::availableIn($key));
        }
    }

    public function failed(string $username, Request $request, string $reason = ''): void
    {
        $ip = (string) $request->ip();
        $key = $this->key($username, $ip);
        RateLimiter::hit($key, $this->lockoutMinutes() * 60);
        SecurityLog::record(SecurityLog::LOGIN_FAILED, $request, $reason, mb_substr($username, 0, 64));
        if (RateLimiter::attempts($key) === $this->maxAttempts()) {
            SecurityLog::record(SecurityLog::LOGIN_LOCKED, $request, $this->lockoutMinutes().' dk', mb_substr($username, 0, 64));
        }

        $ipLimit = $this->ipLimit();
        if ($ipLimit > 0) {
            RateLimiter::hit($this->ipKey($ip), 3600);
            if (RateLimiter::attempts($this->ipKey($ip)) === $ipLimit) {
                SecurityLog::record(SecurityLog::IP_LOGIN_LIMITED, $request, '1 saat');
            }
        }
        Firewall::autoBlock($ip);
    }

    public function clear(string $username, Request $request): void
    {
        RateLimiter::clear($this->key($username, (string) $request->ip()));
    }

    /**
     * Şu an kilitli girişler (Güvenlik > Kilitli girişler). Kilit önbellektedir;
     * aday çiftler güvenlik kaydındaki son hatalardan bulunur.
     *
     * @return list<array{kind:string,username:string,ip:string,attempts:int,available_in:int,last_at:string}>
     */
    public function lockouts(): array
    {
        $out = [];
        $since = now()->subMinutes(max($this->lockoutMinutes(), 60))->format('Y-m-d H:i:s');
        $rows = DB::table('security_events')
            ->selectRaw('username, ip, COUNT(*) AS n, MAX(created_at) AS last_at')
            ->where('type', SecurityLog::LOGIN_FAILED)
            ->where('created_at', '>=', $since)
            ->groupBy('username', 'ip')
            ->orderByDesc('last_at')
            ->limit(500)
            ->get();
        $ips = [];
        foreach ($rows as $row) {
            $key = $this->key((string) $row->username, (string) $row->ip);
            if (RateLimiter::tooManyAttempts($key, $this->maxAttempts())) {
                $out[] = ['kind' => 'user', 'username' => (string) $row->username, 'ip' => (string) $row->ip, 'attempts' => RateLimiter::attempts($key), 'available_in' => RateLimiter::availableIn($key), 'last_at' => (string) $row->last_at];
            }
            $ips[(string) $row->ip] = max($ips[(string) $row->ip] ?? '', (string) $row->last_at);
        }
        $ipLimit = $this->ipLimit();
        foreach ($ips as $ip => $last) {
            $key = $this->ipKey($ip);
            if ($ipLimit > 0 && RateLimiter::tooManyAttempts($key, $ipLimit)) {
                $out[] = ['kind' => 'ip', 'username' => '', 'ip' => $ip, 'attempts' => RateLimiter::attempts($key), 'available_in' => RateLimiter::availableIn($key), 'last_at' => $last];
            }
        }

        return $out;
    }

    /** Kilidi açar: kullanıcı adı verilirse o çift, verilmezse IP kilidi. */
    public function unlock(string $username, string $ip): void
    {
        if ($username !== '') {
            RateLimiter::clear($this->key($username, $ip));
        } else {
            RateLimiter::clear($this->ipKey($ip));
        }
    }

    private function tooMany(int $seconds): never
    {
        throw new DomainException(
            'jwt_auth_too_many_attempts',
            'Çok fazla hatalı deneme. '.max(1, (int) ceil($seconds / 60)).' dakika sonra tekrar deneyin.',
            429,
            ['retry_after' => $seconds],
        );
    }

    private function key(string $username, string $ip): string
    {
        return 'login:'.sha1(mb_strtolower(trim($username)).'|'.$ip);
    }

    private function ipKey(string $ip): string
    {
        return 'login-ip:'.sha1($ip);
    }

    private function maxAttempts(): int
    {
        return max(3, SecurityModule::int(SecurityModule::LOGIN_ATTEMPTS, (int) config('jwt.login_max_attempts', 5)));
    }

    private function lockoutMinutes(): int
    {
        return max(1, SecurityModule::int(SecurityModule::LOCKOUT_MINUTES, (int) config('jwt.login_lockout_minutes', 15)));
    }

    private function ipLimit(): int
    {
        return max(0, SecurityModule::int(SecurityModule::IP_LOGIN_LIMIT, 30));
    }
}
