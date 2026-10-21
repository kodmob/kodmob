<?php

namespace App\Core\Sites;

use App\Core\Database\DatabaseMover;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Alt siteyi ayrı kuruluma dışa alma (çok site, 5. aşama; `php artisan kodmob:site export`). Klasöre:
 *
 *   database.sqlite     sitenin veritabanı (SQLite alt site; ya da --db ile verilen hedefe kodmob:db-move gibi taşınır)
 *   storage/            dil ve SEO yapılandırması, yedekler (geçici dosyalar, günlükler ve sayfa önbelleği hariç)
 *   media/<anahtar>/    görseller; yeni kurulumda MEDIA_URL=/media/<anahtar> ile içerikteki adresler aynı kalır
 *   OKUBENI.txt         yeni sunucudaki adımlar
 *
 * Kaynak yalnız okunur; alt site kayıtta ve çalışır kalır (taşıma bitince elle kayıttan çıkarılır).
 */
final class SiteExporter
{
    private const SOURCE = 'km_site_export';

    /** Depolamada dışa alınmayanlar (yeni kurulumda kendiliğinden oluşur). */
    private const SKIP = ['framework/cache', 'framework/sessions', 'framework/views', 'framework/pages', 'logs', 'database.sqlite'];

    /** @return array{dir:string,database:string,tables:int,rows:int,media:int,storage:int} */
    public static function export(string $key, string $dir, ?string $dbUrl = null): array
    {
        if (! Sites::isMain()) {
            throw new DomainException('km_site_main_only', 'Siteler yalnızca ana siteden yönetilir.', 403);
        }
        $main = (string) Sites::current()['main_storage'];
        $entry = SiteContext::registry($main)[$key] ?? throw new DomainException('km_site_not_found', "Kayıtlı olmayan site: {$key}.", 404);
        $site = SiteContext::describe($key, $entry, $main);
        $dir = rtrim($dir, '/\\');
        if (is_dir($dir) && (new \FilesystemIterator($dir))->valid()) {
            throw ValidationException::field('dir', "Hedef klasör boş değil: {$dir}");
        }
        File::ensureDirectoryExists($dir);

        // Veritabanı: SQLite dosyası kopyalanır ya da verilen hedefe taşınır.
        $config = Sites::connection($site);
        $tables = $rows = 0;
        if ($dbUrl !== null && $dbUrl !== '') {
            config(['database.connections.'.self::SOURCE => $config]);
            try {
                $counts = (new DatabaseMover)->move(self::SOURCE, DatabaseMover::connectionFromUrl($dbUrl));
            } finally {
                DB::purge(self::SOURCE);
                DB::purge(DatabaseMover::TARGET);
            }
            [$tables, $rows, $database] = [count($counts), array_sum($counts), preg_replace('#//([^:/@]+):[^@]*@#', '//$1:***@', $dbUrl)];
        } elseif ($config['driver'] === 'sqlite') {
            File::copy($config['database'], $dir.'/database.sqlite');
            $database = 'database.sqlite';
        } else {
            throw ValidationException::field('db', 'MySQL/PostgreSQL alt site için hedef gerekir: --db="mysql://…" (ya da veritabanını sunucuda yedekleyip taşıyın).');
        }

        $storage = 0;
        foreach (File::allFiles($site['storage']) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            if (array_filter(self::SKIP, static fn ($skip) => $rel === $skip || str_starts_with($rel, $skip.'/'))) {
                continue;
            }
            File::ensureDirectoryExists(dirname($dir.'/storage/'.$rel));
            File::copy($file->getPathname(), $dir.'/storage/'.$rel);
            $storage++;
        }

        $media = 0;
        $source = rtrim(Paths::mediaRoot(), '/\\').'/'.$key;
        if (is_dir($source)) {
            File::copyDirectory($source, $dir.'/media/'.$key);
            // Canlı şeridin geçici dosyaları (Live\PulseFile, live-…) taşınmaz; yeni kurulum kendisi yazar.
            foreach (glob($dir.'/media/'.$key.'/live-*', GLOB_ONLYDIR) ?: [] as $pulse) {
                File::deleteDirectory($pulse);
            }
            $media = count(File::allFiles($dir.'/media/'.$key));
        }

        file_put_contents($dir.'/OKUBENI.txt', self::readme($site, $database));

        return ['dir' => $dir, 'database' => $database, 'tables' => $tables, 'rows' => $rows, 'media' => $media, 'storage' => $storage];
    }

    private static function readme(array $site, string $database): string
    {
        $key = $site['key'];
        $sqlite = $database === 'database.sqlite';

        return implode("\n", [
            "{$site['name']} ({$key}) — ayrı kurulum için dışa alındı: ".Clock::atom(Clock::now()),
            '',
            'İçerik',
            $sqlite ? '  database.sqlite        sitenin veritabanı (SQLite)' : "  veritabanı             {$database} adresine taşındı",
            '  storage/               kodmob-core/storage/ içine (dil ve SEO yapılandırması, yedekler)',
            "  media/{$key}/          public_html/media/{$key}/ olarak (içerikteki görsel adresleri aynı kalır)",
            '',
            'Yeni sunucuda',
            '1. Ana kurulumla aynı sürümün paketini yükleyin (release/OKUBENI.txt adımları).',
            "2. storage/ içeriğini kodmob-core/storage/'a, media/{$key}/ klasörünü public_html/media/'ya kopyalayın.",
            '3. kodmob-core/.env:',
            $sqlite ? '     DB_CONNECTION=sqlite ve DB_DATABASE=<kodmob-core yolu>/storage/database.sqlite (dosyayı oraya koyun)' : '     DB_* değerleri taşınan veritabanının bilgileri',
            "     MEDIA_URL=/media/{$key}",
            "     MEDIA_ROOT=<public_html yolu>/media/{$key}",
            '     APP_URL='.($site['url'] ?: 'https://<alan adı>'),
            '     APP_KEY: ana kurulumunkiyle aynı olursa panelde şifreli saklanan değerler (SMTP, SMS, yapay zekâ',
            '     anahtarları, alt site veritabanı şifreleri) çalışmaya devam eder; farklıysa bunlar yeniden girilir.',
            '4. Kurulum adresini açın (/api/v1/setup?token=…): tablolar güncellenir; kullanıcılar ve içerik hazırdır.',
            '   Oturum anahtarı değiştiği için herkes yeniden giriş yapar.',
            '5. Sitenin kendi derlemesi varsa (tv/sites/'.$key.'/), yeni projede o katman ana derleme olur.',
            '6. Alan adını yeni sunucuya yönlendirin, sonra ana kurulumda siteyi kayıttan çıkarın (Siteler > Kayıttan çıkar).',
            '',
        ]);
    }
}
