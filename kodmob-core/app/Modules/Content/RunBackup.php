<?php

namespace App\Modules\Content;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** "Şimdi yedekle" (panel): yedek arka planda alınır (App\Core\Jobs\Background); sonuç ayarlarda. */
final class RunBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public static function label(): string
    {
        return 'Otomatik yedek';
    }

    public function handle(): void
    {
        AutoBackup::run('panel');
    }
}
