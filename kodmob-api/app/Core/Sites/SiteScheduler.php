<?php

namespace App\Core\Sites;

use App\Core\Jobs\Scheduler;
use App\Core\Modules;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alt sitelerin zamanlanmış görevleri (çok site, 4. aşama). Sunucuda tek cron vardır (ana site
 * `schedule:run`); her dakika `sites:schedule` görevi alt siteleri sırayla dolaşır ve her birinin vakti
 * gelen görevlerini o sitenin bağlamında çalıştırır (Sites::run): cron nabzı, kuyruk (e-postalar),
 * otomatik yedek, eski kayıtların silinmesi, sitenin açık modüllerinin görevleri. Üst üste binme kilitleri
 * de sitenin önbelleğinde. Bir sitenin hatası ötekileri durdurmaz (günlüğe yazılır).
 */
final class SiteScheduler
{
    public static function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => self::runDue())->name('sites:schedule')->everyMinute()->withoutOverlapping(15);
    }

    /** @return array<string,list<string>|array{error:string}> site → çalışan görevler */
    public static function runDue(): array
    {
        $results = [];
        foreach (array_keys(SiteContext::registry((string) Sites::current()['main_storage'])) as $key) {
            try {
                $results[$key] = Sites::run($key, static function () use ($key) {
                    $schedule = new Schedule(config('app.timezone'));
                    Scheduler::define($schedule, array_map(static fn (string $class) => new $class, Modules::classes()));
                    $ran = [];
                    foreach ($schedule->dueEvents(app()) as $event) {
                        if (! $event->filtersPass(app())) {
                            continue;
                        }
                        try {
                            $event->run(app());
                            $ran[] = (string) $event->description;
                        } catch (Throwable $e) {
                            Log::error("[Kodmob site {$key}] {$event->description}: ".$e->getMessage());
                        }
                    }

                    return $ran;
                });
            } catch (Throwable $e) {
                Log::error("[Kodmob site {$key}] zamanlanmış görevler: ".$e->getMessage());
                $results[$key] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }
}
