<?php

namespace App\Core\Sites;

/**
 * Bu istek hangi sitenin? (tek kurulum, çok site; docs/done-starter-raporu.md §7)
 *
 * Laravel açılmadan çalışır: giriş dosyaları (api/index.php, api/render.php) ve bootstrap/app.php
 * çağırır. Laravel tarafı (veritabanı, site kökü, medya, modüller) Sites::configure()'dadır.
 *
 *   Kayıt   <ana depolama>/sites.json
 *           {"sites": {"demo": {"name": "Demo", "domains": ["demo.kodmob.com"], "db": {"driver": "sqlite"}}}}
 *   Seçim   KODMOB_SITE ortam değişkeni (komut satırı: KODMOB_SITE=demo php artisan migrate) ya da
 *           isteğin alan adı (Host; "www." ve port yok sayılır). Kayıtlı olmayan alan adı ana site.
 *   Alt site depolaması <ana depolama>/sites/<anahtar>: LARAVEL_STORAGE_PATH buraya çevrilir; günlükler,
 *           önbellek, sayfa önbelleği, dil ve SEO yapılandırması, yedekler oraya yazılır.
 *
 * Kayıt dosyası yoksa her şey eskisi gibi tek site (ana site, `main`).
 */
final class SiteContext
{
    public const MAIN = 'main';

    /** Alt site anahtarı: klasör ve alan adı parçası olarak güvenli. */
    public const KEY_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/';

    /** Alt sitenin derlenmiş sayfaları: <site kökü>/_sites/<anahtar> (km-manifest.json, _pages). */
    public const ROOT_DIR = '_sites';

    /** @var array<string,mixed>|null */
    private static ?array $current = null;

