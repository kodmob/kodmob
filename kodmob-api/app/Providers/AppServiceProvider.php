<?php

namespace App\Providers;

use App\Core\Events\EventCatalog;
use App\Core\Extensions\Extensions;
use App\Core\Jobs\Scheduler;
use App\Core\Modules;
use App\Core\Search\SearchRegistry;
use App\Core\Security\Capability;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SettingsService;
use App\Core\Sites\Sites;
use App\Core\Sites\SiteScheduler;
use App\Modules\Auth\Services\Jwt;
use App\Modules\Site\Render\Router;
use App\Modules\Site\SiteProfile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Kodmob modüllerini açar: ayar tanımları, olay adları, dinleyiciler ve
 * zamanlanmış görevler (Core\Module). Rotalar routes/api.php'dedir.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Çok site: alt sitenin veritabanı, site kökü, medyası ve modülleri (ana sitede değişiklik yok).
        Sites::configure();
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(Jwt::class, fn () => Jwt::fromConfig());
    }

    public function boot(): void
    {
        // Çekirdek + config/kodmob.php 'modules' listesinde açık olanlar (Core\Modules).
        $modules = array_map(static fn (string $class) => new $class, Modules::classes());
        SettingsRegistry::reset();
        SearchRegistry::reset();
        Capability::reset();
        foreach ($modules as $module) {
            SettingsRegistry::define(...$module->settings());
            SearchRegistry::add(...$module->search());
            Capability::define($module->capabilities());
            foreach ($module->events() as $type => $definition) {
                EventCatalog::define($type, $definition[0], $definition[1] ?? 'primary', $definition[2] ?? null);
            }
            $module->boot();
        }

        // Araç paketlerinin tabloları (kodmob-api/extensions/<anahtar>/database/migrations): migrate ve kurulum adresi.
        $this->loadMigrationsFrom(Extensions::migrations());

        $this->app->afterResolving(Schedule::class, static function (Schedule $schedule) use ($modules) {
            // Çekirdek (nabız, kuyruk, eski kayıtlar, otomatik yedek) ve açık modüllerin görevleri.
            Scheduler::define($schedule, $modules);
            // Çok site: tek cron; alt sitelerin görevleri her birinin bağlamında (Core\Sites\SiteScheduler).
            if (Sites::isMain()) {
                SiteScheduler::schedule($schedule);
            }
        });

        // Site kimliği (panelde site adı, logo) derlemenin metinlerinin yerine: manifest okunurken.
        Router::useProfile(static fn (array $manifest) => SiteProfile::apply($manifest));

        // Vekil sunucu (Cloudflare vb.) arkasında gerçek ziyaretçi IP'si.
        $proxies = config('kodmob.trusted_proxies');
        if ($proxies) {
            Request::setTrustedProxies(
                $proxies === '*' ? ['0.0.0.0/0', '::/0'] : array_map('trim', explode(',', (string) $proxies)),
                Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO
            );
        }
    }
}
