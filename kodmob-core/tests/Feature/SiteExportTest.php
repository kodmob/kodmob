<?php

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Core\Sites\SiteExporter;
use App\Core\Sites\SiteRegistry;
use App\Core\Support\Paths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Alt siteyi ayrı kuruluma dışa alma (Core\Sites\SiteExporter, kodmob:site export): veritabanı, depolama,
 * görseller ve adımlar; kaynak site yerinde ve çalışır kalır.
 */
class SiteExportTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/km-export-'.uniqid();
        SiteRegistry::create([
            'key' => 'demo', 'name' => 'Demo', 'domains' => ['demo.kodmob.test'],
            'admin' => ['username' => 'demo-yonetici', 'email' => 'demo@kodmob.test', 'password' => 'gizli-sifre-123'],
        ]);
        File::ensureDirectoryExists(Paths::mediaRoot().'/demo/2026/10');
        file_put_contents(Paths::mediaRoot().'/demo/2026/10/gorsel.jpg', 'jpg');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    public function test_sqlite_site_is_exported_with_storage_media_and_steps(): void
    {
        $result = SiteExporter::export('demo', $this->out);

        $this->assertSame(['database.sqlite', 1], [$result['database'], $result['media']]);
        $pdo = new \PDO('sqlite:'.$this->out.'/database.sqlite');
        $this->assertSame('demo-yonetici', $pdo->query('select username from users')->fetchColumn());
        $pdo = null;
        $this->assertFileExists($this->out.'/media/demo/2026/10/gorsel.jpg');
        $this->assertFileExists($this->out.'/storage/framework/i18n/config.json');
        $this->assertDirectoryDoesNotExist($this->out.'/storage/framework/views');
        $this->assertFileDoesNotExist($this->out.'/storage/database.sqlite');
        $readme = (string) file_get_contents($this->out.'/OKUBENI.txt');
        $this->assertStringContainsString('MEDIA_URL=/media/demo', $readme);
        $this->assertStringContainsString('APP_URL=https://demo.kodmob.test', $readme);
        $this->assertSame('demo', SiteRegistry::get('demo', false)['key'], 'site kayıtta kalır');

        // Dolu klasöre yazılmaz.
        $this->expectException(ValidationException::class);
        SiteExporter::export('demo', $this->out);
    }

    public function test_database_can_be_moved_to_a_target_and_command_reports(): void
    {
        File::ensureDirectoryExists($this->out);
        touch($target = $this->out.'-hedef.sqlite');
        try {
            $this->artisan('kodmob:site', ['action' => 'export', 'key' => 'demo', '--to' => $this->out.'/paket', '--db' => 'sqlite:'.$target])
                ->expectsOutputToContain('demo dışa alındı')->assertExitCode(0);
            $pdo = new \PDO('sqlite:'.$target);
            $this->assertSame('demo-yonetici', $pdo->query('select username from users')->fetchColumn());
            $pdo = null;
            $this->assertFileDoesNotExist($this->out.'/paket/database.sqlite');
            $this->assertStringContainsString('adresine taşındı', (string) file_get_contents($this->out.'/paket/OKUBENI.txt'));
        } finally {
            @unlink($target);
        }
    }
}
