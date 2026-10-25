<?php

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Core\Extensions\Extensions;
use App\Core\Extensions\ToolInstaller;
use App\Core\Extensions\ToolManifest;
use App\Core\Extensions\ToolScaffold;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Settings\SettingsRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use ZipArchive;

/**
 * Araç paketleri (Core\Extensions; docs/arac-paketi.md). Paket klasörü testte tests/fixtures/extensions:
 * içindeki `merhaba` `kodmob:tool new merhaba --label=Merhaba --author=Kodmob` çıktısıdır.
 */
class ExtensionsTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/extensions';

    private string $temp;

    protected function setUp(): void
    {
        // Paketler uygulama açılırken yüklenir (modüller, uçlar): klasör ondan önce bilinsin.
        $path = realpath(self::FIXTURES);
        putenv('KODMOB_EXTENSIONS='.$path);
        $_ENV['KODMOB_EXTENSIONS'] = $_SERVER['KODMOB_EXTENSIONS'] = $path;
        Extensions::forget();
        parent::setUp();
        $this->temp = sys_get_temp_dir().'/km-test-tools-'.uniqid();
        File::ensureDirectoryExists($this->temp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp);
        putenv('KODMOB_EXTENSIONS');
        unset($_ENV['KODMOB_EXTENSIONS'], $_SERVER['KODMOB_EXTENSIONS']);
        Extensions::forget();
        parent::tearDown();
    }

    public function test_installed_tool_is_a_module_with_capability_setting_and_routes(): void
    {
        $this->assertContains('merhaba', Modules::installed());
        $this->assertContains('merhaba', Modules::keys(), 'kurulu paket açık başlar');
        $this->assertArrayHasKey('km_use_merhaba', Capability::all());
        $this->assertNotNull(SettingsRegistry::get('merhaba.greeting'));

        $h = $this->admin();
        $this->getJson('/v1/tools/merhaba', $h)->assertOk()->assertJsonPath('message', 'Merhaba!');
        $this->getJson('/v1/tools/merhaba', $this->as('editor'))->assertForbidden();
        $this->getJson('/v1/tools/merhaba')->assertUnauthorized();

        $tools = $this->getJson('/v1/system/tools', $h)->assertOk()->assertJsonPath('problems', [])->json('items');
        $item = collect($tools)->firstWhere('key', 'merhaba');
        $this->assertEquals(['source' => 'extension', 'kind' => 'tool', 'version' => '0.1.0', 'author' => 'Kodmob', 'license' => 'MIT', 'installed' => true, 'enabled' => true], array_intersect_key($item, array_flip(['source', 'kind', 'version', 'author', 'license', 'installed', 'enabled'])));

        // Paketin uçları da çatının kurallarına uyar: her uç `cap:` ister.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_contains($route->uri(), 'tools/merhaba')) {
                $this->assertNotEmpty(array_filter($route->gatherMiddleware(), static fn ($m) => is_string($m) && str_starts_with($m, 'cap:km_use_merhaba')), $route->uri());
                $this->assertContains('module:merhaba', $route->gatherMiddleware());
            }
        }
    }

    public function test_tool_turns_off_per_project(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/system/tools/merhaba', ['enabled' => false], $h)->assertOk()->assertJsonPath('enabled', false);
        $this->assertNotContains('merhaba', Modules::keys());
        $this->getJson('/v1/tools/merhaba', $h)->assertNotFound();
        $this->putJson('/v1/system/tools/merhaba', ['enabled' => true], $h)->assertOk();
        $this->getJson('/v1/tools/merhaba', $h)->assertOk();
    }

    public function test_broken_incompatible_and_conflicting_packages_are_not_loaded(): void
    {
        File::copyDirectory(self::FIXTURES.'/merhaba', $this->temp.'/eski');
        $this->rewrite($this->temp.'/eski', ['key' => 'eski', 'kodmob' => '>=1.0 <2']);
        File::copyDirectory(self::FIXTURES.'/merhaba', $this->temp.'/jarvis');
        $this->rewrite($this->temp.'/jarvis', ['key' => 'jarvis']);
        File::ensureDirectoryExists($this->temp.'/bozuk');
        file_put_contents($this->temp.'/bozuk/kodmob-tool.json', '{"key": "bozuk"');
        File::ensureDirectoryExists($this->temp.'/tanimsiz'); // tanımı olmayan klasör paket sayılmaz

        config(['kodmob.extensions_path' => $this->temp]);
        Extensions::forget();
        $this->assertSame([], Extensions::loaded());
        $problems = Extensions::problems();
        $this->assertSame(['bozuk', 'eski', 'jarvis'], array_keys($problems));
        $this->assertStringContainsString('uyumsuz', $problems['eski']);
        $this->assertStringContainsString('çakışıyor', $problems['jarvis']);
        $this->assertStringContainsString('bozuk', $problems['bozuk']);
    }

    /** Modülün modülü (`requires`): takıldığı araç kapalıyken çalışmaz ve açılamaz; durumu korunur. */
    public function test_tool_that_requires_another_follows_it(): void
    {
        File::copyDirectory(self::FIXTURES.'/merhaba', $this->temp.'/merhaba');
        ToolScaffold::create($this->temp, 'merhaba-ek', 'Merhaba ek');
        $this->rewrite($this->temp.'/merhaba-ek', ['requires' => ['merhaba']]);
        config(['kodmob.extensions_path' => $this->temp]);
        Extensions::forget();
        $h = $this->admin();

        $this->assertSame(['merhaba'], Modules::requires('merhaba-ek'));
        $this->assertSame(['merhaba-ek'], Modules::dependents('merhaba'));
        $this->assertContains('merhaba-ek', Modules::keys());

        $this->putJson('/v1/system/tools/merhaba', ['enabled' => false], $h)->assertOk();
        $this->assertNotContains('merhaba-ek', Modules::keys(), 'takıldığı araç kapalı');
        $item = collect($this->getJson('/v1/system/tools', $h)->json('items'))->firstWhere('key', 'merhaba-ek');
        $this->assertSame([false, true, ['merhaba']], [$item['enabled'], $item['waiting'], $item['requires']]);

        $this->putJson('/v1/system/tools/merhaba-ek', ['enabled' => true], $h)->assertStatus(422)->assertJsonPath('details.fields.key', 'Merhaba ek için önce Merhaba açılmalı.');
        $this->putJson('/v1/system/tools/merhaba', ['enabled' => true], $h)->assertOk();
        $this->assertContains('merhaba-ek', Modules::keys(), 'yeniden açılınca kendi durumuyla döner');

        // Kendine ya da geçersiz anahtara takılamaz.
        $this->rewrite($this->temp.'/merhaba-ek', ['requires' => ['merhaba-ek']]);
        Extensions::forget();
        $this->assertArrayHasKey('merhaba-ek', Extensions::problems());
    }

    public function test_manifest_rules_and_version_constraints(): void
    {
        $this->assertTrue(ToolManifest::satisfies('>=2.4 <3', '2.4.0'));
        $this->assertTrue(ToolManifest::satisfies('^2.4', '2.9.1'));
        $this->assertFalse(ToolManifest::satisfies('^2.5', '2.4.0'));
        $this->assertFalse(ToolManifest::satisfies('^2.4', '3.0.0'));
        $this->assertFalse(ToolManifest::satisfies('>=2.4.1', '2.4.0'));
        $this->assertTrue(ToolManifest::satisfies('2.4', '2.4.0'));
        $this->assertFalse(ToolManifest::satisfies('her sürüm', '2.4.0'));

        File::copyDirectory(self::FIXTURES.'/merhaba', $dir = $this->temp.'/merhaba');
        $this->rewrite($dir, ['namespace' => 'App\\Merhaba', 'version' => '1', 'author' => '']);
        try {
            ToolManifest::read($dir);
            $this->fail('geçersiz tanım okunmamalı');
        } catch (ValidationException $e) {
            $this->assertSame(['author', 'version', 'namespace'], array_keys($e->details()['fields']));
        }
    }

    public function test_scaffold_matches_the_fixture_and_passes_check(): void
    {
        $files = ToolScaffold::files('merhaba', 'Merhaba', 'Kodmob');
        $fixture = collect(File::allFiles(self::FIXTURES.'/merhaba'))->mapWithKeys(fn ($f) => [str_replace('\\', '/', $f->getRelativePathname()) => $f->getContents()])->sortKeys()->all();
        ksort($files);
        $this->assertSame(array_keys($fixture), array_keys($files), 'iskelet değişti: tests/fixtures/extensions/merhaba yeniden üretilmeli');
        // Yıl ve uyumlu sürüm üretildiği güne göre değişir.
        $same = static fn (string $text) => preg_replace(['/Copyright \(c\) \d{4}/', '/"kodmob": "[^"]*"/'], ['Copyright (c) YIL', '"kodmob": "X"'], str_replace("\r\n", "\n", $text));
        foreach ($files as $path => $content) {
            $this->assertSame($same($fixture[$path]), $same($content), $path);
        }

        $this->artisan('kodmob:tool', ['action' => 'new', 'key' => 'stok-takibi', '--label' => 'Stok takibi', '--path' => $this->temp])->assertExitCode(0);
        $this->assertFileExists($this->temp.'/stok-takibi/php/StokTakibiModule.php');
        $this->assertStringContainsString("'km_use_stok_takibi'", (string) file_get_contents($this->temp.'/stok-takibi/php/StokTakibiModule.php'));
        $this->artisan('kodmob:tool', ['action' => 'check', 'key' => 'stok-takibi', '--path' => $this->temp])->assertExitCode(0);
        $this->artisan('kodmob:tool', ['action' => 'new', 'key' => 'jarvis', '--path' => $this->temp])->assertExitCode(1);
        $this->artisan('kodmob:tool', ['action' => 'new', 'key' => 'stok-takibi', '--path' => $this->temp])->assertExitCode(1);
    }

    public function test_add_installs_from_folder_zip_and_catalog(): void
    {
        $root = $this->temp.'/kurulu';
        $source = self::FIXTURES.'/merhaba';
        $this->assertSame(['key' => 'merhaba', 'version' => '0.1.0', 'dir' => $root.'/merhaba', 'updated' => false], ToolInstaller::add($source, $root));
        $this->assertFileExists($root.'/merhaba/php/routes.php');
        try {
            ToolInstaller::add($source, $root);
            $this->fail('kurulu paketin üstüne --update olmadan yazılmamalı');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('--update', $e->getMessage());
        }
        $this->assertTrue(ToolInstaller::add($source, $root, true)['updated']);

        // GitHub'ın "Download ZIP"i gibi tek üst klasörlü zip.
        $zip = $this->zip($source, 'merhaba-main/');
        $this->assertSame('merhaba', ToolInstaller::add($zip, $this->temp.'/zipten')['key']);
        $this->assertFileExists($this->temp.'/zipten/merhaba/kodmob-tool.json');

        // Zip klasör dışına yazamaz.
        $evil = $this->temp.'/kotu.zip';
        $archive = new ZipArchive;
        $archive->open($evil, ZipArchive::CREATE);
        $archive->addFromString('../kacak.php', '<?php');
        $archive->close();
        try {
            ToolInstaller::add($evil, $this->temp.'/kotu');
            $this->fail('../ içeren zip açılmamalı');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('geçersiz yol', $e->getMessage());
        }

        // Katalog: indirilen zip'in özeti tutmalı.
        $body = (string) file_get_contents($zip);
        $catalog = $this->temp.'/catalog.json';
        file_put_contents($catalog, json_encode(['tools' => [
            ['key' => 'merhaba', 'label' => 'Merhaba', 'download' => 'https://paket.test/merhaba.zip', 'sha256' => hash('sha256', $body)],
            ['key' => 'sahte', 'label' => 'Sahte', 'download' => 'https://paket.test/sahte.zip', 'sha256' => str_repeat('0', 64)],
        ]]));
        Http::fake(['paket.test/*' => Http::response($body)]);
        $this->assertSame('merhaba', ToolInstaller::add('merhaba', $this->temp.'/katalog', false, $catalog)['key']);
        try {
            ToolInstaller::add('sahte', $this->temp.'/katalog', false, $catalog);
            $this->fail('özeti tutmayan paket kurulmamalı');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('özeti', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->temp.'/katalog/sahte');
    }

    public function test_add_command(): void
    {
        $this->artisan('kodmob:add', ['source' => self::FIXTURES.'/merhaba', '--path' => $this->temp])->assertExitCode(0);
        $this->artisan('kodmob:add', ['source' => self::FIXTURES.'/merhaba', '--path' => $this->temp])->assertExitCode(1);
        $this->artisan('kodmob:add', ['source' => 'katalogda-yok', '--path' => $this->temp])->assertExitCode(1);
    }

    /** Kopyalanan paketin tanımında alanları değiştirir. */
    private function rewrite(string $dir, array $fields): void
    {
        $file = $dir.'/kodmob-tool.json';
        file_put_contents($file, json_encode(array_merge(json_decode((string) file_get_contents($file), true), $fields)));
    }

    private function zip(string $dir, string $prefix): string
    {
        $file = $this->temp.'/paket-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE);
        foreach (File::allFiles($dir) as $f) {
            $zip->addFile($f->getPathname(), $prefix.str_replace('\\', '/', $f->getRelativePathname()));
        }
        $zip->close();

        return $file;
    }
}
