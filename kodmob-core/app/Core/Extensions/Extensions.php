<?php

namespace App\Core\Extensions;

use App\Core\Exceptions\ValidationException;
use App\Core\Module;
use App\Core\Modules;

/**
 * Kurulu araç paketleri (`kodmob-core/extensions/<anahtar>/`; docs/arac-paketi.md). Paket çatının da projenin de
 * değildir (üçüncü sahip): `kodmob:add` kurar, `kodmob:upgrade` dokunmaz.
 *
 * Klasörde olan, tanımı geçerli, çatı sürümüyle uyumlu ve anahtarı çatının modülleriyle çakışmayan paket
 * yüklenir: ad alanı `php/`'ye bağlanır (PSR-4), modülü isteğe bağlı bir araç olur (Core\Modules; Araç
 * Pazarı'nda proje başına açılıp kapanır), uçları `php/routes.php`'den `/v1` altında `module:<anahtar>` ile,
 * tabloları `database/migrations`'tan. Yüklenmeyenin nedeni Araç Pazarı'nda görünür (`problems()`).
 *
 * Paket listesi kurulumun tamamınındır (alt projeler aynı klasörü görür); proje başına kapatma ModuleState'te.
 */
final class Extensions
{
    /** @var array<string,array{loaded:array<string,ToolManifest>,problems:array<string,string>}> klasör → tarama */
    private static array $scanned = [];

    /** @var array<string,string> bağlanmış ad alanı → php klasörü */
    private static array $autoload = [];

    /** Paketlerin klasörü (KODMOB_EXTENSIONS ile değişir; testler). */
    public static function path(): string
    {
        return rtrim((string) (config('kodmob.extensions_path') ?: base_path('extensions')), '/\\');
    }

    /**
     * Yüklenen paketler (anahtar sırasıyla).
     *
     * @return array<string,ToolManifest>
     */
    public static function loaded(): array
    {
        return self::scan()['loaded'];
    }

    /**
     * Klasörde olup yüklenemeyen paketler ve nedenleri.
     *
     * @return array<string,string>
     */
    public static function problems(): array
    {
        return self::scan()['problems'];
    }

    /**
     * Core\Modules için isteğe bağlı araç kayıtları.
     *
     * @return array<string,array{class:class-string<Module>,label:string,description:string,kind:string,requires:list<string>,source:string,version:string,author:string,license:string,homepage:string}>
     */
    public static function modules(): array
    {
        $out = [];
        foreach (self::loaded() as $key => $tool) {
            /** @var class-string<Module> $class */
            $class = $tool->moduleClass();
            $out[$key] = [
                'class' => $class, 'label' => $tool->data['label'], 'description' => $tool->data['description'], 'kind' => 'tool', 'requires' => $tool->requires,
                'source' => 'extension', 'version' => $tool->data['version'], 'author' => $tool->data['author'],
                'license' => $tool->data['license'], 'homepage' => $tool->data['homepage'],
            ];
        }

        return $out;
    }

    /**
     * Uç dosyaları: anahtar → php/routes.php (routes/api.php `module:<anahtar>` ile yükler).
     *
     * @return array<string,string>
     */
    public static function routes(): array
    {
        return array_filter(array_map(static fn (ToolManifest $tool) => $tool->routes(), self::loaded()));
    }

    /** @return list<string> */
    public static function migrations(): array
    {
        return array_values(array_filter(array_map(static fn (ToolManifest $tool) => $tool->migrations(), self::loaded())));
    }

    /** Testler ve kurulum komutu için: klasör yeniden taranır. */
    public static function forget(): void
    {
        self::$scanned = [];
    }

    /** @return array{loaded:array<string,ToolManifest>,problems:array<string,string>} */
    private static function scan(): array
    {
        $root = self::path();
        if (isset(self::$scanned[$root])) {
            return self::$scanned[$root];
        }
        $loaded = $problems = [];
        $version = (string) config('kodmob.version');
        $dirs = is_dir($root) ? (glob($root.'/*', GLOB_ONLYDIR) ?: []) : [];
        sort($dirs);
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (! is_file($dir.'/'.ToolManifest::FILE)) {
                continue;
            }
            try {
                $tool = ToolManifest::read($dir);
            } catch (ValidationException $e) {
                $problems[$name] = $e->getMessage();

                continue;
            }
            if (isset(Modules::OPTIONAL[$name])) {
                $problems[$name] = "Anahtar çatının \"{$name}\" modülüyle çakışıyor; paket yüklenmedi.";
            } elseif (! $tool->compatible($version)) {
                $problems[$name] = "Çatının {$version} sürümüyle uyumsuz (paket: {$tool->data['kodmob']}).";
            } else {
                self::autoload($tool->data['namespace'], $dir.'/php');
                if (! is_subclass_of($tool->moduleClass(), Module::class)) {
                    $problems[$name] = "{$tool->moduleClass()} bir App\\Core\\Module olmalı.";

                    continue;
                }
                $loaded[$name] = $tool;
            }
        }

        return self::$scanned[$root] = ['loaded' => $loaded, 'problems' => $problems];
    }

    /** Ad alanını paketin php/ klasörüne bağlar (Composer gerekmez: sunucuda komut çalıştırılamayabilir). */
    private static function autoload(string $namespace, string $dir): void
    {
        $prefix = $namespace.'\\';
        if (isset(self::$autoload[$prefix])) {
            self::$autoload[$prefix] = $dir;

            return;
        }
        self::$autoload[$prefix] = $dir;
        spl_autoload_register(static function (string $class) use ($prefix) {
            if (! str_starts_with($class, $prefix)) {
                return;
            }
            $file = self::$autoload[$prefix].'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
