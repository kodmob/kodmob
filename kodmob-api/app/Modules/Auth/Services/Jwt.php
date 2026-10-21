<?php

namespace App\Modules\Auth\Services;

use App\Core\Sites\SiteContext;
use App\Core\Support\Options;

/**
 * HS256 JWT kodlama/çözme. Dış paket gerektirmez; yalnızca HS256 kabul edilir
 * (alg=none ve anahtar karışıklığı saldırılarına kapalı).
 */
final class Jwt
{
    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT imza anahtarı en az 32 bayt olmalı.');
        }
    }

    /** Anahtar sürümü (Güvenlik > Oturumlar > "İmza anahtarını yenile" artırır). */
    public const VERSION_OPTION = 'jwt_key_version';

    /**
     * JWT_SECRET (yoksa APP_KEY) ve anahtar sürümünden türetilen imza anahtarı.
     * Sürüm artınca eski jetonların imzası tutmaz: herkesin oturumu kapanır.
     */
    public static function fromConfig(): self
    {
        $secret = (string) config('jwt.secret');
        if ($secret === '') {
            $secret = hash_hmac('sha256', 'kodmob-jwt', (string) config('app.key'));
        }
        // Çok site: her alt sitenin kendi anahtarı (bir sitenin jetonu ötekinde geçmez).
        $site = (string) config('kodmob.site', SiteContext::MAIN);
        if ($site !== SiteContext::MAIN) {
            $secret = hash_hmac('sha256', 'kodmob-site|'.$site, $secret);
        }
        $version = self::version();

        return new self($version > 1 ? hash_hmac('sha256', 'kodmob-jwt-v'.$version, $secret) : $secret);
    }

    public static function version(): int
    {
        try {
            return max(1, (int) Options::get(self::VERSION_OPTION, 1));
        } catch (\Throwable) {
            return 1;
        }
    }

    /** Anahtarın parmak izi (panelde gösterilir; anahtarın kendisi değil). */
    public function fingerprint(): string
    {
        return substr(hash('sha256', 'fp|'.$this->secret), 0, 12);
    }

    /** @param array<string,mixed> $claims */
    public function encode(array $claims): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $signature = self::b64(hash_hmac('sha256', "$header.$payload", $this->secret, true));

        return "$header.$payload.$signature";
    }

    /**
     * İmzayı ve süreyi doğrular. Geçersizse null; nedeni `$error`'a yazılır.
     *
     * @return array<string,mixed>|null
     */
    public function decode(string $token, ?string &$error = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            $error = 'malformed';

            return null;
        }
        [$h, $p, $s] = $parts;

        $signature = self::unb64($s);
        if ($signature === null || ! hash_equals(hash_hmac('sha256', "$h.$p", $this->secret, true), $signature)) {
            $error = 'signature';

            return null;
        }
        $header = json_decode((string) self::unb64($h), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            $error = 'algorithm';

            return null;
        }
        $claims = json_decode((string) self::unb64($p), true);
        if (! is_array($claims) || ! isset($claims['exp'], $claims['sub'])) {
            $error = 'payload';

            return null;
        }
        if ((int) $claims['exp'] < time()) {
            $error = 'expired';

            return null;
        }

        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
