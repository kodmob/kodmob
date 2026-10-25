<?php

namespace Tests\Feature;

use App\Core\Sites\SiteRegistry;
use App\Core\Sites\Sites;
use App\Core\Sites\SiteScheduler;
use App\Core\Support\Options;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Alt sitelerin zamanlanmış görevleri tek cron'dan (Core\Sites\SiteScheduler): her sitenin nabzı,
 * kuyruğu ve başarısız işleri kendi veritabanında.
 */
class SiteSchedulerTest extends TestCase
{
    private function create(string $key): void
    {
        SiteRegistry::create([
            'key' => $key, 'name' => ucfirst($key), 'domains' => ["{$key}.kodmob.test"], 'modules' => ['blog'],
            'admin' => ['username' => "{$key}-yonetici", 'email' => "{$key}@kodmob.test", 'password' => 'gizli-sifre-123'],
        ]);
    }

    private function child(string $key, string $sql): mixed
    {
        $pdo = new \PDO('sqlite:'.$this->storage.'/sites/'.$key.'/database.sqlite');
        $value = $pdo->query($sql)->fetchColumn();
        $pdo = null;

        return $value;
    }

    public function test_main_cron_runs_each_child_sites_due_tasks_in_its_own_context(): void
    {
        $this->create('bir');
        $this->create('iki');
        $this->assertContains('sites:schedule', array_map(static fn ($e) => $e->description, app(Schedule::class)->events()));

        // Alt sitenin kuyruğunda bekleyen iş: o sitenin cron'u işler (canlıdaki gibi veritabanı kuyruğu).
        // Ana sitenin kuyruğunda da bir iş var ve kuyruk bağlantısı açılmış (önbellekte): ona dokunulmamalı.
        config(['queue.default' => 'database']);
        $job = static fn (string $uuid) => ['queue' => 'default', 'payload' => '{"uuid":"'.$uuid.'","displayName":"Bozuk","job":"Yok\\\\Boyle@handle","maxTries":1,"data":[]}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()];
        DB::table('jobs')->insert($job('ana'));
        $this->assertSame(1, Queue::connection('database')->size());
        Sites::run('bir', static fn () => DB::table('jobs')->insert($job('alt')));
        $results = SiteScheduler::runDue();

        $this->assertSame(['bir', 'iki'], array_keys($results));
        foreach (['bir', 'iki'] as $key) {
            $this->assertContains('cron:heartbeat', $results[$key]);
            $this->assertContains('queue:drain', $results[$key]);
            $this->assertNotNull($this->child($key, "select value from options where name = 'cron_heartbeat'"), "{$key} nabzı");
        }
        $this->assertNull(Options::get('cron_heartbeat'), 'ana sitenin nabzı alt sitelerin cron\'undan yazılmaz');
        $this->assertSame(0, (int) $this->child('bir', 'select count(*) from jobs'), 'iş alt sitenin kuyruğundan alındı');
        $this->assertSame(1, (int) $this->child('bir', 'select count(*) from failed_jobs'), 'başarısız iş alt sitenin tablosunda');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'ana sitenin işi yerinde');
        $this->assertSame(1, Queue::connection('database')->size());
        $this->assertSame('main', Sites::key());
    }
}
