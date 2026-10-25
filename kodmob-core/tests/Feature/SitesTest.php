<?php

namespace Tests\Feature;

use App\Core\Content\Languages;
use App\Core\Security\Roles;
use App\Core\Sites\SiteContext;
use App\Core\Sites\Sites;
use App\Core\Support\Options;
use App\Core\Support\Paths;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\Jwt;
use App\Modules\Auth\Services\TokenService;
use App\Modules\Site\Render\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Tek kurulum, çok site (Core\Sites): alan adından site seçimi, alt sitenin ayrı depolaması,
 * veritabanı, site kökü, medyası ve oturum anahtarı; komut satırında KODMOB_SITE.
 */
class SitesTest extends TestCase
{
    private string $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->main = sys_get_temp_dir().'/km-sites-'.uniqid();
        File::ensureDirectoryExists($this->main);
        file_put_contents($this->main.'/sites.json', json_encode(['sites' => [
            'demo' => ['name' => 'Demo', 'domains' => ['Demo.Kodmob.test', 'www.ikinci.test'], 'modules' => ['blog'], 'db' => ['driver' => 'sqlite']],
            'Kötü Anahtar' => ['domains' => ['kotu.test']],
            'main' => ['domains' => ['ana.test']],
        ]]));
    }

    protected function tearDown(): void
    {
        $this->restoreMain();
        File::deleteDirectory($this->main);
        parent::tearDown();
    }

    /** Siteyi testin kayıt dosyasıyla seçer. */
    private function boot(?string $host = null, ?string $explicit = null): array
    {
        SiteContext::reset();
        $_SERVER['KODMOB_MAIN_STORAGE'] = $this->main;
        $host !== null ? $_SERVER['HTTP_HOST'] = $host : null;
        // Boş değer, üst süreçten (php artisan test) devralınan KODMOB_SITE=main'i gölgeler: alan adı seçilsin.
        $_SERVER['KODMOB_SITE'] = $explicit ?? '';
        try {
            return SiteContext::boot(base_path());
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }
    }

    private function restoreMain(): void
    {
        SiteContext::reset();
        unset($_SERVER['HTTP_HOST']);
        $_SERVER['KODMOB_MAIN_STORAGE'] = $this->storage;
        SiteContext::boot(base_path());
    }

    public function test_registry_and_host_matching(): void
    {
        $sites = SiteContext::registry($this->main);
        $this->assertSame(['demo'], array_keys($sites), 'geçersiz anahtar ve "main" kayıttan atlanır');
        $this->assertSame(['demo.kodmob.test', 'ikinci.test'], $sites['demo']['domains']);
        foreach (['demo.kodmob.test', 'DEMO.kodmob.test:8090', 'www.demo.kodmob.test', 'ikinci.test.', 'www.ikinci.test'] as $host) {
            $this->assertSame('demo', SiteContext::byHost($sites, $host), $host);
        }
        foreach (['kodmob.test', 'kotu.test', 'ana.test', 'demo.kodmob.test.evil.com', ''] as $host) {
            $this->assertNull(SiteContext::byHost($sites, $host), $host);
        }
        // Alt sitenin kendi derlemesi yoksa ana sitenin derlemesi (ortak tema).
        $root = $this->main.'/public_html';
        $this->assertSame($root, SiteContext::root($root.'/', 'demo'));
        File::ensureDirectoryExists($root.'/_sites/demo');
        file_put_contents($root.'/_sites/demo/km-manifest.json', '{}');
        $this->assertSame($root.'/_sites/demo', SiteContext::root($root, 'demo'));
        $this->assertSame($root, SiteContext::root($root, SiteContext::MAIN));
        $this->assertSame([], SiteContext::registry($this->main.'/yok'));
    }

    public function test_boot_selects_site_and_its_storage(): void
    {
        $site = $this->boot('demo.kodmob.test:443');
        $this->assertSame(['demo', 'Demo', ['blog']], [$site['key'], $site['name'], $site['modules']]);
        $this->assertSame($this->main.'/sites/demo', $site['storage']);
        $this->assertSame($site['storage'], $_SERVER['LARAVEL_STORAGE_PATH']);
        $this->assertDirectoryExists($site['storage'].'/framework/views');
        $this->assertDirectoryExists($site['storage'].'/framework/pages');
        $this->assertSame($site, SiteContext::boot(base_path()), 'ikinci çağrı aynı seçimi döner');

        $main = $this->boot('bilinmeyen.test');
        $this->assertSame([SiteContext::MAIN, $this->main], [$main['key'], $main['storage']]);
        $this->assertArrayNotHasKey('LARAVEL_STORAGE_PATH', $_SERVER, 'ana sitede depolama değişmez');

        $this->assertSame('demo', $this->boot('kodmob.test', 'demo')['key'], 'KODMOB_SITE alan adından önce gelir');
        $this->expectExceptionMessage('Kayıtlı olmayan site: hayalet');
        $this->boot(null, 'hayalet');
    }

    public function test_child_site_has_own_database_root_media_and_tokens(): void
    {
        $mainConnection = (string) config('database.default');
        $mainHeaders = $this->admin();
        $mainFingerprint = app(Jwt::class)->fingerprint();

        $site = $this->boot('demo.kodmob.test');
        try {
            $this->childSite($site, $mainConnection, $mainHeaders, $mainFingerprint);
        } finally {
            // RefreshDatabase işlemi varsayılan bağlantıda geri alır: ana bağlantıya dönülür.
            config(['database.default' => $mainConnection]);
            DB::purge(Sites::CONNECTION);
        }
    }

    private function childSite(array $site, string $mainConnection, array $mainHeaders, string $mainFingerprint): void
    {
        touch($site['storage'].'/database.sqlite');
        Sites::apply($site);
        app()->forgetInstance(Jwt::class);

        $this->assertSame(['site', 'sqlite', $site['storage'].'/database.sqlite'], [config('database.default'), config('database.connections.site.driver'), config('database.connections.site.database')]);
        $this->assertSame(base_path('tests/fixtures/site'), Paths::siteRoot(), 'kendi derlemesi yok: ana sitenin derlemesi');
        $router = Router::load(Paths::siteRoot());
        $this->assertSame('https://demo.kodmob.test', $router->manifest()['siteUrl'], 'site haritası ve kanonik adres alt sitenin');
        $shell = $router->shell(Paths::siteRoot().'/tr/index.html');
        $this->assertStringContainsString('https://demo.kodmob.test/en/', $shell, 'kabuğa gömülü adresler (hreflang) alt sitenin');
        $this->assertStringNotContainsString('https://kodmob.test', $shell);
        $this->assertSame([$this->mediaRoot.'/demo', '/media/demo/a.jpg'], [Paths::mediaRoot(), Paths::mediaUrl('a.jpg')]);
        $this->assertSame(['https://demo.kodmob.test', ['blog'], 'demo'], [config('app.url'), config('kodmob.modules'), Sites::key()]);

        // Alt sitenin kendi veritabanı: tablolar migration'larla kurulur, ana sitenin kullanıcıları yoktur.
        Artisan::call('migrate', ['--database' => Sites::CONNECTION, '--force' => true]);
        foreach ([Options::class, Roles::class, Languages::class] as $cache) {
            method_exists($cache, 'flush') ? $cache::flush() : $cache::forget();
        }
        $demo = User::query()->create(['username' => 'demo-yonetici', 'display_name' => 'Demo', 'email' => 'demo@kodmob.test', 'password' => 'gizli-sifre-123', 'role' => User::ADMIN, 'status' => User::ACTIVE]);
        $this->assertSame(['demo-yonetici'], DB::connection('site')->table('users')->pluck('username')->all());
        $this->assertTrue(DB::connection($mainConnection)->table('users')->where('username', 'yonetici')->exists());
        $this->assertFalse(DB::connection($mainConnection)->table('users')->where('username', 'demo-yonetici')->exists());

        // Oturum anahtarı siteye özel: ana sitenin jetonu burada geçmez.
        $this->assertNotSame($mainFingerprint, app(Jwt::class)->fingerprint());
        $this->getJson('/v1/me', $mainHeaders)->assertUnauthorized();
        $token = app(TokenService::class)->issue($demo, Request::create('/'))['token'];
        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('username', 'demo-yonetici');
    }

    public function test_artisan_runs_for_the_site_in_kodmo_b_site(): void
    {
        $env = ['KODMOB_MAIN_STORAGE' => $this->main, 'KODMOB_SITE' => 'demo', 'LARAVEL_STORAGE_PATH' => false, 'APP_ENV' => 'testing'];
        File::ensureDirectoryExists($this->main.'/sites/demo');
        touch($this->main.'/sites/demo/database.sqlite');

        $run = Process::path(base_path())->env($env)->run([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction']);
        $this->assertSame(0, $run->exitCode(), $run->errorOutput().$run->output());
        $pdo = new \PDO('sqlite:'.$this->main.'/sites/demo/database.sqlite');
        $this->assertSame(count(glob(base_path('database/migrations/*.php'))), (int) $pdo->query('select count(*) from migrations')->fetchColumn());
        $pdo = null;

        $run = Process::path(base_path())->env(['KODMOB_SITE' => 'hayalet'] + $env)->run([PHP_BINARY, 'artisan', 'about', '--only=environment']);
        $this->assertNotSame(0, $run->exitCode());
        $this->assertStringContainsString('Kayıtlı olmayan site: hayalet', $run->errorOutput().$run->output());
    }
}
