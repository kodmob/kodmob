<?php

namespace App\Modules\Auth\Services;

use App\Core\Support\Clock;
use App\Core\Support\SiteManifest;
use App\Modules\Auth\Models\User;

/**
 * İki adımlı doğrulama: zaman tabanlı tek kullanımlık kod (TOTP, RFC 6238; Google Authenticator,
 * Microsoft Authenticator, 1Password, Bitwarden…). 30 sn'lik adım, 6 hane, saat kaymasına ±1 adım.
 *
 * - Gizli anahtar kullanıcı satırında APP_KEY ile şifreli (`encrypted` dönüşümü); onaylanana kadar
 *   bekler (`two_factor_confirmed_at` boş), uygulamadan bir kod girilince açılır.
 * - Aynı kod (zaman adımı) ikinci kez kabul edilmez (`two_factor_last_step`).
 * - 8 yedek kod: telefon kaybolursa; her biri bir kez geçer, yalnızca özetleri saklanır.
 */
final class TwoFactor
{
    private const STEP = 30;

    private const DIGITS = 6;

    private const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && (string) $user->two_factor_secret !== '';
    }

    /** Yeni gizli anahtar (160 bit, base32). */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Doğrulama uygulamasına eklenecek adres (QR kodu bundan çizilir). */
    public static function otpauthUrl(User $user, string $secret): string
    {
        $issuer = SiteManifest::siteName();

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->username).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::STEP,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Bir zaman adımının kodu (RFC 4226 HOTP). */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Uygulama kodu doğru mu (±1 adım); doğruysa adım kaydedilir, aynı kod tekrar geçmez.
     * `$secret`: onay sırasında henüz kaydedilmemiş anahtar.
     */
    public static function verifyCode(User $user, string $code, ?string $secret = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        $secret ??= (string) $user->two_factor_secret;
        if (! preg_match('/^\d{6}$/', (string) $code) || $secret === '') {
            return false;
        }
        $now = intdiv(Clock::moment()->getTimestamp(), self::STEP);
        $last = (int) ($user->two_factor_last_step ?? 0);
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if ($step > $last && hash_equals(self::code($secret, $step), $code)) {
                $user->two_factor_last_step = $step;
                $user->save();

                return true;
            }
        }

        return false;
    }

    /** Yedek kod doğruysa harcanır. */
    public static function useRecoveryCode(User $user, string $code): bool
    {
        $hash = self::hashRecovery($code);
        $codes = (array) ($user->two_factor_recovery ?? []);
        $index = array_search($hash, $codes, true);
        if ($index === false) {
            return false;
        }
        unset($codes[$index]);
        $user->two_factor_recovery = array_values($codes);
        $user->save();

        return true;
    }

    /** Uygulama kodu ya da yedek kod. @return 'code'|'recovery'|null */
    public static function check(User $user, string $input): ?string
    {
        if (self::verifyCode($user, $input)) {
            return 'code';
        }

        return self::useRecoveryCode($user, $input) ? 'recovery' : null;
    }

    /**
     * Yeni yedek kodlar (eskileri geçersiz). Düz hali yalnızca bir kez, kullanıcıya gösterilir.
     *
     * @return list<string>
     */
    public static function newRecoveryCodes(User $user): array
    {
        $codes = array_map(static fn () => strtolower(substr(self::base32Encode(random_bytes(10)), 0, 5).'-'.substr(self::base32Encode(random_bytes(10)), 0, 5)), range(1, 8));
        $user->two_factor_recovery = array_map(self::hashRecovery(...), $codes);
        $user->save();

        return $codes;
    }

    public static function remainingRecoveryCodes(User $user): int
    {
        return count((array) ($user->two_factor_recovery ?? []));
    }

    public static function disable(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_recovery = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_step = null;
        $user->save();
    }

    private static function hashRecovery(string $code): string
    {
        return hash_hmac('sha256', strtolower(preg_replace('/[^a-z0-9]/i', '', $code)), (string) config('app.key'));
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text))) as $char) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
