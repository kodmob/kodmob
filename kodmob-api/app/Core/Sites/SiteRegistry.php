<?php

namespace App\Core\Sites;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Modules;
use App\Core\Support\Clock;
use App\Core\Support\Files;
use App\Core\Support\Secret;
use App\Modules\Auth\Models\User;
use App\Modules\I18n\I18nConfig;
use App\Modules\Seo\Services\SeoConfig;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Alt site kaydı (<ana depolama>/sites.json) ve kurulumu: panelde Siteler, komut satırında kodmob:site.
 * Yalnızca ana sitede çalışır.
 *
 *   create   kayıt + depolama + veritabanı (SQLite dosyası / pgsql şeması) + tablolar + dil ve SEO
 *            yapılandırması + ilk yönetici. Bir adım tutmazsa kayıt geri alınır, yeniden denenebilir.
 *   update   ad, alan adları, adres, modüller (veritabanı bilgisi değişmez)
 *   migrate  sürüm güncellemesinden sonra alt sitelerin tabloları (ana sitenin kurulum adresi de yapar)
 *   remove   yalnızca kayıttan çıkarır; veritabanı, depolama ve görseller silinmez
 */
final class SiteRegistry
{
    private const PROBE = 'km_site_probe';

    private const HOST_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Projenin panel adresi (proje seçici, geçiş bağlantısı): kayıttaki adres, yoksa ilk alan adı. İstek standart
     * dışı bir bağlantı noktasındaysa (yerel geliştirme: 127.0.0.1:8090, *.localhost) alan adı bu isteğin şeması
     * ve bağlantı noktasıyla kullanılır; sitenin yerel deneme adresi (`anahtar.localhost`) varsa o (gerçek alan adı
     * yerelde açılmaz).
     *
     * @param  array<string,mixed>  $site  list() / get() satırı
     */
    public static function panelUrl(array $site, string $scheme, int $port): string
    {
        $domain = (string) ($site['domains'][0] ?? '');
        if (! in_array($port, [80, 443], true)) {
            foreach ((array) ($site['domains'] ?? []) as $host) {
                if (str_ends_with((string) $host, '.localhost')) {
                    $domain = (string) $host;
                    break;
                }
            }
        }
        $base = ! in_array($port, [80, 443], true) && $domain !== ''
            ? $scheme.'://'.$domain.':'.$port
            : ((string) ($site['url'] ?? '') ?: ($domain !== '' ? 'https://'.$domain : ''));

        return $base !== '' ? rtrim($base, '/').'/panel/' : '';
    }

