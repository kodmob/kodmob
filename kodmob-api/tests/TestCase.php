<?php

namespace Tests;

use App\Core\Content\Types;
use App\Core\Extensions\Extensions;
use App\Core\ModuleState;
use App\Core\Sites\SiteContext;
use App\Core\Sites\Sites;
use App\Core\Support\SiteManifest;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\TokenService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\Support\Portfolio\ProjectType;
use Tests\Support\Portfolio\ReferenceType;
use Tests\Support\Portfolio\ServiceType;

/**
 * Testler bellek içi SQLite'ta çalışır; site kökü tests/fixtures/site (küçük bir
 * km-manifest.json ve sayfa kabukları), görseller ve storage/ geçici klasörde.
 *
 * Çatının testleri projenin koduna (app/Project, config/project.php) bağlı değildir: içerik türleri test türleridir
 * (tests/Support/Portfolio: portföy sitesi örneği). Projenin kendi testleri (tests/Project) `CONTENT_TYPES`'ı
 * projenin türleriyle değiştirebilir.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected string $mediaRoot;

    protected string $storage;

    /**
     * Kurulu araç paketleri (kodmob-api/extensions) yüklensin mi. Çatının testleri paketlerden bağımsızdır (paket
     * eklemek onları bozmasın); paketin kendi testleri (ad alanı Tests\ dışında) ve bunu true yapan test
     * (ArchitectureTest: paketler de kurallara uyar) paketleri yükler.
     */
    protected bool $extensions = false;

    private bool $withoutExtensions = false;

    /** @var list<class-string> uygulama açılırken `project.content_types` (rotalar türleri açılışta okur) */
    protected const CONTENT_TYPES = [ProjectType::class, ServiceType::class, ReferenceType::class];

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->beforeBootstrapping(RegisterProviders::class, static fn ($app) => $app['config']->set('project.content_types', static::CONTENT_TYPES));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        $this->withoutExtensions = str_starts_with(static::class, 'Tests\\') && ! $this->extensions && getenv('KODMOB_EXTENSIONS') === false;
        if ($this->withoutExtensions) {
            $none = __DIR__.'/fixtures/no-extensions';
            putenv('KODMOB_EXTENSIONS='.$none);
            $_ENV['KODMOB_EXTENSIONS'] = $_SERVER['KODMOB_EXTENSIONS'] = $none;
        }
        Extensions::forget();
        // Modüller uygulama açılırken sitenin profilinden seçilir (km-manifest.json `profile`): test sitesinin
        // derlemesi uygulamadan önce bilinsin (KODMOB_MODULES verilirse o geçerli, bkz. ModulesOffTest).
        $root = __DIR__.'/fixtures/site';
        putenv('SITE_ROOT='.$root);
        $_ENV['SITE_ROOT'] = $_SERVER['SITE_ROOT'] = $root;
        // Önceki testin profili (tür listesi) rotalar kurulmadan unutulsun.
        SiteManifest::forget();
        Types::forget();
        // Yedekler, sayfa önbelleği ve projede kapatılan araçlar (storage/framework/modules.json) geliştirme
        // klasörüne yazılmasın ve oradan okunmasın: uygulama daha açılırken geçici depolamada (modüller açılışta
        // seçilir; yerel sitede kapatılan araç testleri etkilemesin).
        $this->storage = sys_get_temp_dir().'/km-test-storage-'.uniqid();
        foreach (['app', 'framework/cache', 'framework/views', 'logs'] as $dir) {
            @mkdir($this->storage.'/'.$dir, 0755, true);
        }
        $_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $this->storage;
        ModuleState::forget();
        // Her test süresiz başlar: yedek geri yükleme gibi işlerin set_time_limit()'i sonraki testlere taşmasın.
        set_time_limit(0);
        parent::setUp();
        $this->mediaRoot = sys_get_temp_dir().'/km-test-media-'.uniqid();
        $this->app->useStoragePath($this->storage);
        // Çok site kaydı (storage/sites.json) da geçici depolamada: yereldeki alt sitelere dokunulmaz.
        SiteContext::reset();
        $_SERVER['KODMOB_MAIN_STORAGE'] = $this->storage;
        SiteContext::boot(base_path());
        config([
            'kodmob.site_root' => base_path('tests/fixtures/site'),
            'kodmob.media_root' => $this->mediaRoot,
            'app.url' => 'https://kodmob.test',
        ]);
        Sites::forgetMemory();
    }

    protected function tearDown(): void
    {
        SiteContext::reset();
        File::deleteDirectory($this->mediaRoot);
        File::deleteDirectory($this->storage);
        unset($_ENV['LARAVEL_STORAGE_PATH'], $_SERVER['LARAVEL_STORAGE_PATH']);
        if ($this->withoutExtensions) {
            putenv('KODMOB_EXTENSIONS');
            unset($_ENV['KODMOB_EXTENSIONS'], $_SERVER['KODMOB_EXTENSIONS']);
        }
        parent::tearDown();
    }

    /** Yönetici oluşturur; Bearer başlığını döner. */
    protected function admin(string $username = 'yonetici', string $password = 'gizli-sifre-123'): array
    {
        return $this->as(User::ADMIN, $username, $password);
    }

    /** Verilen roldeki kullanıcı (yoksa oluşturur) için yeni oturum; Bearer başlığını döner. */
    protected function as(string $role, ?string $username = null, string $password = 'gizli-sifre-123'): array
    {
        $username ??= $role;
        $user = User::query()->firstOrCreate(['username' => $username], [
            'email' => $username.'@kodmob.test',
            'display_name' => $role === User::ADMIN ? 'Yönetici' : ucfirst($username),
            'password' => $password,
            'role' => $role,
            'status' => User::ACTIVE,
        ]);
        $token = app(TokenService::class)->issue($user, Request::create('/'))['token'];

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    protected function userId(string $username): int
    {
        return (int) User::query()->where('username', $username)->value('id');
    }
}
