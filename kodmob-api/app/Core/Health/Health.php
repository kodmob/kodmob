<?php

namespace App\Core\Health;

use App\Core\Database\DatabaseStats;
use App\Core\Jobs\Background;
use App\Core\Mail\MailLog;
use App\Core\Modules;
use App\Core\Sites\Sites;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sistem Sağlığı: kurulumun tek bakışta özeti, salt okuma. Çekirdek
 * kartları burada (sunucu, hız, veritabanı, depolama, arka plan); modüller kendi kartını `health()`
 * kancasıyla ekler (Güvenlik, Yedekleme, Siteler…). Yeni modül paneli değiştirmeden kart ekler:
 *
 *     public function health(): array
 *     {
 *         return [Health::section('orders', 'Siparişler', 'cart', [['Bekleyen', '12']], [Health::flag('warn', '3 sipariş 2 gündür bekliyor.')])];
 *     }
 *
 * Kart: başlık, ikon, satırlar (etiket, değer), isteğe bağlı liste, bulgular (warn / critical / info)
 * ve ilgili panel sayfası. Kartın düzeyi bulgularının en kötüsüdür; genel düzey kartların en kötüsü.
 */
final class Health
{
    public const LEVELS = ['ok' => 0, 'info' => 0, 'warn' => 1, 'critical' => 2];

