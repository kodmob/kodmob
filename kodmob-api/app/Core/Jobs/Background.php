<?php

namespace App\Core\Jobs;

use App\Core\Support\Clock;
use App\Core\Support\Options;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Arka plan işleri (e-posta, ileride çeviri, görsel işleme…): ziyaretçiyi ve paneli bekletmeden,
 * başarısız olursa yeniden denenerek çalışır.
 *
 * - Cron kuruluysa (Hostinger > Cron Jobs: her dakika `schedule:run`) iş veritabanı kuyruğuna
 *   (`jobs`) girer; zamanlanmış görev her dakika kuyruğu boşaltır (`queue:work --stop-when-empty`).
 *   Başarısız iş 3 kez denenir, sonra `failed_jobs`'a düşer (Ayarlar > Arka plan: yeniden dene / sil).
 * - Cron yoksa ya da durduysa (son çalışma 3 dakikadan eski) iş aynı istekte, yanıt
 *   gönderildikten sonra çalışır (`afterResponse`): ziyaretçi beklemez, iş takılıp kalmaz; ama
 *   yeniden deneme yoktur.
 * - `QUEUE_CONNECTION=sync` (testler) hemen çalıştırır.
 *
 * Kullanım: `Background::dispatch(new SendMail(...))`. İş sınıfı `ShouldQueue` uygular.
 */
final class Background
{
    private const HEARTBEAT = 'cron_heartbeat';

    /** Cron bu kadar saniyedir çalışmadıysa durmuş sayılır. */
    private const STALE_AFTER = 180;

    public static function dispatch(object $job): void
    {
        if (config('queue.default') === 'sync') {
            self::runNow($job);
        } elseif (self::cronAlive()) {
            dispatch($job);
        } else {
            app()->terminating(static fn () => self::runNow($job));
        }
    }

    /** Kuyruksuz tek deneme: hata çağırana sıçramaz (işin `failed()`'ı çalışır, günlüğe yazılır). */
    private static function runNow(object $job): void
    {
        try {
            dispatch_sync($job);
        } catch (Throwable $e) {
            Log::error(sprintf('[Kodmob] Arka plan işi başarısız: %s — %s', class_basename($job), $e->getMessage()));
        }
    }

    /** Zamanlanmış görevler (AppServiceProvider): nabız ve kuyruğun boşaltılması. */
    public static function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => Options::set(self::HEARTBEAT, Clock::now()))->name('cron:heartbeat')->everyMinute();
        // Süreç başlatmadan (proc_open kapalı paylaşımlı sunucular): aynı süreçte, en çok 50 sn.
        $schedule->call(static fn () => Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3, '--backoff' => 60]))
            ->name('queue:drain')->everyMinute()->withoutOverlapping(5);
        $schedule->call(static fn () => Artisan::call('queue:prune-failed', ['--hours' => 24 * 30]))->name('queue:prune-failed')->dailyAt('03:50');
    }

    public static function lastCron(): ?string
    {
        $at = Options::get(self::HEARTBEAT);

        return is_string($at) && $at !== '' ? $at : null;
    }

    public static function cronAlive(): bool
    {
        $at = self::lastCron();

        return $at !== null && Clock::carbon($at)->diffInSeconds(Clock::carbon(Clock::now()), true) <= self::STALE_AFTER;
    }

    /** Panel özeti: cron, kuyrukta bekleyen ve başarısız işler. */
    public static function status(): array
    {
        $count = static function (string $table): int {
            try {
                return DB::table($table)->count();
            } catch (Throwable) {
                return 0;
            }
        };

        return [
            'connection' => (string) config('queue.default'),
            'cron' => ['last' => self::lastCron() ? Clock::atom(self::lastCron()) : null, 'alive' => self::cronAlive()],
            'pending' => $count('jobs'),
            'failed' => $count('failed_jobs'),
        ];
    }

    /** @return list<array{uuid:string,name:string,error:string,failed_at:string}> */
    public static function failed(int $limit = 50): array
    {
        try {
            $rows = DB::table('failed_jobs')->orderByDesc('id')->limit($limit)->get();
        } catch (Throwable) {
            return [];
        }

        return $rows->map(static function ($row) {
            $payload = json_decode((string) $row->payload, true) ?: [];

            return [
                'uuid' => (string) $row->uuid,
                'name' => self::label((string) ($payload['displayName'] ?? 'İş')),
                'error' => mb_substr(strtok((string) $row->exception, "\n") ?: '', 0, 300),
                'failed_at' => Clock::atom((string) $row->failed_at),
            ];
        })->all();
    }

    public static function retry(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Log::info('[Kodmob] Başarısız iş yeniden kuyrukta: '.$uuid);
    }

    public static function forget(string $uuid): void
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
    }

    /** Sınıf adından okunur ad: işin kendi `label()`'ı, yoksa sınıfın kısa adı. */
    private static function label(string $class): string
    {
        return class_exists($class) && method_exists($class, 'label') ? (string) $class::label() : class_basename($class);
    }
}