    /** @return list<array<string,mixed>> panel ve komut satırı için (şifre yok) */
    public static function list(bool $status = true): array
    {
        $main = self::mainStorage();
        $out = [];
        foreach (SiteContext::registry($main) as $key => $entry) {
            $out[] = self::view(SiteContext::describe($key, $entry, $main), $entry, $status);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public static function get(string $key, bool $status = true): array
    {
        $entry = self::entries()[$key] ?? throw new DomainException('km_site_not_found', "Kayıtlı olmayan site: {$key}.", 404);

        return self::view(SiteContext::describe($key, $entry, self::mainStorage()), $entry, $status);
    }

    /**
     * @param  array<string,mixed>  $input  key, name, domains[], url?, modules?[], db{driver,host,port,database,username,password,schema}?, admin{username,email,password,display_name?}
     * @return array<string,mixed>
     */
    public static function create(array $input): array
    {
        self::guard();
        $key = strtolower(trim((string) ($input['key'] ?? '')));
        $errors = [];
        if (! preg_match(SiteContext::KEY_PATTERN, $key) || $key === SiteContext::MAIN) {
            $errors['key'] = 'Anahtar 1–32 karakter: küçük harf, rakam ve tire (başta ve sonda tire olmaz); "main" kullanılamaz.';
        } elseif (isset(self::entries()[$key])) {
            $errors['key'] = 'Bu anahtarla bir site zaten var.';
        }
        $entry = self::fields($input, $key, $errors);
        $entry['db'] = self::database((array) ($input['db'] ?? []), $errors);
        $admin = self::admin((array) ($input['admin'] ?? []), $errors);
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        $entry['created_at'] = Clock::atom(Clock::now());

        $site = SiteContext::describe($key, $entry, self::mainStorage());
        SiteContext::prepare($site['storage']);
        self::prepareDatabase($site);

        self::write(static fn (array $sites) => $sites + [$key => $entry]);
        try {
            Sites::run($key, static function () use ($admin) {
                self::migrateCurrent();
                $user = User::query()->where('username', $admin['username'])->first() ?? new User(['username' => $admin['username']]);
                $user->fill($admin + ['role' => User::ADMIN, 'status' => User::ACTIVE, 'password_changed_at' => Clock::now()])->save();
            });
        } catch (Throwable $e) {
            self::write(static function (array $sites) use ($key) {
                unset($sites[$key]);

                return $sites;
            });
            throw new DomainException('km_site_setup_failed', 'Site kurulamadı: '.$e->getMessage().' Kayıt geri alındı; sorunu giderip yeniden deneyin.', 422, previous: $e);
        }

        return self::get($key);
    }

    /** @param array<string,mixed> $input name, domains[], url?, modules?[] */
    public static function update(string $key, array $input): array
    {
        self::guard();
        $current = self::entries()[$key] ?? throw new DomainException('km_site_not_found', "Kayıtlı olmayan site: {$key}.", 404);
        $errors = [];
        $fields = self::fields($input + ['name' => $current['name'] ?? $key, 'domains' => $current['domains'] ?? []], $key, $errors);
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        self::write(static function (array $sites) use ($key, $fields, $input) {
            $entry = ['name' => $fields['name'], 'domains' => $fields['domains']] + $sites[$key];
            // Gönderilmeyen alan olduğu gibi kalır; boş adres ve null modül listesi kaydı varsayılana döndürür.
            foreach (['url', 'modules'] as $field) {
                if (array_key_exists($field, $input)) {
                    unset($entry[$field]);
                    if (isset($fields[$field])) {
                        $entry[$field] = $fields[$field];
                    }
                }
            }
            $sites[$key] = $entry;

            return $sites;
        });

        return self::get($key);
    }

    /** Kayıttan çıkarır; veriler yerinde kalır (yeniden kaydedilince site geri gelir). */
    public static function remove(string $key): array
    {
        self::guard();
        $site = self::get($key, false);
        self::write(static function (array $sites) use ($key) {
            unset($sites[$key]);

            return $sites;
        });

        return $site;
    }

    /**
     * Alt sitelerin tabloları (sürüm güncellemesi). Bir sitenin hatası ötekileri durdurmaz.
     *
     * @return list<array{key:string,ok:bool,output:string}>
     */
    public static function migrate(?string $only = null): array
    {
        self::guard();
        $keys = $only !== null ? [self::get($only, false)['key']] : array_keys(self::entries());
        $results = [];
        foreach ($keys as $key) {
            try {
                $results[] = ['key' => $key, 'ok' => true, 'output' => Sites::run($key, static fn () => self::migrateCurrent())];
            } catch (Throwable $e) {
                $results[] = ['key' => $key, 'ok' => false, 'output' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** Sitenin bağlamında: tablolar, render.php'nin okuduğu SEO ve dil yapılandırması (kurulum adresiyle aynı). */
    private static function migrateCurrent(): string
    {
        $output = new BufferedOutput;
        Artisan::call('migrate', ['--force' => true], $output);
        SeoConfig::compile();
        I18nConfig::compile();

        return trim($output->fetch());
    }

    // ---------------------------------------------------------------- doğrulama

    /** @return array<string,mixed> name, domains, url?, modules? */
    private static function fields(array $input, string $key, array &$errors): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Site adı gerekli (en çok 100 karakter).';
        }
        $domains = [];
        foreach ((array) ($input['domains'] ?? []) as $domain) {
            $host = SiteContext::host((string) $domain);
            if ($host !== '' && ! in_array($host, $domains, true)) {
                $domains[] = $host;
            }
        }
        $taken = self::takenHosts($key);
        if (! $domains) {
            $errors['domains'] = 'En az bir alan adı yazın (ör. demo.kodmob.com).';
        } elseif ($bad = array_values(array_filter($domains, static fn ($h) => ! preg_match(self::HOST_PATTERN, $h)))) {
            $errors['domains'] = 'Geçersiz alan adı: '.implode(', ', $bad).'. Yalnızca alan adı yazın (https:// ve / olmadan).';
        } elseif ($ip = array_values(array_filter($domains, static fn ($h) => (bool) preg_match('/^.+\.\d{1,3}(\.\d{1,3}){3}$/', $h)))) {
            // IP adresinin alt adı (deneme.127.0.0.1) hiçbir yere çözülmez: tarayıcıda açılmaz.
            $errors['domains'] = $ip[0].' tarayıcıda açılmaz (IP adresinin alt adı olmaz). Yerelde '.explode('.', $ip[0])[0].'.localhost yazın.';
        } elseif ($used = array_values(array_intersect($domains, array_keys($taken)))) {
            $errors['domains'] = implode(', ', array_map(static fn ($h) => "{$h} ({$taken[$h]})", $used)).' zaten kullanılıyor.';
        }
        $entry = ['name' => $name, 'domains' => $domains];

        $url = rtrim(trim((string) ($input['url'] ?? '')), '/');
        if ($url !== '') {
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($url, PHP_URL_PATH)) {
                $errors['url'] = 'Adres http:// ya da https:// ile başlayan bir alan adı olmalı (yol olmadan).';
            }
            $entry['url'] = $url;
        }
        if (array_key_exists('modules', $input) && $input['modules'] !== null) {
            $modules = array_values(array_unique(array_map('strval', (array) $input['modules'])));
            if ($unknown = array_diff($modules, array_keys(Modules::OPTIONAL))) {
                $errors['modules'] = 'Bilinmeyen modül: '.implode(', ', $unknown).'.';
            }
            $entry['modules'] = array_values(array_intersect(array_keys(Modules::OPTIONAL), $modules));
        }

        return $entry;
    }

    /** @return array<string,string> alan adı → kullanan site (ana site dahil) */
    private static function takenHosts(string $except): array
    {
        $taken = [];
        foreach (self::entries() as $key => $entry) {
            if ($key !== $except) {
                foreach ((array) ($entry['domains'] ?? []) as $host) {
                    $taken[SiteContext::host((string) $host)] = $key;
                }
            }
        }
        // Ana sitenin alan adı (ve panelin açıldığı adres) alt siteye verilirse ana site kapanır.
        foreach ([(string) parse_url((string) config('app.url'), PHP_URL_HOST), app()->runningInConsole() ? '' : request()->getHost()] as $host) {
            if (($host = SiteContext::host($host)) !== '') {
                $taken[$host] = SiteContext::MAIN;
            }
        }

        return $taken;
    }

    /** @return array<string,string> kayda yazılacak veritabanı bilgisi (şifre şifreli) */
    private static function database(array $db, array &$errors): array
    {
        $driver = (string) ($db['driver'] ?? 'sqlite') ?: 'sqlite';
        if (! in_array($driver, Sites::DRIVERS, true)) {
            $errors['db.driver'] = 'Veritabanı türü: sqlite, mysql, mariadb ya da pgsql.';

            return ['driver' => 'sqlite'];
        }
        if ($driver === 'sqlite') {
            // Komut satırından özel dosya yolu verilebilir; panel varsayılanı kullanır.
            return array_filter(['driver' => 'sqlite', 'database' => trim((string) ($db['database'] ?? ''))]);
        }
        $out = ['driver' => $driver];
        foreach (['host' => 'Sunucu', 'database' => 'Veritabanı adı', 'username' => 'Kullanıcı adı'] as $field => $label) {
            $out[$field] = trim((string) ($db[$field] ?? ''));
            if ($out[$field] === '') {
                $errors['db.'.$field] = $label.' gerekli.';
            }
        }
        $port = trim((string) ($db['port'] ?? ''));
        if ($port !== '' && ! preg_match('/^\d{1,5}$/', $port)) {
            $errors['db.port'] = 'Port bir sayı olmalı.';
        }
        if ($port !== '') {
            $out['port'] = $port;
        }
        $password = (string) ($db['password'] ?? '');
        if ($password !== '') {
            $out['password'] = str_starts_with($password, 'enc:') ? $password : Secret::seal($password);
        }
        $schema = trim((string) ($db['schema'] ?? ''));
        if ($schema !== '') {
            if ($driver !== 'pgsql' || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $schema)) {
                $errors['db.schema'] = 'Şema yalnızca PostgreSQL için; küçük harf, rakam ve alt çizgi.';
            }
            $out['schema'] = $schema;
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function admin(array $admin, array &$errors): array
    {
        $username = trim((string) ($admin['username'] ?? ''));
        $email = mb_strtolower(trim((string) ($admin['email'] ?? '')));
        $password = (string) ($admin['password'] ?? '');
        if (! preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            $errors['admin.username'] = 'Kullanıcı adı 3–60 karakter: harf, rakam, nokta, tire ve alt çizgi.';
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['admin.email'] = 'Geçerli bir e-posta adresi yazın.';
        }
        if (mb_strlen($password) < 10) {
            $errors['admin.password'] = 'Şifre en az 10 karakter olmalı.';
        }
        $name = trim((string) ($admin['display_name'] ?? '')) ?: $username;

        return ['username' => mb_strtolower($username), 'email' => $email, 'password' => $password, 'display_name' => mb_substr($name, 0, 120)];
    }

    // ---------------------------------------------------------------- veritabanı

    /** SQLite dosyası / PostgreSQL şeması açılır, bağlantı denenir; ana sitenin veritabanı ve dolu veritabanı reddedilir. */
    private static function prepareDatabase(array $site): void
    {
        $config = Sites::connection($site);
        if ($config['driver'] === 'sqlite') {
            if (! is_file($config['database']) && ! @touch($config['database'])) {
                throw new DomainException('km_site_db_failed', 'SQLite dosyası oluşturulamadı: '.$config['database'], 422);
            }
        }
        $main = (array) config('database.connections.'.config('database.default'));
        if (self::sameDatabase($config, $main)) {
            throw ValidationException::field('db.database', 'Bu, ana sitenin veritabanı. Alt site için ayrı bir veritabanı (ya da PostgreSQL\'de ayrı şema) gerekir.');
        }
        try {
            if ($config['driver'] === 'pgsql' && ! empty($config['search_path'])) {
                self::probe(['search_path' => 'public'] + $config)->statement('create schema if not exists "'.$config['search_path'].'"');
                DB::purge(self::PROBE);
            }
            $db = self::probe($config);
            $tables = array_column(Schema::connection(self::PROBE)->getTables($config['driver'] === 'pgsql' ? ($config['search_path'] ?? 'public') : null), 'name');
            if ($tables && ! in_array('migrations', $tables, true)) {
                throw ValidationException::field('db.database', 'Veritabanı boş değil ('.count($tables).' tablo) ve Kodmob\'a ait görünmüyor. Boş bir veritabanı seçin.');
            }
            $db->getPdo();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DomainException('km_site_db_failed', 'Veritabanına bağlanılamadı: '.$e->getMessage(), 422, previous: $e);
        } finally {
            DB::purge(self::PROBE);
        }
    }

    private static function probe(array $config): Connection
    {
        DB::purge(self::PROBE);
        config(['database.connections.'.self::PROBE => $config]);

        return DB::connection(self::PROBE);
    }

    private static function sameDatabase(array $a, array $b): bool
    {
        $norm = static fn (array $c) => ($c['driver'] ?? '') === 'sqlite'
            ? ['sqlite', realpath((string) ($c['database'] ?? '')) ?: (string) ($c['database'] ?? '')]
            : [($c['driver'] ?? '') === 'mariadb' ? 'mysql' : ($c['driver'] ?? ''), strtolower((string) ($c['host'] ?? '')), (string) ($c['port'] ?? ''), (string) ($c['database'] ?? ''), (string) ($c['search_path'] ?? 'public')];

        return $norm($a) === $norm($b) && ($a['database'] ?? '') !== ':memory:';
    }

    // ---------------------------------------------------------------- görünüm ve dosya

    /** @return array<string,mixed> */
    private static function view(array $site, array $entry, bool $status): array
    {
        $db = $site['db'];
        $driver = (string) ($db['driver'] ?? 'sqlite');

        return [
            'key' => $site['key'],
            'name' => $site['name'],
            'domains' => $site['domains'],
            'url' => $site['url'],
            'modules' => $site['modules'],
            'db' => array_filter([
                'driver' => $driver,
                'host' => $db['host'] ?? null,
                'database' => $driver === 'sqlite' ? basename((string) ($db['database'] ?? 'database.sqlite')) : ($db['database'] ?? null),
                'schema' => $db['schema'] ?? null,
            ]),
            'created_at' => $entry['created_at'] ?? null,
            'status' => $status ? self::status($site) : null,
        ];
    }

    /** @return array{ok:bool,pending:int,admins:int,error:string} */
    private static function status(array $site): array
    {
        try {
            $db = self::probe(Sites::connection($site));
            if (! Schema::connection(self::PROBE)->hasTable('migrations')) {
                return ['ok' => false, 'pending' => count(self::migrationFiles()), 'admins' => 0, 'error' => 'Tablolar kurulmamış.'];
            }
            $ran = $db->table('migrations')->pluck('migration')->all();
            $pending = count(array_diff(self::migrationFiles(), $ran));
            $admins = Schema::connection(self::PROBE)->hasTable('users') ? $db->table('users')->where('role', User::ADMIN)->where('status', User::ACTIVE)->count() : 0;

            return ['ok' => $pending === 0 && $admins > 0, 'pending' => $pending, 'admins' => $admins, 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'pending' => 0, 'admins' => 0, 'error' => 'Veritabanına bağlanılamadı: '.$e->getMessage()];
        } finally {
            DB::purge(self::PROBE);
        }
    }

    /** @return list<string> */
    private static function migrationFiles(): array
    {
        return array_map(static fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
    }

    /** @return array<string,array<string,mixed>> kayıttaki ham satırlar */
    private static function entries(): array
    {
        $file = self::file();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data['sites'] ?? null) ? $data['sites'] : [];
    }

    /** Kilitli oku-değiştir-yaz (iki yönetici aynı anda site eklerse biri kaybolmasın). */
    private static function write(callable $change): void
    {
        $file = self::file();
        $lock = fopen($file.'.lock', 'c');
        try {
            if ($lock) {
                flock($lock, LOCK_EX);
            }
            $data = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
            $data['sites'] = $change(is_array($data['sites'] ?? null) ? $data['sites'] : []);
            if (! Files::putJson($file, $data)) {
                throw new DomainException('km_site_registry_write', 'Site kaydı yazılamadı: '.$file, 500);
            }
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public static function file(): string
    {
        return self::mainStorage().'/sites.json';
    }

    private static function mainStorage(): string
    {
        return (string) Sites::current()['main_storage'];
    }

    private static function guard(): void
    {
        if (! Sites::isMain()) {
            throw new DomainException('km_site_main_only', 'Siteler yalnızca ana siteden yönetilir.', 403);
        }
    }
}
