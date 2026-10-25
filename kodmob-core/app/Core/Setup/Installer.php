<?php

namespace App\Core\Setup;

use App\Core\Exceptions\ValidationException;

/**
 * Yeni projenin yerel kurulumu (`php artisan kodmob:install`): şablon indirildikten / klonlandıktan sonra tek komut.
 *
 *   .env           yoksa .env.example'dan; yerel değerler (SQLite, e-posta günlüğe, hata ayıklama açık, .local)
 *   anahtarlar     APP_KEY ve JWT_SECRET boşsa üretilir (var olanlara dokunulmaz)
 *   veritabanı     SQLite dosyası (kodmob-core/database/database.sqlite); MAMP ya da MySQL gerekmez
 *   profil         başlangıç profilinden projenin profili (kodmob-frontend/src/profiles/<ad>/) ve kodmob-frontend/profile.js
 *   kilit          kodmob.lock.json yoksa kurulu çatı sürümü işaretlenir (kodmob:upgrade için)
 *
 * Tablolar ve ilk yönetici komutun kendisinde (ayrı süreçte; yeni .env okunsun).
 */
final class Installer
{
    /** Yeni .env'in yerel değerleri (dosya yoksa ya da --local ile). */
    public const LOCAL = [
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'true',
        'DB_CONNECTION' => 'sqlite',
        'MAIL_MAILER' => 'log',
        'LOG_CHANNEL' => 'single',
        'LOG_LEVEL' => 'debug',
    ];

    public function __construct(private readonly string $root) {}

    public function api(string $path = ''): string
    {
        return $this->root.'/kodmob-core'.($path !== '' ? '/'.$path : '');
    }

    /**
     * .env'i hazırlar.
     *
     * @return list<string> yapılanlar (ekranda gösterilir; gizli değer yazılmaz)
     */
    public function env(string $url, bool $local): array
    {
        $done = [];
        $file = $this->api('.env');
        if (! is_file($file)) {
            if (! is_file($this->api('.env.example'))) {
                throw ValidationException::field('env', '.env.example yok: kodmob-core klasörü eksik.');
            }
            copy($this->api('.env.example'), $file);
            $done[] = '.env oluşturuldu (.env.example\'dan)';
            $local = true;
        }
        if ($local) {
            $sqlite = $this->api('database/database.sqlite');
            $values = self::LOCAL + [
                'APP_URL' => rtrim($url, '/'),
                'DB_DATABASE' => str_replace('\\', '/', $sqlite),
                'SITE_ROOT' => str_replace('\\', '/', $this->root.'/.local/public_html'),
                'MEDIA_ROOT' => str_replace('\\', '/', $this->root.'/.local/public_html/media'),
            ];
            foreach ($values as $key => $value) {
                self::setEnv($file, $key, $value);
            }
            if (! is_file($sqlite)) {
                @mkdir(dirname($sqlite), 0755, true);
                touch($sqlite);
            }
            $done[] = 'yerel ayarlar: SQLite ('.basename($sqlite).'), e-posta günlüğe, adres '.$values['APP_URL'];
        }
        if (self::envValue($file, 'APP_KEY') === '') {
            self::setEnv($file, 'APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
            $done[] = 'APP_KEY üretildi';
        }
        if (self::envValue($file, 'JWT_SECRET') === '') {
            self::setEnv($file, 'JWT_SECRET', bin2hex(random_bytes(32)));
            $done[] = 'JWT_SECRET üretildi';
        }

        return $done;
    }

    /**
     * Projenin profili: başlangıç profilinin kopyası (yoksa) ve etkin profil (kodmob-frontend/profile.js).
     *
     * @return list<string>
     */
    public function profile(string $name): array
    {
        if (! preg_match('/^[a-z][a-z0-9-]{1,30}$/', $name)) {
            throw ValidationException::field('profile', 'Profil adı küçük harf, rakam ve tireden oluşmalı (ör. benim-sitem).');
        }
        $profiles = $this->root.'/kodmob-frontend/src/profiles';
        $target = $profiles.'/'.$name;
        $done = [];
        if (! is_dir($target)) {
            if (! is_dir($profiles.'/starter')) {
                throw ValidationException::field('profile', 'Başlangıç profili yok: kodmob-frontend/src/profiles/starter');
            }
            self::copyDir($profiles.'/starter', $target);
            $done[] = "profil oluşturuldu: kodmob-frontend/src/profiles/{$name} (başlangıçtan)";
        }
        $selector = $this->root.'/kodmob-frontend/profile.js';
        $source = (string) @file_get_contents($selector);
        $updated = preg_replace("/(KM_PROFILE \\|\\| ')[a-z0-9_-]+(')/", '${1}'.$name.'${2}', $source, 1, $count);
        if (! $count) {
            throw ValidationException::field('profile', 'kodmob-frontend/profile.js beklenen biçimde değil (KM_PROFILE || \'…\').');
        }
        if ($updated !== $source) {
            file_put_contents($selector, $updated);
            $done[] = "etkin profil: {$name} (kodmob-frontend/profile.js)";
        }

        return $done;
    }

    /** Kurulu çatı sürümünü işaretler (kilit yoksa). */
    public function lock(): ?string
    {
        if (! is_file($this->root.'/'.FrameworkFiles::MANIFEST)) {
            return null;
        }
        $framework = FrameworkFiles::load($this->root);
        if ($framework->lock() !== null) {
            return null;
        }
        $files = $framework->scan();
        $framework->writeLock($files);

        return 'kodmob.lock.json yazıldı (çatı '.$framework->manifest['version'].', '.count($files).' dosya)';
    }

    /** .env'de anahtarın değeri (tırnaksız); yoksa boş. */
    public static function envValue(string $file, string $key): string
    {
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $text, $m)) {
            return '';
        }

        return trim(trim($m[1]), "\"'");
    }

    /** .env'de anahtarı yazar (varsa değiştirir, yoksa sona ekler). */
    public static function setEnv(string $file, string $key, string $value): void
    {
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        $line = $key.'='.(preg_match('/[\s#"\']/', $value) ? '"'.addcslashes($value, '"\\').'"' : $value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $text = preg_match($pattern, $text) ? (string) preg_replace_callback($pattern, static fn () => $line, $text, 1) : rtrim($text, "\r\n")."\n".$line."\n";
        file_put_contents($file, $text);
    }

    private static function copyDir(string $from, string $to): void
    {
        @mkdir($to, 0755, true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? @mkdir($target, 0755, true) : copy($item->getPathname(), $target);
        }
    }
}
