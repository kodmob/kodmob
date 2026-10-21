<?php

namespace App\Core\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Beklenmeyen hata günlüğü. Kullanıcıya "bir hata
 * oluştu" denir ama nedeni yalnızca sunucu günlüğündeydi: artık her hata bir kod alır (veritabanında
 * `DB-3F9A1C`, diğerlerinde `ERR-…`), kod kullanıcının gördüğü mesajda yazar, ayrıntısı (zaman, kişi,
 * işlem, hatanın kendisi, SQL kalıbı) Sistem Sağlığı › Hata kodları'nda görünür.
 *
 * Dosyada tutulur (sitenin depolamasında, son MAX kayıt): veritabanı çökmüşken de yazılır, geri alınan
 * işlemle birlikte silinmez, yedeğe girmez. SQL'in parametreleri yazılmaz (kişisel veri taşıyabilir).
 */
final class ErrorJournal
{
    private const MAX = 50;

    public static function file(): string
    {
        return storage_path('framework/errors.json');
    }

    /** Hatayı kaydeder, kullanıcıya gösterilecek kodu döndürür. */
    public static function record(Throwable $e, ?int $userId = null, string $request = ''): string
    {
        $database = $e instanceof QueryException;
        $ref = ($database ? 'DB-' : 'ERR-').strtoupper(bin2hex(random_bytes(3)));
        $error = $database ? ($e->getPrevious()?->getMessage() ?: $e->getMessage()) : $e->getMessage();
        $entry = [
            'ref' => $ref,
            'at' => Clock::now(),
            'user_id' => $userId,
            'request' => mb_substr($request, 0, 160),
            'context' => mb_substr(class_basename($e).' · '.self::where($e), 0, 200),
            'error' => mb_substr($error, 0, 500),
            'query' => $database ? mb_substr((string) preg_replace('/\s+/', ' ', $e->getSql()), 0, 1500) : '',
        ];
        try {
            $dir = dirname(self::file());
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $handle = fopen(self::file(), 'c+');
            if ($handle && flock($handle, LOCK_EX)) {
                $list = json_decode((string) stream_get_contents($handle), true);
                $list = array_slice([$entry, ...(is_array($list) ? $list : [])], 0, self::MAX);
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                flock($handle, LOCK_UN);
            }
            if ($handle) {
                fclose($handle);
            }
        } catch (Throwable) {
            // Günlük yazılamazsa da kullanıcıya kod gösterilir; hata Laravel günlüğündedir.
        }

        return $ref;
    }

    /** @return list<array<string,mixed>> yeniden eskiye, kişinin adıyla */
    public static function all(): array
    {
        $list = is_file(self::file()) ? json_decode((string) file_get_contents(self::file()), true) : [];

        return array_map(static fn (array $row) => ['at' => Clock::atom((string) ($row['at'] ?? ''))] + $row + [
            'user_name' => ! empty($row['user_id']) ? UserNames::of((int) $row['user_id']) : null,
        ], is_array($list) ? array_values($list) : []);
    }

    public static function clear(): void
    {
        @unlink(self::file());
    }

    /** Hatanın çıktığı yer: uygulama kodundaki ilk satır (dosya yolu proje köküne göre). */
    private static function where(Throwable $e): string
    {
        $root = str_replace('\\', '/', base_path()).'/';
        foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file !== '' && str_starts_with($file, $root.'app/')) {
                return substr($file, strlen($root)).':'.($frame['line'] ?? 0);
            }
        }

        return str_replace($root, '', str_replace('\\', '/', $e->getFile())).':'.$e->getLine();
    }
}
