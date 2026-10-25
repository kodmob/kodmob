<?php

namespace App\Core\Jobs;

use App\Core\Mail\MailLog;
use App\Core\Module;
use App\Core\Sms\SmsLog;
use App\Modules\Content\AutoBackup;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Bir sitenin zamanlanmış görevleri: çekirdek (cron nabzı, kuyruğun boşaltılması, eski gönderim kayıtları,
 * otomatik yedek) ve açık modüllerinkiler (Core\Module::schedule). Ana site AppServiceProvider'da,
 * alt siteler Core\Sites\SiteScheduler'da (ana sitenin cron'u, sitenin bağlamında) aynı listeyi kurar.
 */
final class Scheduler
{
    /** @param iterable<Module> $modules */
    public static function define(Schedule $schedule, iterable $modules): void
    {
        Background::schedule($schedule);
        $schedule->call(static fn () => MailLog::prune())->name('mail:prune')->dailyAt('03:55');
        $schedule->call(static fn () => SmsLog::prune())->name('sms:prune')->dailyAt('03:56');
        // Otomatik yedek (Ayarlar > Yedekleme): saatte bir bakılır, sırası geldiyse alınır.
        $schedule->call(static fn () => AutoBackup::runIfDue())->name('backup:auto')->hourly()->withoutOverlapping(60);
        foreach ($modules as $module) {
            $module->schedule($schedule);
        }
    }
}
