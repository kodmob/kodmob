<?php

namespace App\Modules\Live;

use App\Core\Support\Paths;
use App\Core\Support\Text;

/**
 * Değişiklik nabzı: her konu için "son değişme anı"nı tutan küçük, statik
 * bir JSON dosyası.
 *
 *     { "v": 1, "t": { "brief": 1759751234123, "notification": …, "jarvis_job": … } }
 *
 * Panel bu dosyayı düz dosya olarak okur (Apache, ETag ile 304): PHP açılmaz,
 * değişiklik yokken istek ~1 ms'dir. Bir konunun değeri değişince panel o
 * konunun verisini kendisi yeniden ister. Dosyada kayıt içeriği yoktur; yine
 * de adresi tahmin edilemez (`live-<özet>/`) ve yalnızca yönetici ucundan
 * (`GET /live`) öğrenilir.
 *
 * Yazma: bir istekteki bütün dokunuşlar toplanır, istek sonunda tek sefer
 * yazılır (flock + geçici dosya + rename).
 */
final class PulseFile
{
    private const FILE = 'pulse.json';

    /** @var array<string,true> bu istekte dokunulan konular */
    private static array $pending = [];

    /** Yazmanın kaydedildiği uygulama (testlerde her test yeni uygulama açar). */
    private static ?int $registeredFor = null;

    /** Konuları "değişti" olarak işaretler; istek sonunda dosyaya yazılır. */
    public static function touch(string ...$topics): void
    {
        foreach ($topics as $topic) {
            $topic = preg_replace('/[^a-z0-9_]/', '', strtolower($topic));
            if ($topic !== '') {
                self::$pending[$topic] = true;
            }
        }
        // İstek (ya da komut) bitince tek sefer yazılır.
        if (self::$pending && self::$registeredFor !== spl_object_id(app())) {
            self::$registeredFor = spl_object_id(app());
            app()->terminating(static fn () => self::flush());
        }
    }

    public static function flush(): void
    {
        if (! self::$pending) {
            return;
        }
        $topics = array_keys(self::$pending);
        self::$pending = [];
        try {
            $path = self::path();
        } catch (\Throwable) {
            return;
        }
        if ($path === null) {
            return;
        }
        $lock = @fopen($path.'.lock', 'c');
        if ($lock === false) {
            return;
        }
        try {
            flock($lock, LOCK_EX);
            $data = self::read($path);
            $now = (int) floor(microtime(true) * 1000);
            foreach ($topics as $topic) {
                // Aynı milisaniyede iki yazma da "değişti" sayılsın.
                $data['t'][$topic] = max($now, (int) ($data['t'][$topic] ?? 0) + 1);
            }
            $tmp = $path.'.'.getmypid().'.tmp';
            if (@file_put_contents($tmp, Text::json($data)) !== false) {
                @rename($tmp, $path);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,int> */
    public static function snapshot(): array
    {
        $path = self::path();

        return $path === null ? [] : self::read($path)['t'];
    }

    /** Tarayıcının okuyacağı adres (site köküne göre yol). */
    public static function url(): string
    {
        return Paths::mediaUrl(self::dirName().'/'.self::FILE);
    }

    private static function dirName(): string
    {
        return 'live-'.substr(hash_hmac('sha256', 'km-pulse-dir', (string) config('app.key')), 0, 16);
    }

    private static function path(): ?string
    {
        $dir = Paths::mediaRoot().'/'.self::dirName();
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return null;
            }
            @file_put_contents($dir.'/index.html', '');
        }

        return $dir.'/'.self::FILE;
    }

    /** @return array{v:int,t:array<string,int>} */
    private static function read(string $path): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || ! isset($data['t']) || ! is_array($data['t'])) {
            return ['v' => 1, 't' => []];
        }

        return ['v' => 1, 't' => array_map('intval', $data['t'])];
    }
}
