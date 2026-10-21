<?php

namespace App\Core\Support;

/**
 * Dosya yazma yardımcıları. Laravel'siz katmanın (public/render.php) okuduğu derlenmiş
 * yapılandırmalar atomik yazılır: önce aynı klasörde geçici dosya, sonra yeniden adlandırma.
 * Okuyan taraf hiçbir zaman yarım yazılmış dosya görmez.
 */
final class Files
{
    public static function putAtomic(string $file, string $contents): bool
    {
        $dir = dirname($file);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }
        $tmp = $dir.'/.'.basename($file).'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($tmp);

            return false;
        }
        // Windows'ta hedef varken rename başarısız olabilir: önce dene, olmazsa kopyala.
        if (@rename($tmp, $file)) {
            return true;
        }
        $ok = @copy($tmp, $file);
        @unlink($tmp);

        return $ok;
    }

    /** JSON (Türkçe karakterler ve eğik çizgiler kaçırılmadan) atomik yazılır. */
    public static function putJson(string $file, mixed $data): bool
    {
        return self::putAtomic($file, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
