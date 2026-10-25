<?php

namespace App\Core\Setup;

use App\Core\Exceptions\ValidationException;

/**
 * Çatının dosyaları (kökteki kodmob.json): hangi yollar çatıya ait (güncellemeyle yenilenir), hangileri
 * projeye (hiç dokunulmaz). Projede `kodmob.lock.json` kurulu çatı sürümünü ve her çatı dosyasının
 * özetini tutar; `kodmob:upgrade` projede değişmiş dosyayı bununla anlar.
 *
 *   kodmob.json       { name, version, framework: [desen…], project: [desen…], exclude: [desen…] }
 *   kodmob.lock.json  { name, version, files: { "kodmob-frontend/src/panel/app.js": "<sha256>", … } }
 *
 * Desenler: `**` (klasörler dahil her şey), `*` (bir parça içinde), gerisi harfi harfine; yollar kökten,
 * `/` ile. Özet satır sonundan bağımsızdır (metin dosyasında CRLF → LF), Windows ile Linux aynı sayar.
 */
final class FrameworkFiles
{
    public const MANIFEST = 'kodmob.json';

    public const LOCK = 'kodmob.lock.json';

    /** Hiç gezilmeyen klasörler (bağımlılık, derleme, çalışma verisi). */
    private const SKIP_DIRS = ['.git', 'node_modules', 'vendor', '.local', '.e2e', '.pg', 'release'];

    /** @param array{name:string,version:string,framework:list<string>,project:list<string>,exclude:list<string>} $manifest */
    public function __construct(public readonly string $root, public readonly array $manifest) {}

    public static function load(string $root): self
    {
        $file = rtrim($root, '/\\').'/'.self::MANIFEST;
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($data) || empty($data['framework'])) {
            throw ValidationException::field('root', "Çatı tanımı yok ya da bozuk: {$file}");
        }

        return new self(rtrim($root, '/\\'), [
            'name' => (string) ($data['name'] ?? 'kodmob'),
            'version' => (string) ($data['version'] ?? '0.0.0'),
            'framework' => array_values(array_map('strval', (array) $data['framework'])),
            'project' => array_values(array_map('strval', (array) ($data['project'] ?? []))),
            'exclude' => array_values(array_map('strval', (array) ($data['exclude'] ?? []))),
        ]);
    }

    /** Yol çatıya mı ait (çatı deseninde, proje ve hariç desenlerinde değil). */
    public function owns(string $path): bool
    {
        return self::matches($path, $this->manifest['framework'])
            && ! self::matches($path, $this->manifest['project'])
            && ! self::matches($path, $this->manifest['exclude']);
    }

    /**
     * Kökteki çatı dosyaları ve özetleri (yol sırasıyla).
     *
     * @return array<string,string> yol → sha256
     */
    public function scan(): array
    {
        $out = [];
        foreach ($this->bases() as $base) {
            $full = $base === '' ? $this->root : $this->root.'/'.$base;
            if (is_file($full)) {
                if ($this->owns($base)) {
                    $out[$base] = self::hash($full);
                }

                continue;
            }
            if (! is_dir($full)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f) => ! ($f->isDir() && in_array($f->getFilename(), self::SKIP_DIRS, true))
            ));
            foreach ($it as $file) {
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root) + 1));
                if ($file->isFile() && $this->owns($path)) {
                    $out[$path] = self::hash($file->getPathname());
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** Kurulu sürümün kilidi (yoksa null). @return array{name:string,version:string,files:array<string,string>}|null */
    public function lock(): ?array
    {
        $file = $this->root.'/'.self::LOCK;
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) && is_array($data['files'] ?? null) ? ['name' => (string) ($data['name'] ?? ''), 'version' => (string) ($data['version'] ?? ''), 'files' => $data['files']] : null;
    }

    /** @param array<string,string> $files */
    public function writeLock(array $files, ?string $version = null): void
    {
        ksort($files);
        file_put_contents($this->root.'/'.self::LOCK, json_encode([
            'name' => $this->manifest['name'],
            'version' => $version ?? $this->manifest['version'],
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /** Dosyanın özeti: metinde satır sonu farkı sayılmaz. */
    public static function hash(string $file): string
    {
        $content = (string) file_get_contents($file);

        return hash('sha256', str_contains(substr($content, 0, 8000), "\0") ? $content : str_replace("\r\n", "\n", $content));
    }

    /** @param list<string> $patterns */
    public static function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match(self::regex($pattern), $path)) {
                return true;
            }
        }

        return false;
    }

    private static function regex(string $pattern): string
    {
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $c = $pattern[$i];
            if ($c === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $i++;
                if (($pattern[$i + 1] ?? '') === '/') {
                    $i++;
                    $out .= '(?:.*/)?';
                } else {
                    $out .= '.*';
                }
            } elseif ($c === '*') {
                $out .= '[^/]*';
            } else {
                $out .= preg_quote($c, '#');
            }
        }

        return '#^'.$out.'$#';
    }

    /** Gezilecek kökler: desenlerin joker öncesi kısmı (kök klasörün tamamı gezilmez). @return list<string> */
    private function bases(): array
    {
        $bases = [];
        foreach ($this->manifest['framework'] as $pattern) {
            if (! str_contains($pattern, '*')) {
                $bases[] = $pattern;

                continue;
            }
            $static = explode('*', $pattern, 2)[0];
            $cut = strrpos($static, '/');
            $bases[] = $cut === false ? '' : substr($static, 0, $cut);
        }
        $bases = array_values(array_unique($bases));
        sort($bases);
        if (in_array('', $bases, true)) {
            return [''];
        }

        // Başka bir kökün içindeki kök gezilmez (iki kez sayılmasın).
        return array_values(array_filter($bases, static function ($b) use ($bases) {
            foreach ($bases as $other) {
                if ($other !== $b && str_starts_with($b, $other.'/')) {
                    return false;
                }
            }

            return true;
        }));
    }
}
