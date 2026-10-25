<?php

namespace App\Core\Sites;

use App\Core\Ai\Ai;
use App\Core\Content\ContentRepository;
use App\Core\Content\Languages;
use App\Core\Content\Types;
use App\Core\Exceptions\DomainException;
use App\Core\ModuleState;
use App\Core\Security\Roles;
use App\Core\Settings\SettingsService;
use App\Core\Support\Options;
use App\Core\Support\PanelUrl;
use App\Core\Support\Secret;
use App\Core\Support\SiteManifest;
use App\Core\Support\UserNames;
use App\Modules\Auth\Services\Jwt;
use App\Modules\Blog\BlogPosts;
use App\Modules\Media\MediaLibrary;
use App\Modules\Site\Legal\LegalDocs;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Seçilen sitenin Laravel ayarları (site seçimi: SiteContext). Ana site için hiçbir şey değişmez;
 * alt sitede (AppServiceProvider::register, başka her şeyden önce):
 *
 *   veritabanı   `site` bağlantısı varsayılan olur. Kayıttaki db: {"driver": "sqlite"} (varsayılan; dosya
 *                <depolama>/database.sqlite) ya da mysql / mariadb / pgsql (host, port, database, username,
 *                password; pgsql'de schema = site başına şema). Şifre düz ya da Secret ile `enc:` önekli.
 *   site kökü    <ana site kökü>/_sites/<anahtar>; sitenin kendi derlemesi yoksa ana sitenin derlemesi
 *   medya        <ana medya klasörü>/<anahtar>, adresi /media/<anahtar>
 *   modüller     kayıtta "modules" varsa o liste (yoksa config/kodmob.php)
 *   adres        "url" ya da ilk alan adı (komut satırında üretilen bağlantılar için; kanonik adresler
 *                Router'da aynı değerle)
 *
 * Oturum jetonlarının imza anahtarı da siteye özeldir (Auth\Services\Jwt): bir sitenin jetonu
 * ötekinde geçmez, kullanıcı kimlikleri çakışsa bile.
 */
final class Sites
{
    public const CONNECTION = 'site';

    public const DRIVERS = ['sqlite', 'mysql', 'mariadb', 'pgsql'];

    /** @return array<string,mixed> */
    public static function current(): array
    {
        return SiteContext::current() ?? SiteContext::boot(base_path());
    }

    public static function key(): string
    {
        return (string) config('kodmob.site', SiteContext::MAIN);
    }

    public static function isMain(): bool
    {
        return self::key() === SiteContext::MAIN;
    }

    public static function configure(): void
    {
        $site = self::current();
        if ($site['key'] === SiteContext::MAIN) {
            config(['kodmob.site' => SiteContext::MAIN]);

            return;
        }
        self::apply($site);
    }

    /** @param array<string,mixed> $site SiteContext::boot() sonucu */
    public static function apply(array $site): void
    {
        $key = (string) $site['key'];
        $root = rtrim((string) (config('kodmob.site_root') ?: dirname(public_path())), '/\\');
        $media = rtrim((string) (config('kodmob.media_root') ?: $root.'/media'), '/\\');
        $connection = self::connection($site);
        // Varsayılan SQLite dosyası ilk istekte açılır: kurulum adresi (/api/v1/setup) tabloları kurabilsin.
        if ($connection['driver'] === 'sqlite' && empty($site['db']['database']) && ! is_file($connection['database']) && is_dir(dirname($connection['database']))) {
            @touch($connection['database']);
        }
        $values = [
            // Ana projenin bağlantısı: paylaşılan sistem ayarları oradan okunur (Support\Options::SHARED).
            'kodmob.main_connection' => (string) (config('kodmob.main_connection') ?: config('database.default')),
            'kodmob.site' => $key,
            'kodmob.site_root' => SiteContext::root($root, $key),
            'kodmob.media_root' => $media.'/'.$key,
            'kodmob.media_url' => rtrim((string) config('kodmob.media_url', '/media'), '/').'/'.$key,
            'database.connections.'.self::CONNECTION => $connection,
            'database.default' => self::CONNECTION,
            // Ortamdaki DB_CONNECTION'dan gelir; alt sitenin başarısız işleri ve iş grupları kendi veritabanında.
            'queue.failed.database' => self::CONNECTION,
            'queue.batching.database' => self::CONNECTION,
        ];
        if ((string) ($site['url'] ?? '') !== '') {
            $values['app.url'] = (string) $site['url'];
        }
        if (is_array($site['modules'] ?? null)) {
            $values['kodmob.modules'] = $site['modules'];
        }
        config($values);
    }

    /**
     * Bir işi alt sitenin bağlamında çalıştırır (ana siteden: alt site kurulumu, migration; ileride site
     * başına zamanlanmış işler). Veritabanı, depolama (günlük ve dosya önbelleği dahil), site kökü, medya,
     * adres, oturum anahtarı ve bellekteki önbellekler o siteye geçer; iş bitince (hata olsa da) geri döner.
     *
     * @template T
     *
     * @param  callable(array<string,mixed>):T  $fn  sitenin bilgisiyle çağrılır
     * @return T
     */
    public static function run(string $key, callable $fn): mixed
    {
        if (! self::isMain()) {
            throw new \LogicException('Başka sitenin bağlamına yalnızca ana siteden geçilir.');
        }
        $main = (string) self::current()['main_storage'];
        $registry = SiteContext::registry($main);
        if (! isset($registry[$key])) {
            throw new DomainException('km_site_not_found', "Kayıtlı olmayan site: {$key}.", 404);
        }
        $site = SiteContext::describe($key, $registry[$key], $main);
        SiteContext::prepare($site['storage']);

        $saved = Arr::only(config()->all(), ['app', 'kodmob', 'database', 'cache', 'logging', 'queue']);
        $storage = app()->storagePath();
        try {
            app()->useStoragePath($site['storage']);
            self::apply($site);
            config([
                'cache.stores.file.path' => $site['storage'].'/framework/cache/data',
                'cache.stores.file.lock_path' => $site['storage'].'/framework/cache/data',
                'logging.channels.single.path' => $site['storage'].'/logs/laravel.log',
                'logging.channels.daily.path' => $site['storage'].'/logs/laravel.log',
            ]);
            self::reconnect();

            return $fn($site);
        } finally {
            config($saved);
            app()->useStoragePath($storage);
            self::reconnect();
        }
    }

    /** Site değişince: bağlantı, önbellek ve günlük sürücüleri, oturum anahtarı, bellekteki kayıtlar. */
    private static function reconnect(): void
    {
        DB::purge(self::CONNECTION);
        app('cache')->forgetDriver();
        app('log')->forgetChannel();
        // Kuyruk yöneticisi bağlantıyı (ve içindeki veritabanı bağlantısını) önbelleğe alır: yeniden kurulsun.
        (function () {
            $this->connections = [];
        })->call(app('queue'));
        app()->forgetInstance('queue.connection');
        app()->forgetInstance('queue.failer');
        app()->forgetInstance(Jwt::class);
        // Kapsayıcıda tek örnek olan ve veritabanından okuduğunu saklayan servisler de (ayarlar).
        app()->forgetInstance(SettingsService::class);
        self::forgetMemory();
    }

    /** İstek içi önbellekler (veritabanından okunmuş ayarlar, roller, diller, kayıtlar, manifest). */
    public static function forgetMemory(): void
    {
        Options::flush();
        ContentRepository::forget();
        MediaLibrary::forget();
        Roles::forget();
        Languages::forget();
        Types::forget();
        PanelUrl::flush();
        SiteManifest::forget();
        ModuleState::forget();
        UserNames::forget();
        Ai::forget();
        BlogPosts::forget();
        LegalDocs::forget();
    }

    /**
     * @param  array<string,mixed>  $site
     * @return array<string,mixed>
     */
    public static function connection(array $site): array
    {
        $db = (array) ($site['db'] ?? []);
        $driver = (string) ($db['driver'] ?? 'sqlite');
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new \RuntimeException("Site {$site['key']}: desteklenmeyen veritabanı sürücüsü \"{$driver}\".");
        }
        // Karakter seti, sıralama, PDO seçenekleri ana sitenin aynı sürücülü bağlantısından.
        $config = (array) config('database.connections.'.$driver, []);
        unset($config['url']);
        $config['driver'] = $driver;
        if ($driver === 'sqlite') {
            $config['database'] = (string) ($db['database'] ?? '') ?: $site['storage'].'/database.sqlite';
            $config['prefix'] = '';

            return $config;
        }
        foreach (['host', 'port', 'database', 'username'] as $field) {
            if (isset($db[$field])) {
                $config[$field] = (string) $db[$field];
            }
        }
        $config['password'] = Secret::open($db['password'] ?? '');
        if ($driver === 'pgsql' && ! empty($db['schema'])) {
            $config['search_path'] = (string) $db['schema'];
        }

        return $config;
    }
}
