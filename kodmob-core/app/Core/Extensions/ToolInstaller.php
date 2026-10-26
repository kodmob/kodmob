<?php

namespace App\Core\Extensions;

use App\Core\Exceptions\ValidationException;
use App\Core\Modules;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Araç paketini kurar (`php artisan kodmob:add <kaynak>`; docs/arac-paketi.md). Kaynak:
 *
 * - paketin klasörü (kodmob-tool.json kökte ya da tek alt klasörde),
 * - paketin zip'i (GitHub'ın "Download ZIP"i gibi tek üst klasörlü olabilir),
 * - Keşfet kataloğundaki anahtar (github.com/kodmob/kodmob-explore; Marketplace): `download` adresinden indirilir,
 *   `sha256` özeti tutmazsa kurulmaz.
 *
 * Tanım denetlenir (ToolManifest), çatı sürümüyle uyumu ve anahtarın çatının modülleriyle çakışmadığı
 * bakılır; paket `extensions/<anahtar>/`'a kopyalanır. Kurulu paketin üstüne yalnızca `--update` ile.
 */
final class ToolInstaller
{
    /** Kopyalanmayan klasörler. */
    private const SKIP = ['.git', 'node_modules', 'vendor', '.idea', '.vscode'];

    /**
     * @return array{key:string,version:string,dir:string,updated:bool}
     */
    public static function add(string $source, string $root, bool $update = false, ?string $catalog = null): array
    {
        $temp = storage_path('framework/kodmob-tool-'.bin2hex(random_bytes(4)));
        try {
            $dir = self::locate(self::fetch($source, $temp, $catalog));
            $tool = ToolManifest::read($dir, false);
            $key = $tool->key();
            if (isset(Modules::OPTIONAL[$key])) {
                throw ValidationException::field('key', "\"{$key}\" çatının bir modülü; paket kurulamaz.");
            }
            $version = (string) config('kodmob.version');
            if (! $tool->compatible($version)) {
                throw ValidationException::field('kodmob', "Paket çatının {$version} sürümüyle uyumsuz ({$tool->data['kodmob']}).");
            }
            $target = rtrim($root, '/\\').'/'.$key;
            $exists = is_dir($target);
            if ($exists && ! $update) {
                throw ValidationException::field('key', "{$tool->data['label']} zaten kurulu ({$target}); güncellemek için --update.");
            }
            if (realpath($dir) === realpath($target)) {
                throw ValidationException::field('source', 'Paket zaten kurulu olduğu klasörde.');
            }
            if ($exists) {
                File::deleteDirectory($target);
            }
            self::copy($dir, $target);
            Extensions::forget();

            return ['key' => $key, 'version' => $tool->data['version'], 'dir' => $target, 'updated' => $exists];
        } finally {
            File::deleteDirectory($temp);
        }
    }

    /** Kaynağı okunur bir klasöre çevirir (zip açılır, katalogdaki paket indirilir). */
    private static function fetch(string $source, string $temp, ?string $catalog): string
    {
        if (is_dir($source)) {
            return $source;
        }
        if (is_file($source)) {
            return self::unzip($source, $temp.'/paket');
        }
        if (! preg_match(ToolManifest::KEY, $source)) {
            throw ValidationException::field('source', "Klasör, zip ya da katalogdaki bir anahtar olmalı: {$source}");
        }
        if ($catalog === null) {
            Marketplace::refresh(true);
        }
        $entry = Marketplace::find($source, $catalog);
        if ($entry === null) {
            throw ValidationException::field('source', "Katalogda yok: {$source} (klasör ya da zip ile kurulabilir).");
        }
        if (! str_starts_with($entry['download'], 'https://') || ! preg_match('/^[0-9a-f]{64}$/', $entry['sha256'])) {
            throw ValidationException::field('source', "Katalog kaydı eksik: {$source} (https indirme adresi ve sha256 gerekli).");
        }
        $response = Http::timeout(60)->get($entry['download']);
        if (! $response->successful()) {
            throw ValidationException::field('source', "Paket indirilemedi ({$response->status()}): {$entry['download']}");
        }
        if (! hash_equals($entry['sha256'], hash('sha256', $response->body()))) {
            throw ValidationException::field('source', 'İndirilen paketin özeti katalogdakiyle aynı değil; kurulmadı.');
        }
        File::ensureDirectoryExists($temp);
        file_put_contents($zip = $temp.'/paket.zip', $response->body());

        return self::unzip($zip, $temp.'/paket');
    }

    private static function unzip(string $file, string $dir): string
    {
        $zip = new ZipArchive;
        if ($zip->open($file) !== true) {
            throw ValidationException::field('source', "Zip açılamadı: {$file}");
        }
        // Zip'teki yol klasör dışına çıkamaz (../, mutlak yol).
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (preg_match('#(^|/)\.\.(/|$)#', $name) || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
                $zip->close();
                throw ValidationException::field('source', "Pakette geçersiz yol: {$name}");
            }
        }
        File::ensureDirectoryExists($dir);
        $zip->extractTo($dir);
        $zip->close();

        return $dir;
    }

    /** Tanımın olduğu klasör: kök ya da tek alt klasör. */
    private static function locate(string $dir): string
    {
        $dir = rtrim($dir, '/\\');
        if (is_file($dir.'/'.ToolManifest::FILE)) {
            return $dir;
        }
        $subs = array_values(array_filter(glob($dir.'/*', GLOB_ONLYDIR) ?: [], static fn ($d) => is_file($d.'/'.ToolManifest::FILE)));
        if (count($subs) === 1) {
            return $subs[0];
        }
        throw ValidationException::field('source', 'Pakette '.ToolManifest::FILE.' bulunamadı.');
    }

    private static function copy(string $from, string $to): void
    {
        File::ensureDirectoryExists($to);
        foreach (new \FilesystemIterator($from, \FilesystemIterator::SKIP_DOTS) as $item) {
            /** @var \SplFileInfo $item */
            if (in_array($item->getFilename(), self::SKIP, true)) {
                continue;
            }
            $target = $to.'/'.$item->getFilename();
            $item->isDir() ? self::copy($item->getPathname(), $target) : copy($item->getPathname(), $target);
        }
    }
}
