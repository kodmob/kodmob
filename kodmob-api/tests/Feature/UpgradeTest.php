<?php

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Core\Setup\FrameworkFiles;
use App\Core\Setup\FrameworkPackage;
use App\Core\Setup\Upgrader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Çatı paketi ve güncelleme (kodmob:pack, kodmob:upgrade): projede değişmemiş çatı dosyası yenilenir,
 * değişmiş olanın yenisi yanına yazılır, projenin dosyasına dokunulmaz, satır sonu farkı değişiklik sayılmaz.
 */
class UpgradeTest extends TestCase
{
    private const MANIFEST = ['name' => 'kodmob', 'framework' => ['app/**', 'readme.md'], 'project' => ['app/Project/**'], 'exclude' => ['**/*.log']];

    /** @param array<string,string> $files */
    private function tree(string $dir, string $version, array $files): FrameworkFiles
    {
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/kodmob.json', json_encode(['version' => $version] + self::MANIFEST));
        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($dir.'/'.$path));
            file_put_contents($dir.'/'.$path, $content);
        }

        return FrameworkFiles::load($dir);
    }

    public function test_pack_and_upgrade_respect_project_changes(): void
    {
        $base = $this->storage.'/upgrade-'.uniqid();
        $v1 = ['app/a.php' => 'a1', 'app/b.php' => 'b1', 'app/c.php' => 'c1', 'app/d.php' => 'd1', 'app/e.php' => 'e1', 'app/Project/p.php' => 'p1', 'app/debug.log' => 'x', 'readme.md' => "r1\r\nikinci\r\n"];
        $one = FrameworkPackage::build($this->tree($base.'/v1', '1.0.0', $v1), $base.'/out');
        $this->assertSame(['kodmob-1.0.0.zip', 6, '1.0.0'], [basename($one['file']), $one['count'], $one['version']], 'proje dosyası ve hariç tutulan pakete girmez');

        // Proje 1.0.0 ile kuruldu, sonra kendi değişiklikleri.
        $project = $this->tree($base.'/project', '1.0.0', $v1);
        $project->writeLock(FrameworkPackage::open($one['file'], $base.'/tmp')['lock']['files'], '1.0.0');
        file_put_contents($base.'/project/app/b.php', 'b-proje');
        file_put_contents($base.'/project/app/c.php', 'c-proje');
        unlink($base.'/project/app/d.php');
        file_put_contents($base.'/project/app/Project/p.php', 'p-proje');
        file_put_contents($base.'/project/readme.md', "r1\nikinci\n"); // yalnızca satır sonu

        $v2 = ['app/a.php' => 'a2', 'app/b.php' => 'b2', 'app/c.php' => 'c1', 'app/d.php' => 'd1', 'app/f.php' => 'f2', 'app/Project/p.php' => 'p2', 'readme.md' => "r2\n"];
        $two = FrameworkPackage::build($this->tree($base.'/v2', '2.0.0', $v2), $base.'/out');
        $upgrader = new Upgrader(FrameworkFiles::load($base.'/project'), FrameworkPackage::open($two['file'], $base.'/tmp'));

        $plan = array_column($upgrader->plan(), 'action', 'path');
        $this->assertSame([
            'app/a.php' => 'update', 'app/b.php' => 'conflict', 'app/c.php' => 'keep', 'app/d.php' => 'removed',
            'app/e.php' => 'delete', 'app/f.php' => 'add', 'readme.md' => 'update',
        ], $plan);

        $upgrader->apply($upgrader->plan());
        $read = static fn (string $path) => is_file($base.'/project/'.$path) ? file_get_contents($base.'/project/'.$path) : null;
        $this->assertSame(['a2', 'b-proje', 'b2', 'c-proje', null, null, 'f2', 'p-proje', "r2\n"], [
            $read('app/a.php'), $read('app/b.php'), $read('app/b.php'.Upgrader::NEW_SUFFIX), $read('app/c.php'),
            $read('app/d.php'), $read('app/e.php'), $read('app/f.php'), $read('app/Project/p.php'), $read('readme.md'),
        ]);
        $lock = FrameworkFiles::load($base.'/project')->lock();
        $this->assertSame('2.0.0', $lock['version']);
        $this->assertArrayNotHasKey('app/Project/p.php', $lock['files']);
        $this->assertSame('2.0.0', FrameworkFiles::load($base.'/project')->manifest['version'], 'kodmob.json da yeni sürüm');

        // İkinci kez: yalnızca bilinçli farklar kalır (çakışma yeniden yazılmaz, eşitlenmiş dosya aynı).
        $again = array_column((new Upgrader(FrameworkFiles::load($base.'/project'), FrameworkPackage::open($two['file'], $base.'/tmp')))->plan(), 'action', 'path');
        $this->assertSame(['app/b.php' => 'keep', 'app/c.php' => 'keep', 'app/d.php' => 'removed'], $again);
    }

    /** Komutlar: paket, kilit, deneme (yazmadan), güncelleme; --root ile başka bir depoda. */
    public function test_commands_pack_lock_and_upgrade(): void
    {
        $base = $this->storage.'/upgrade-'.uniqid();
        $this->tree($base.'/v1', '1.0.0', ['app/a.php' => 'a1']);
        $this->tree($base.'/project', '1.0.0', ['app/a.php' => 'a1']);
        $this->artisan('kodmob:lock', ['--root' => $base.'/project'])->assertExitCode(0);
        $this->artisan('kodmob:lock', ['--root' => $base.'/project'])->assertExitCode(1);
        $this->tree($base.'/v2', '2.0.0', ['app/a.php' => 'a2', 'app/b.php' => 'b2']);
        $this->artisan('kodmob:pack', ['--root' => $base.'/v2', '--out' => $base.'/out'])->assertExitCode(0);
        $zip = $base.'/out/kodmob-2.0.0.zip';

        $this->artisan('kodmob:upgrade', ['--root' => $base.'/project', '--from' => $zip, '--dry-run' => true])
            ->expectsOutputToContain('Çatı 1.0.0 → 2.0.0')->assertExitCode(0);
        $this->assertSame('a1', file_get_contents($base.'/project/app/a.php'));
        $this->artisan('kodmob:upgrade', ['--root' => $base.'/project', '--from' => $zip])->assertExitCode(0);
        $this->assertSame(['a2', 'b2'], [file_get_contents($base.'/project/app/a.php'), file_get_contents($base.'/project/app/b.php')]);
        $this->assertSame([], glob($this->app->storagePath('framework').'/kodmob-upgrade-*'), 'açılan paket silinir');
    }

    public function test_upgrade_needs_a_lock_and_a_safe_package(): void
    {
        $base = $this->storage.'/upgrade-'.uniqid();
        $package = FrameworkPackage::build($this->tree($base.'/v1', '1.0.0', ['app/a.php' => 'a1']), $base.'/out');
        try {
            (new Upgrader($this->tree($base.'/project', '1.0.0', ['app/a.php' => 'a1']), FrameworkPackage::open($package['file'], $base.'/tmp')))->plan();
            $this->fail('kilitsiz proje güncellenmemeli');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('kodmob:lock', $e->getMessage());
        }

        $zip = new ZipArchive;
        $zip->open($base.'/evil.zip', ZipArchive::CREATE);
        $zip->addFromString('../evil.php', '<?php');
        $zip->close();
        $this->expectException(ValidationException::class);
        FrameworkPackage::open($base.'/evil.zip', $base.'/tmp');
    }
}
