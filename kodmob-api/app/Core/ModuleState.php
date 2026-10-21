<?php

namespace App\Core;

/**
 * Projenin araç durumu (Araç Pazarı; docs/done-panel-yapisi-raporu.md §6): çalışma anında, projenin depolamasında
 * (`storage/framework/modules.json`; alt projede kendi depolaması). Derleme (profil) ya da kayıt hangi isteğe bağlı
 * modüllerin kurulu olduğunu söyler; bu dosya hangilerinin bu projede kapalı olduğunu (`disabled`) ve hangi çatı
 * araçlarının panelden eklendiğini (`added`; derlemeyi değiştirmeden). Modül listesi uygulama açılırken gerektiği
 * için veritabanı değil dosya.
 */
final class ModuleState
{
    /** @var array<string,array{disabled:list<string>,added:list<string>}> dosya → durum */
    private static array $cache = [];

    /** @return list<string> bu projede kapatılan modüller */
    public static function disabled(): array
    {
        return self::state()['disabled'];
    }

    /** @return list<string> panelden eklenen çatı araçları (profilde olmayan) */
    public static function added(): array
    {
        return self::state()['added'];
    }

    public static function set(string $key, bool $enabled): void
    {
        $state = self::state();
        $state['disabled'] = array_values(array_diff($state['disabled'], [$key]));
        if (! $enabled) {
            $state['disabled'][] = $key;
        }
        self::write($state);
    }

    /** Çatı aracını panelden ekler (açık gelir) ya da çıkarır. */
    public static function add(string $key, bool $added): void
    {
        $state = self::state();
        $state['added'] = array_values(array_diff($state['added'], [$key]));
        if ($added) {
            $state['added'][] = $key;
            $state['disabled'] = array_values(array_diff($state['disabled'], [$key]));
        }
        self::write($state);
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    /** @return array{disabled:list<string>,added:list<string>} */
    private static function state(): array
    {
        $file = self::file();
        if (! array_key_exists($file, self::$cache)) {
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $list = static fn ($value) => array_values(array_filter(array_map('strval', (array) $value)));
            self::$cache[$file] = ['disabled' => $list($data['disabled'] ?? []), 'added' => $list($data['added'] ?? [])];
        }

        return self::$cache[$file];
    }

    /** @param array{disabled:list<string>,added:list<string>} $state */
    private static function write(array $state): void
    {
        sort($state['disabled']);
        sort($state['added']);
        $file = self::file();
        @mkdir(dirname($file), 0755, true);
        // Eklenen yoksa dosya eski biçimde kalır (yalnızca `disabled`).
        file_put_contents($file, json_encode($state['added'] ? $state : ['disabled' => $state['disabled']], JSON_PRETTY_PRINT)."\n", LOCK_EX);
        self::$cache[$file] = $state;
    }

    private static function file(): string
    {
        return storage_path('framework/modules.json');
    }
}