    /** @return array{generated_at:string,level:string,sections:list<array<string,mixed>>} */
    public static function report(): array
    {
        // Her kart ayrı: biri okunamazsa (ör. veritabanı yok) yerine hata kartı çıkar, rapor düşmez.
        $sources = ['server' => fn () => [self::server()], 'speed' => fn () => [self::speed()], 'database' => fn () => [self::database()],
            'storage' => fn () => [self::storage()], 'background' => fn () => [self::background()]];
        foreach (Modules::classes() as $class) {
            $sources[class_basename($class)] = fn () => (new $class)->health();
        }
        $sections = [];
        foreach ($sources as $key => $source) {
            try {
                array_push($sections, ...$source());
            } catch (Throwable $e) {
                $sections[] = self::section($key, $key, 'alert', [], [self::flag('critical', 'Bu kart okunamadı: '.mb_substr($e->getMessage(), 0, 200))], ['order' => 90]);
            }
        }
        usort($sections, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return ['generated_at' => Clock::atom(Clock::now()) ?? '', 'level' => self::worst(array_column($sections, 'level')), 'sections' => $sections];
    }

    /**
     * @param  list<array{0:string,1:string}>  $rows  [etiket, değer]
     * @param  list<array{level:string,text:string,fix:string}>  $flags
     * @param  array{list?:array{title:string,items:list<array{0:string,1:string,2?:string}>},link?:array{route:string,tab?:?string,label:string},note?:string,order?:int}  $extra
     * @return array<string,mixed>
     */
    public static function section(string $key, string $title, string $icon, array $rows, array $flags = [], array $extra = []): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'icon' => $icon,
            'level' => self::worst(array_column($flags, 'level')),
            'rows' => array_map(static fn ($r) => ['label' => $r[0], 'value' => $r[1]], $rows),
            'list' => isset($extra['list']) ? ['title' => $extra['list']['title'], 'items' => array_map(static fn ($i) => ['label' => $i[0], 'value' => $i[1], 'hint' => $i[2] ?? ''], $extra['list']['items'])] : null,
            'flags' => $flags,
            'link' => $extra['link'] ?? null,
            'note' => $extra['note'] ?? null,
            'order' => $extra['order'] ?? 100,
        ];
    }

    /** Bulgu: `warn` dikkat, `critical` kritik, `info` yalnızca bilgi (genel düzeyi etkilemez). */
    public static function flag(string $level, string $text, string $fix = ''): array
    {
        return ['level' => $level, 'text' => $text, 'fix' => $fix];
    }

    /** @param  list<string>  $levels */
    public static function worst(array $levels): string
    {
        $worst = 'ok';
        foreach ($levels as $level) {
            if ((self::LEVELS[$level] ?? 0) > self::LEVELS[$worst]) {
                $worst = $level;
            }
        }

        return $worst;
    }

    public static function mb(int|float $bytes): string
    {
        return number_format($bytes / 1048576, $bytes >= 104857600 ? 0 : 1, ',', '.').' MB';
    }

    // ------------------------------------------------------------------ sunucu

    private static function server(): array
    {
        $url = (string) config('app.url');
        $optional = array_column(array_filter(Modules::view(), static fn ($m) => $m['enabled']), 'label');

        return self::section('server', 'Sunucu', 'monitor', [
            ['Adres', (string) (parse_url($url, PHP_URL_HOST) ?: $url).(str_starts_with($url, 'https://') ? ' · HTTPS' : ' · HTTP')],
            ['Ortam', app()->environment('production') ? 'Canlı (production)' : (string) app()->environment()],
            ['Sürüm', 'Kodmob '.config('kodmob.version').' · Laravel '.app()->version().' · PHP '.PHP_VERSION],
            ['Sunucu', mb_substr((string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI), 0, 80)],
            ['Saat dilimi', (string) config('app.timezone')],
            ['Site', Sites::isMain() ? 'Ana site' : 'Alt site · '.Sites::key()],
            ['İsteğe bağlı modüller', $optional ? implode(', ', $optional) : 'Hiçbiri'],
        ], [], ['order' => 10]);
    }

    // --------------------------------------------------------------------- hız

    private static function speed(): array
    {
        $flags = [];
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $on = is_array($opcache) && ! empty($opcache['opcache_enabled']);
        $hit = $on ? round((float) ($opcache['opcache_statistics']['opcache_hit_rate'] ?? 0), 1) : null;
        if (! $on) {
            $flags[] = self::flag('warn', 'OPcache kapalı: PHP her istekte yeniden derleniyor.', 'Hosting panelinin PHP ayarlarından OPcache\'i açın.');
        }
        $memory = (string) ini_get('memory_limit');
        if ($memory !== '-1' && self::bytes($memory) < 128 * 1048576) {
            $flags[] = self::flag('warn', "PHP bellek sınırı düşük ({$memory}).", 'Hosting panelinden memory_limit\'i en az 256M yapın.');
        }
        $upload = min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')));
        if ($upload < 8 * 1048576) {
            $flags[] = self::flag('warn', 'En büyük yükleme '.self::mb($upload).': büyük görseller yüklenemez.', 'upload_max_filesize ve post_max_size en az 16M olmalı.');
        }
        [$pages, $pageBytes] = self::dir(Paths::pageCache(), 20000);

        return self::section('speed', 'Hız', 'zap', [
            ['OPcache', $on ? "Açık · %{$hit} isabet" : 'Kapalı'],
            ['Önbellek', (string) config('cache.default')],
            ['Sayfa önbelleği', number_format($pages, 0, ',', '.').' sayfa · '.self::mb($pageBytes)],
            ['Bellek sınırı', $memory],
            ['En büyük yükleme', self::mb($upload)],
        ], $flags, ['order' => 30]);
    }

    // -------------------------------------------------------------- veritabanı

    private static function database(): array
    {
        try {
            $s = DatabaseStats::summary();
        } catch (Throwable $e) {
            return self::section('database', 'Veritabanı', 'database', [], [self::flag('critical', 'Veritabanı okunamadı: '.mb_substr($e->getMessage(), 0, 200))], ['order' => 40]);
        }
        $flags = [];
        $pending = self::pendingMigrations();
        if ($pending) {
            $flags[] = self::flag('critical', count($pending).' tablo güncellemesi bekliyor: yeni sürümün özellikleri hata verebilir.', 'Kurulum adresini (/api/v1/setup) açın ya da Siteler › Tabloları güncelle.');
        }
        if ($s['free_bytes'] >= 200 * 1048576) {
            $flags[] = self::flag('warn', 'Tablolarda '.self::mb($s['free_bytes']).' boş alan var.', 'phpMyAdmin\'de tabloları seçip "Optimize" ile geri kazanın.');
        }
        $names = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'];
        $rows = [
            ['Sürücü', trim(($names[$s['driver']] ?? $s['driver']).' '.$s['version'])],
            ['Boyut', ($s['bytes'] ? self::mb($s['bytes']).' · ' : '').$s['tables'].' tablo'],
            ['Tablo güncellemeleri', $pending ? count($pending).' bekliyor' : 'Güncel'],
        ];
        if ($s['free_bytes'] > 0) {
            $rows[] = ['Boş alan', self::mb($s['free_bytes'])];
        }
        $items = array_map(static fn ($t) => [$t['table'], $t['bytes'] ? self::mb($t['bytes']) : '', number_format($t['rows'], 0, ',', '.').' satır'], $s['largest']);

        return self::section('database', 'Veritabanı', 'database', $rows, $flags, ['list' => ['title' => 'En büyük tablolar', 'items' => $items], 'order' => 40]);
    }

    /** @return list<string> çalışmamış migration'lar */
    private static function pendingMigrations(): array
    {
        $files = array_map(static fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            return $files;
        }

        return array_values(array_diff($files, $ran));
    }

    // ---------------------------------------------------------------- depolama

    private static function storage(): array
    {
        $flags = [];
        [$files, $bytes, $types] = self::media();
        [$backups, $backupBytes] = self::dir(Paths::backups(), 2000);
        [, $logBytes] = self::dir(storage_path('logs'), 500);
        if ($logBytes > 50 * 1048576) {
            $flags[] = self::flag('warn', 'Sunucu günlükleri '.self::mb($logBytes).'.', 'storage/logs altındaki eski laravel.log dosyalarını silin; hata ayrıntısı için önce Hata kodları\'na bakın.');
        }
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $rows = [
            ['Görseller ve dosyalar', number_format($files, 0, ',', '.').' dosya · '.self::mb($bytes)],
            ['Türler', "{$types['image']} görsel · {$types['pdf']} PDF · {$types['other']} diğer"],
            ['Yedekler', number_format($backups, 0, ',', '.').' dosya · '.self::mb($backupBytes)],
            ['Günlükler', self::mb($logBytes)],
        ];
        if (is_numeric($free) && is_numeric($total) && $total > 0) {
            $rows[] = ['Disk boş / toplam', self::mb($free).' / '.self::mb($total)];
            $ratio = $free / $total;
            if ($ratio < 0.1) {
                $flags[] = self::flag('critical', 'Disk boş alanı %10\'un altında.', 'Eski yedekleri indirip silin ya da hosting paketini büyütün.');
            } elseif ($ratio < 0.2) {
                $flags[] = self::flag('warn', 'Disk boş alanı %20\'nin altında.');
            }
        }

        return self::section('storage', 'Depolama', 'layers', $rows, $flags, ['note' => 'Görseller sitenin medya klasöründe ('.Paths::mediaUrl().'); yedekler ve günlükler sitenin depolamasında, web\'e kapalı.', 'order' => 50]);
    }

    /** @return array{0:int,1:int,2:array{image:int,pdf:int,other:int}} */
    private static function media(): array
    {
        $types = ['image' => 0, 'pdf' => 0, 'other' => 0];
        $count = 0;
        $bytes = 0;
        foreach (self::files(Paths::mediaRoot(), 20000) as $file) {
            $count++;
            $bytes += $file->getSize();
            $ext = strtolower($file->getExtension());
            $types[in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg'], true) ? 'image' : ($ext === 'pdf' ? 'pdf' : 'other')]++;
        }

        return [$count, $bytes, $types];
    }

    // ------------------------------------------------------------- arka plan

    private static function background(): array
    {
        $flags = [];
        $status = Background::status();
        if (! $status['cron']['alive']) {
            $flags[] = self::flag(app()->environment('production') ? 'warn' : 'info', 'Cron çalışmıyor: zamanlanmış işler (otomatik yedek, temizlik) yalnızca panel kullanıldıkça çalışır, başarısız e-postalar yeniden denenmez.', 'Hosting › Cron Jobs: her dakika php …/kodmob-core/artisan schedule:run');
        }
        if ($status['failed'] > 0) {
            $flags[] = self::flag('warn', "{$status['failed']} başarısız arka plan işi var.", 'Ayarlar › Arka plan\'dan yeniden deneyin ya da silin.');
        }
        $since = Clock::carbon(Clock::now())->subDay();
        $failedMail = array_filter(MailLog::recent(50), static fn ($m) => $m['status'] !== 'sent' && Clock::carbon((string) $m['created_at'])->greaterThan($since));
        if ($failedMail) {
            $flags[] = self::flag('warn', 'Son 24 saatte '.count($failedMail).' e-posta gönderilemedi: '.mb_substr((string) (reset($failedMail)['error'] ?? ''), 0, 160), 'Ayarlar › E-posta\'dan ayarları kontrol edip deneme gönderin.');
        }
        $last = $status['cron']['last'];

        return self::section('background', 'Arka plan', 'activity', [
            ['Cron', $status['cron']['alive'] ? 'Çalışıyor' : ($last ? 'Durmuş · son '.Clock::local($last) : 'Hiç çalışmadı')],
            ['Kuyruk', $status['connection'].' · '.$status['pending'].' bekleyen'],
            ['Başarısız iş', (string) $status['failed']],
            ['Gönderilemeyen e-posta (24 sa)', (string) count($failedMail)],
        ], $flags, ['link' => ['route' => 'settings', 'tab' => 'jobs', 'label' => 'Arka plan'], 'order' => 60]);
    }

    // ------------------------------------------------------------------ ortak

    /** @return array{0:int,1:int} dosya sayısı, bayt (en çok $limit dosya gezilir) */
    private static function dir(string $dir, int $limit): array
    {
        $count = 0;
        $bytes = 0;
        foreach (self::files($dir, $limit) as $file) {
            $count++;
            $bytes += $file->getSize();
        }

        return [$count, $bytes];
    }

    /** @return iterable<\SplFileInfo> */
    private static function files(string $dir, int $limit): iterable
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }
        $n = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && ! str_starts_with($file->getFilename(), '.')) {
                    yield $file;
                    if (++$n >= $limit) {
                        return;
                    }
                }
            }
        } catch (Throwable) {
            // Okunamayan klasör: eldeki toplamla dönülür.
        }
    }

    /** "128M" → bayt. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
