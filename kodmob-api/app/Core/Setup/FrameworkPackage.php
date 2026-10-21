<?php

namespace App\Core\Setup;

use App\Core\Exceptions\ValidationException;
use ZipArchive;

/**
 * Çatı paketi (`php artisan kodmob:pack`): kodmob.json'daki çatı dosyaları + kilit (kodmob.lock.json:
 * sürüm ve özetler) tek zip'te. Projeler `kodmob:upgrade --from=<zip>` ile bundan günceller. Projenin
 * dosyaları (profiller, app/Project, config/project.php…) pakete girmez.
 */
final class FrameworkPackage
{
    /** @return array{file:string,count:int,version:string} */
    public static function build(FrameworkFiles $framework, string $outDir): array
    {
        $files = $framework->scan();
        if (! $files) {
            throw ValidationException::field('root', 'Pakete girecek çatı dosyası yok (kodmob.json desenleri).');
        }
        @mkdir($outDir, 0755, true);
        $version = $framework->manifest['version'];
        $target = rtrim($outDir, '/\\').'/'.$framework->manifest['name'].'-'.$version.'.zip';
        @unlink($target);

        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::field('out', "Paket yazılamadı: {$target}");
        }
        foreach (array_keys($files) as $path) {
            $zip->addFile($framework->root.'/'.$path, $path);
        }
        // Çatının tanımı da gider (güncelleme yeni desenleri alır; kilitte değil, projenin desenleri birleşir).
        $zip->addFile($framework->root.'/'.FrameworkFiles::MANIFEST, FrameworkFiles::MANIFEST);
        ksort($files);
        $zip->addFromString(FrameworkFiles::LOCK, json_encode([
            'name' => $framework->manifest['name'],
            'version' => $version,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $zip->close();

        return ['file' => $target, 'count' => count($files), 'version' => $version];
    }

    /**
     * Paketi (zip ya da açılmış klasör) okunur hale getirir: klasör yolu ve kilidi.
     *
     * @return array{dir:string,lock:array{name:string,version:string,files:array<string,string>},temp:bool}
     */
    public static function open(string $source, string $tempDir): array
    {
        $temp = false;
        if (is_file($source)) {
            $zip = new ZipArchive;
            if ($zip->open($source) !== true) {
                throw ValidationException::field('from', "Paket açılamadı: {$source}");
            }
            $dir = rtrim($tempDir, '/\\').'/kodmob-upgrade-'.bin2hex(random_bytes(4));
            @mkdir($dir, 0755, true);
            // Zip'teki yol klasör dışına çıkamaz (../).
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
                    $zip->close();
                    throw ValidationException::field('from', "Pakette geçersiz yol: {$name}");
                }
            }
            $zip->extractTo($dir);
            $zip->close();
            $source = $dir;
            $temp = true;
        }
        $lockFile = rtrim($source, '/\\').'/'.FrameworkFiles::LOCK;
        $lock = is_file($lockFile) ? json_decode((string) file_get_contents($lockFile), true) : null;
        if (! is_array($lock) || ! is_array($lock['files'] ?? null)) {
            throw ValidationException::field('from', "Pakette kilit yok: {$lockFile} (paket kodmob:pack ile üretilir).");
        }

        return ['dir' => rtrim($source, '/\\'), 'lock' => ['name' => (string) ($lock['name'] ?? ''), 'version' => (string) ($lock['version'] ?? ''), 'files' => $lock['files']], 'temp' => $temp];
    }
}
