<?php

namespace App\Core\Storage;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;

/**
 * Parolayla şifrelenmiş dosya (uzak depoya giden yedek; Modules\Content\AutoBackup). Büyük dosya belleğe alınmaz:
 * parça parça AES-256-GCM (OpenSSL; paylaşımlı sunucuda sodium her zaman yok). Anahtar paroladan PBKDF2-SHA256 ile,
 * her dosyada rastgele tuzla türetilir; anahtar kurulumdan (APP_KEY) bağımsızdır: yeni sunucuda parolayla açılır.
 *
 *   başlık   "KMBK" · sürüm (1) · yineleme (4) · parça boyu (4) · tuz (16) · nonce öneki (7)   = 36 bayt
 *   parça    şifreli metin (parça boyu, sonuncusu kısa olabilir) · etiket (16)
 *
 * Parçanın nonce'u: önek · sıra (4) · son mu (1). Başlık her parçada ek veridir. Sıra ve "son" işareti parçaların
 * yer değiştirmesini, kesilmeyi ve sona ekleme yapılmasını yakalar (STREAM yapısı).
 */
final class EncryptedFile
{
    public const MAGIC = 'KMBK';

    public const EXTENSION = 'kmbak';

    private const VERSION = 1;

    private const ITERATIONS = 600000;

    private const CHUNK = 1048576;

    private const HEADER = 36;

    private const TAG = 16;

    public static function is(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        $magic = fread($handle, 4);
        fclose($handle);

        return $magic === self::MAGIC;
    }

    public static function encrypt(string $source, string $target, string $passphrase, int $iterations = self::ITERATIONS, int $chunk = self::CHUNK): void
    {
        if ($passphrase === '') {
            throw ValidationException::field('passphrase', 'Yedek şifresi gerekli.');
        }
        $salt = random_bytes(16);
        $prefix = random_bytes(7);
        $header = self::MAGIC.chr(self::VERSION).pack('N', $iterations).pack('N', $chunk).$salt.$prefix;
        $key = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);

        [$in, $out] = self::open($source, $target);
        try {
            fwrite($out, $header);
            $current = self::read($in, $chunk);
            for ($index = 0; ; $index++) {
                $next = self::read($in, $chunk);
                $last = $next === '';
                $tag = '';
                $cipher = openssl_encrypt($current, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($prefix, $index, $last), $tag, $header, self::TAG);
                if ($cipher === false) {
                    throw new DomainException('km_encrypt_failed', 'Dosya şifrelenemedi.', 500);
                }
                fwrite($out, $cipher.$tag);
                if ($last) {
                    break;
                }
                $current = $next;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** Parola yanlışsa ya da dosya bozuk / eksikse hata; yarım çıktı silinir. */
    public static function decrypt(string $source, string $target, string $passphrase): void
    {
        [$in, $out] = self::open($source, $target);
        try {
            $header = self::read($in, self::HEADER);
            if (strlen($header) !== self::HEADER || ! str_starts_with($header, self::MAGIC) || ord($header[4]) !== self::VERSION) {
                throw ValidationException::field('file', 'Bu dosya şifreli bir Kodmob yedeği değil.');
            }
            $iterations = unpack('N', substr($header, 5, 4))[1];
            $chunk = unpack('N', substr($header, 9, 4))[1];
            // Başlık dosyadan gelir: aşırı değerle sunucuyu yormasın.
            if ($iterations < 1000 || $iterations > 5000000 || $chunk < 1024 || $chunk > 16777216) {
                throw ValidationException::field('file', 'Şifreli yedeğin başlığı geçersiz.');
            }
            $key = hash_pbkdf2('sha256', $passphrase, substr($header, 13, 16), $iterations, 32, true);
            $prefix = substr($header, 29, 7);

            $current = self::read($in, $chunk + self::TAG);
            for ($index = 0; ; $index++) {
                $next = self::read($in, $chunk + self::TAG);
                $last = $next === '';
                $plain = strlen($current) < self::TAG ? false : openssl_decrypt(substr($current, 0, -self::TAG), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($prefix, $index, $last), substr($current, -self::TAG), $header);
                if ($plain === false) {
                    throw ValidationException::field('passphrase', 'Yedek şifresi yanlış ya da dosya bozuk.');
                }
                fwrite($out, $plain);
                if ($last) {
                    break;
                }
                $current = $next;
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($target);
            throw $e;
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    private static function nonce(string $prefix, int $index, bool $last): string
    {
        return $prefix.pack('N', $index).($last ? "\x01" : "\x00");
    }

    /** @return array{0:resource,1:resource} */
    private static function open(string $source, string $target): array
    {
        $in = @fopen($source, 'rb');
        if (! $in) {
            throw new DomainException('km_file_unreadable', 'Dosya okunamadı.', 500);
        }
        $out = @fopen($target, 'wb');
        if (! $out) {
            fclose($in);
            throw new DomainException('km_file_unwritable', 'Dosya yazılamadı.', 500);
        }

        return [$in, $out];
    }

    /** Tam `$length` bayt (dosya sonunda daha az); fread tek seferde eksik dönebilir. */
    private static function read($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && ! feof($handle)) {
            $part = fread($handle, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }

        return $data;
    }
}
