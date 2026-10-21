<?php

namespace App\Modules\Site\Blocks;

/**
 * Sayfa düzenleyicinin önizleme anahtarı. Panel oturumu (Bearer) çerçevedeki sayfa isteğine gitmez; düzenleyici
 * kısa ömürlü bir anahtar alır ve sayfayı `?km-preview=<anahtar>` ile açar. Anahtar sitenin depolamasında özetiyle
 * dosyadır: public/render.php Laravel'i açmadan tanır (önbelleği atlar, çerçeveye izin verir); sayfa üretilirken
 * kullanıcının hâlâ etkin ve yetkili olduğu ayrıca denetlenir (Site\Render\SitePage).
 *
 * Bu sınıf Laravel'e bağlı değildir (render.php `require_once` eder); depolama yolu dışarıdan verilir.
 */
final class PreviewToken
{
    public const PARAM = 'km-preview';

    public const TTL = 7200;

    public static function dir(string $storage): string
    {
        return rtrim($storage, '/\\').'/framework/preview';
    }

    /** @return array{token:string,expires:int} */
    public static function issue(string $storage, int $userId): array
    {
        $dir = self::dir($storage);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - self::TTL) {
                @unlink($file);
            }
        }
        $token = bin2hex(random_bytes(20));
        $expires = time() + self::TTL;
        file_put_contents($dir.'/'.hash('sha256', $token).'.json', (string) json_encode(['user' => $userId, 'expires' => $expires]), LOCK_EX);

        return ['token' => $token, 'expires' => $expires];
    }

    /** Anahtarın kullanıcısı (geçersiz ya da süresi geçmişse null). */
    public static function user(string $storage, string $token): ?int
    {
        if (! preg_match('/^[0-9a-f]{40}$/', $token)) {
            return null;
        }
        $file = self::dir($storage).'/'.hash('sha256', $token).'.json';
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data) || (int) ($data['expires'] ?? 0) < time()) {
            @unlink($file);

            return null;
        }

        return (int) ($data['user'] ?? 0) ?: null;
    }
}
