<?php

namespace Tests\Feature;

use App\Core\Database\DatabaseMover;
use App\Core\Exceptions\DomainException;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Siteyi başka veritabanına taşıma (kodmob:db-move). Kaynak o anki test veritabanı (kodmob-frontend `npm run test:db`
 * ile SQLite, MySQL ve PostgreSQL), hedef geçici bir SQLite dosyası.
 */
class DatabaseMoveTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/km-move-'.uniqid().'.sqlite';
        touch($this->file);
    }

    protected function tearDown(): void
    {
        DB::purge(DatabaseMover::TARGET);
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_site_moves_with_ids_booleans_and_counts(): void
    {
        $admin = $this->admin();
        $project = $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Taşınan Proje', 'slug' => 'tasinan-proje']]], $admin)->assertCreated()->json('id');
        $this->postJson('/v1/seo/redirects', ['source' => '/eski/', 'target' => '/yeni/', 'code' => 301], $admin)->assertCreated();
        User::query()->create(['username' => 'pasif', 'display_name' => 'Pasif', 'email' => 'pasif@kodmob.test', 'password' => 'gizli-sifre-123', 'role' => 'editor', 'status' => User::PASSIVE]);
        $source = [
            'users' => DB::table('users')->count(),
            'contents' => DB::table('contents')->count(),
            'languages' => DB::table('languages')->count(),
            'events' => DB::table('events')->count(),
        ];

        $counts = (new DatabaseMover)->move((string) config('database.default'), DatabaseMover::connectionFromUrl('sqlite:'.$this->file));
        $target = DB::connection(DatabaseMover::TARGET);
        foreach ($source as $table => $rows) {
            $this->assertSame($rows, $counts[$table], $table);
            $this->assertSame($rows, $target->table($table)->count(), $table);
        }
        $this->assertSame('Taşınan Proje', $target->table('contents')->where('id', $project)->value('title'), 'kimlik korunur');
        $redirect = $target->table('seo_redirects')->first();
        $this->assertSame([true, false], [(bool) $redirect->active, (bool) $redirect->is_regex]);
        $this->assertArrayNotHasKey('migrations', $counts);
        $this->assertSame((int) $target->table('migrations')->count(), count(glob(base_path('database/migrations/*.php'))), 'hedefte şema migration\'larla kurulur');

        // Hedefte veri varken durur; --force ile baştan kurar.
        try {
            (new DatabaseMover)->move((string) config('database.default'), DatabaseMover::connectionFromUrl('sqlite:'.$this->file));
            $this->fail('dolu hedefe yazmamalı');
        } catch (DomainException $e) {
            $this->assertSame('km_db_move_not_empty', $e->errorCode());
        }
        $again = (new DatabaseMover)->move((string) config('database.default'), DatabaseMover::connectionFromUrl('sqlite:'.$this->file), true);
        $this->assertSame($counts, $again);
    }

    public function test_target_urls(): void
    {
        $pg = DatabaseMover::connectionFromUrl('pgsql://site_user:%C5%9Fifre%40@127.0.0.1:5433/kodmob');
        $this->assertSame(['pgsql', '127.0.0.1', '5433', 'kodmob', 'site_user', 'şifre@'], [$pg['driver'], $pg['host'], $pg['port'], $pg['database'], $pg['username'], $pg['password']]);
        $this->assertSame('3306', DatabaseMover::connectionFromUrl('mysql://root@localhost/site')['port']);
        $this->assertSame(['sqlite', 'C:/yol/site.sqlite'], array_values(array_intersect_key(DatabaseMover::connectionFromUrl('sqlite:C:/yol/site.sqlite'), array_flip(['driver', 'database']))));
        $this->expectException(DomainException::class);
        DatabaseMover::connectionFromUrl('oracle://x/y');
    }

    public function test_command_reports_and_needs_a_target(): void
    {
        $this->artisan('kodmob:db-move')->expectsOutputToContain('Hedef gerekli')->assertExitCode(1);
        $this->artisan('kodmob:db-move', ['--to' => 'sqlite:'.$this->file])->expectsOutputToContain('Bitti:')->assertExitCode(0);
    }
}