    /**
     * Siteyi bir kez seçer ve depolama yolunu ayarlar; sonraki çağrılar aynı sonucu döner.
     *
     * @return array{key:string,name:string,domains:list<string>,storage:string,main_storage:string,db:array<string,mixed>,modules:?list<string>,url:string}
     */
    public static function boot(string $core): array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $main = self::mainStorage($core);
        $sites = self::registry($main);
        $explicit = self::env('KODMOB_SITE');
        if ($explicit !== '' && $explicit !== self::MAIN && ! isset($sites[$explicit])) {
            throw new \RuntimeException("Kayıtlı olmayan site: {$explicit} ({$main}/sites.json)");
        }
        $key = $explicit !== '' ? $explicit : (self::byHost($sites, (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? self::MAIN);

        // Alt süreçler (ör. kuyruk) aynı siteyle çalışsın; ana depolama da kaybolmasın.
        $_SERVER['KODMOB_SITE'] = $_ENV['KODMOB_SITE'] = $key;
        $_SERVER['KODMOB_MAIN_STORAGE'] = $_ENV['KODMOB_MAIN_STORAGE'] = $main;

        $site = self::describe($key, $sites[$key] ?? [], $main);
        if ($key !== self::MAIN) {
            self::prepare($site['storage']);
            $_SERVER['LARAVEL_STORAGE_PATH'] = $_ENV['LARAVEL_STORAGE_PATH'] = $site['storage'];
        }

        return self::$current = $site;
    }

    /**
     * Kayıt satırından site bilgisi (boot() ve başka sitenin bağlamında çalışma: Sites::run()).
     *
     * @param  array<string,mixed>  $entry
     * @return array{key:string,name:string,domains:list<string>,storage:string,main_storage:string,db:array<string,mixed>,modules:?list<string>,url:string}
     */
    public static function describe(string $key, array $entry, string $mainStorage): array
    {
        $domains = array_values((array) ($entry['domains'] ?? []));

        return [
            'key' => $key,
            'name' => (string) ($entry['name'] ?? ($key === self::MAIN ? '' : $key)),
            'domains' => $domains,
            'storage' => $key === self::MAIN ? $mainStorage : $mainStorage.'/sites/'.$key,
            'main_storage' => $mainStorage,
            'db' => (array) ($entry['db'] ?? []),
            'modules' => isset($entry['modules']) && is_array($entry['modules']) ? array_values(array_map('strval', $entry['modules'])) : null,
            // Kayıtta "url" yoksa ilk alan adı (https).
            'url' => rtrim((string) ($entry['url'] ?? '') ?: ($key !== self::MAIN && $domains ? 'https://'.$domains[0] : ''), '/'),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * Sitenin derlenmiş sayfalarının klasörü: ana site için site kökünün kendisi; alt site kendi derlemesi
     * (_sites/<anahtar>/km-manifest.json) yoksa ana sitenin derlemesini kullanır (ortak tema).
     */
    public static function root(string $mainRoot, ?string $key = null): string
    {
        $key ??= self::$current['key'] ?? self::MAIN;
        $mainRoot = rtrim($mainRoot, '/\\');
        $own = $mainRoot.'/'.self::ROOT_DIR.'/'.$key;

        return $key !== self::MAIN && is_file($own.'/km-manifest.json') ? $own : $mainRoot;
    }

    /** Alt sitenin adresi (kanonik adresler, site haritası, e-postalardaki bağlantılar); ana sitede boş. */
    public static function url(): string
    {
        return (self::$current['key'] ?? self::MAIN) === self::MAIN ? '' : (string) self::$current['url'];
    }

    /**
     * Kayıttaki alt siteler (geçersiz anahtarlar atlanır).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function registry(string $mainStorage): array
    {
        $file = rtrim($mainStorage, '/\\').'/sites.json';
        if (! is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        $sites = [];
        foreach ((array) ($data['sites'] ?? []) as $key => $site) {
            if (is_string($key) && $key !== self::MAIN && preg_match(self::KEY_PATTERN, $key) && is_array($site)) {
                $site['domains'] = array_values(array_filter(array_map(self::host(...), array_map('strval', (array) ($site['domains'] ?? [])))));
                $sites[$key] = $site;
            }
        }

        return $sites;
    }

    /** @param array<string,array<string,mixed>> $sites */
    public static function byHost(array $sites, string $host): ?string
    {
        $host = self::host($host);
        if ($host === '') {
            return null;
        }
        foreach ($sites as $key => $site) {
            if (in_array($host, $site['domains'] ?? [], true)) {
                return $key;
            }
        }

        return null;
    }

    /** Karşılaştırma biçimi: küçük harf, port ve sondaki nokta yok, "www." yok. */
    public static function host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $host = rtrim($host, '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Ana sitenin depolaması: KODMOB_MAIN_STORAGE (alt süreçler), LARAVEL_STORAGE_PATH ya da <çekirdek>/storage. */
    public static function mainStorage(string $core): string
    {
        $path = self::env('KODMOB_MAIN_STORAGE') ?: self::env('LARAVEL_STORAGE_PATH') ?: $core.'/storage';

        return rtrim($path, '/\\');
    }

    /** Yalnızca testler: seçimi unutur (depolama değişkenlerini de geri alır). */
    public static function reset(): void
    {
        if (self::$current !== null && self::$current['key'] !== self::MAIN) {
            unset($_SERVER['LARAVEL_STORAGE_PATH'], $_ENV['LARAVEL_STORAGE_PATH']);
        }
        unset($_SERVER['KODMOB_SITE'], $_ENV['KODMOB_SITE'], $_SERVER['KODMOB_MAIN_STORAGE'], $_ENV['KODMOB_MAIN_STORAGE']);
        self::$current = null;
    }

    /** Laravel'in beklediği klasörler (ilk istekte ya da site kurulurken açılır). */
    public static function prepare(string $storage): void
    {
        if (is_dir($storage.'/framework/views')) {
            return;
        }
        foreach (['app', 'framework/cache/data', 'framework/sessions', 'framework/views', 'framework/pages', 'logs'] as $dir) {
            if (! is_dir($storage.'/'.$dir)) {
                @mkdir($storage.'/'.$dir, 0755, true);
            }
        }
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
