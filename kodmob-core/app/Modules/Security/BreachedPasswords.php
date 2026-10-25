<?php

namespace App\Modules\Security;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sızmış şifre denetimi (Have I Been Pwned, "aralık" sorgusu; k-anonimlik): şifrenin SHA-1 özetinin yalnızca ilk 5
 * hanesi gönderilir, dönen listede özetin geri kalanı aranır. Şifre ya da tam özeti sunucudan çıkmaz. Servis yanıt
 * vermezse denetim atlanır (şifre kabul edilir): giriş ve kayıt dış servise bağlı kalmaz.
 *
 * Ayar: Güvenlik › Ayarlar › "Sızmış şifreleri reddet" (`security.breached_passwords`, varsayılan açık).
 */
final class BreachedPasswords
{
    private const URL = 'https://api.pwnedpasswords.com/range/';

    /** Kaç sızıntıda göründü (0: görünmedi ya da denetlenemedi). */
    public static function count(string $password): int
    {
        if (! SecurityModule::bool(SecurityModule::BREACHED_PASSWORDS, true)) {
            return 0;
        }
        $hash = strtoupper(sha1($password));
        try {
            $response = Http::timeout(4)->withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'Kodmob'])->get(self::URL.substr($hash, 0, 5));
        } catch (Throwable) {
            return 0;
        }
        if (! $response->successful()) {
            return 0;
        }
        $suffix = substr($hash, 5);
        foreach (preg_split('/\R/', (string) $response->body()) ?: [] as $line) {
            [$rest, $times] = array_pad(explode(':', trim($line)), 2, '0');
            if (strtoupper($rest) === $suffix) {
                return (int) $times;
            }
        }

        return 0;
    }
}
