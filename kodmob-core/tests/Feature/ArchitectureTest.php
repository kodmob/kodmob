<?php

namespace Tests\Feature;

use App\Core\Events\EventCatalog;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Settings\SettingsRegistry;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Mimari kurallar (CLAUDE.md; docs/done-sozlesme-raporu.md §4.3). Kod incelemesinde gözden kaçabilecek kurallar
 * burada otomatik denetlenir. Bir kural bilerek esnetilecekse izin listesine gerekçesiyle eklenir; listeler
 * küçülmeli, büyümemeli.
 */
class ArchitectureTest extends TestCase
{
    /** Kurulu araç paketlerinin uçları da aynı kurallara uyar (TestCase::$extensions). */
    protected bool $extensions = true;

    /** Kurallar bütün isteğe bağlı modüllerle denetlenir (yetkileri ve olayları yalnızca açıkken tanımlı). */
    protected function setUp(): void
    {
        $all = implode(',', array_keys(Modules::OPTIONAL));
        putenv('KODMOB_MODULES='.$all);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = $all;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    /** Yetki (cap:) istemeyen uçlar: yöntem + adres → gerekçe. */
    private const PUBLIC_ROUTES = [
        'GET v1' => 'API kökü (sürüm ve sağlık)',
        'POST v1/auth/token' => 'giriş',
        'POST v1/auth/token/refresh' => 'oturum yenileme (HttpOnly çerez)',
        'POST v1/auth/token/revoke' => 'çıkış; jeton yeterli (auth.jwt)',
        'POST v1/auth/handoff' => 'projeler arası geçiş: ana projenin verdiği tek kullanımlık, 60 sn anahtar (Handoff; throttle)',
        'POST v1/briefs' => 'sitedeki talep formu (throttle, briefs modülü)',
        'POST v1/newsletter' => 'bülten aboneliği: onay e-postası gider (tuzak alan, IP özetinden saatlik sınır, throttle; newsletter modülü)',
        'POST v1/newsletter/confirm' => 'bülten onay bağlantısı: abonenin 48 haneli anahtarı (throttle)',
        'POST v1/newsletter/unsubscribe' => 'abonelikten çıkma: abonenin anahtarı; e-posta istemcisinin tek tık çıkması (RFC 8058) (throttle)',
        'GET v1/shop/cart' => 'mağaza: ziyaretçinin sepeti (HttpOnly çerezdeki anahtar; throttle, commerce modülü)',
        'POST v1/shop/cart/items' => 'mağaza: sepete ekle (throttle)',
        'PATCH v1/shop/cart/items/{line}' => 'mağaza: sepetteki adet (throttle)',
        'DELETE v1/shop/cart/items/{line}' => 'mağaza: sepetten çıkar (throttle)',
        'POST v1/shop/cart/coupon' => 'mağaza: kupon (throttle)',
        'POST v1/shop/checkout' => 'mağaza: sipariş verme; fiyat ve stok sunucuda (throttle)',
        'GET v1/shop/orders/{number}' => 'mağaza: müşterinin siparişi; numara + bağlantı anahtarı (throttle)',
        'POST v1/shop/orders/{number}/pay' => 'mağaza: online ödemeyi yeniden başlatma; numara + bağlantı anahtarı (throttle)',
        'GET v1/shop/bookings/{tour}/availability' => 'rezervasyon: ayın tarihleri ve kalan yer (throttle, booking modülü)',
        'POST v1/shop/bookings/quote' => 'rezervasyon: fiyat dökümü, sepete eklemeden (throttle)',
        'POST v1/shop/payments/stripe' => 'Stripe bildirimi: imza (Stripe-Signature, whsec_…) ve 5 dakikalık zaman denetimi',
        'POST v1/shop/payments/paytr' => 'PayTR bildirimi: hash (merchant_key / merchant_salt) denetimi',
        'GET v1/page-data' => 'ziyaretçi sayfa verisi',
        'GET v1/search-index' => 'site içi arama dizini',
        'GET v1/setup' => 'kurulum adresi; SETUP_TOKEN ister',
        'POST v1/setup' => 'kurulum adresi (ilk yönetici); SETUP_TOKEN ister',
        'GET v1/site' => 'ziyaretçiye açık site bilgileri',
        'GET v1/suggest' => '404 sayfasındaki öneriler',
        'GET v1/theme.css' => 'panelde verilen site renkleri (ziyaretçinin tarayıcısı ister)',
        'GET v1/manifest.webmanifest' => 'web uygulaması bildirimi: site adı, renk, ikon adresleri (telefona ekleme)',
        'GET v1/members/me' => 'site üyeliği: çerezdeki oturumun üyesi (yoksa null; members modülü)',
        'POST v1/members/register' => 'üye kaydı: tuzak alan, süre denetimi, onay, şifre kuralı (throttle)',
        'POST v1/members/login' => 'üye girişi (throttle)',
        'POST v1/members/logout' => 'üye çıkışı: çerezdeki oturum',
        'POST v1/members/verify' => 'e-posta doğrulama: tek kullanımlık, süreli anahtar (throttle)',
        'POST v1/members/verify/resend' => 'doğrulama e-postasını yeniden: üyenin oturumu (throttle)',
        'POST v1/members/forgot' => 'şifre yenileme bağlantısı: kayıtlı olup olmadığı belli edilmez (throttle)',
        'POST v1/members/reset' => 'yeni şifre: tek kullanımlık, 1 saatlik anahtar (throttle)',
        'PUT v1/members/me' => 'üye profili: üyenin oturumu',
        'POST v1/members/password' => 'üye şifre değişimi: şu anki şifreyle',
        'POST v1/members/delete' => 'hesabı silme (KVKK): şifre ve onay yazısı',
        'GET v1/members/orders' => 'üyenin siparişleri: oturum ve doğrulanmış e-posta',
        'GET v1/members/google' => 'Google ile giriş başlangıcı: state çerezi',
        'GET v1/members/google/callback' => 'Google dönüşü: state eşleşmesi, kimlik jetonunun kitlesi ve e-postanın doğrulanmışlığı',
        'POST v1/members/push/subscribe' => 'üyenin anlık bildirim aboneliği: oturum, yalnızca bilinen itme servisleri',
        'POST v1/members/push/unsubscribe' => 'üyenin anlık bildirim aboneliğini bırakma: oturum',
        'GET v1/icon/{size}.png' => 'proje ikonu: sekme, telefona eklenen uygulama, anlık bildirim (yalnızca sabit boylar)',
    ];

    /** Çekirdekten (app/Core) modüllere bugün var olan bağımlılıklar: dosya → modül sınıfları. Yenisi gerekçe ister. */
    private const CORE_TO_MODULES = [
        'Content/ContentType.php' => ['Live\PulseFile', 'Media\MediaLibrary'],
        'Content/Fields.php' => ['Media\MediaLibrary'],
        'Content/MediaPayload.php' => ['Media\ImageProcessor', 'Media\MediaLibrary'],
        'Content/Types.php' => ['Blog\BlogPosts'],
        'Jobs/Scheduler.php' => ['Content\AutoBackup'],
        'Modules.php' => ['Blog\BlogModule', 'Briefs\BriefsModule', 'Commerce\Booking\BookingModule', 'Commerce\CommerceModule', 'Commerce\Hotels\HotelsModule', 'Commerce\Paytr\PaytrModule', 'Commerce\Stripe\StripeModule', 'Domains\DomainsModule', 'I18n\I18nModule', 'Jarvis\JarvisModule', 'Live\LiveModule', 'Media\MediaModule', 'Members\MembersModule', 'Newsletter\NewsletterModule', 'Notifications\NotificationsModule', 'Security\SecurityModule', 'Seo\SeoModule', 'Site\SiteModule', 'System\SystemModule'],
        'Search/SearchRegistry.php' => ['Auth\Models\User'],
        'Setup/SetupController.php' => ['Auth\Models\User', 'Content\Backup', 'I18n\I18nConfig', 'Seo\Services\SeoConfig'],
        'Sites/SiteRegistry.php' => ['Auth\Models\User', 'I18n\I18nConfig', 'Seo\Services\SeoConfig'],
        'Sites/Sites.php' => ['Auth\Services\Jwt', 'Blog\BlogPosts', 'Media\MediaLibrary', 'Site\Legal\LegalDocs'],
        'Support/SiteManifest.php' => ['Site\Render\Router'],
    ];

    /** Statik dizi tutan ama site değişiminde sıfırlanması gerekmeyen sınıflar → gerekçe. */
    private const STATIC_NOT_SITE_DATA = [
        'Activity' => 'istek içi işlem kaydı durumu; LogActivity her istekte sıfırlar',
        'Capability' => 'modüllerin yetkileri kodla tanımlanır (açılışta Module::capabilities)',
        'EventCatalog' => 'olay türleri kodla tanımlanır',
        'Extensions' => 'araç paketleri kurulumun tamamının (klasör), siteden bağımsız',
        'BlockLibrary' => 'km-blocks.json dosya yoluyla anahtarlı; alt sitenin kökü (dolayısıyla yolu) ayrı',
        'BlockRenderer' => 'ayrıştırılmış blok şablonları şablonun içeriğiyle anahtarlı',
        'Payments' => 'mağazanın ödeme yöntemleri kodla tanımlanır (modüllerin boot()\'u)',
        'CartLines' => 'mağazanın satır türleri kodla tanımlanır (modüllerin boot()\'u)',
        'IndexNow' => 'istek sonunda gönderilen kuyruk',
        'JobRunner' => 'iş türleri kodla tanımlanır',
        'PulseFile' => 'istek sonunda yazılan canlı şerit kuyruğu',
        'RdapBootstrap' => 'IANA RDAP listesi, siteden bağımsız',
        'SearchRegistry' => 'arama sağlayıcıları kodla tanımlanır',
        'SettingsRegistry' => 'ayar tanımları kodla tanımlanır',
        'SiteContext' => 'site seçiminin kendisi',
        'SkillRegistry' => 'Jarvis araçları kodla tanımlanır',
    ];

    /** Veritabanına özgü SQL kalıpları; yalnızca app/Core/Database içinde. */
    private const DRIVER_SQL = '/\b(ILIKE|GROUP_CONCAT|STRING_AGG|DATE_FORMAT|JSON_EXTRACT|IFNULL)\b|\bPRAGMA\s+[a-z_]+|strftime\(|pg_[a-z_]+\(|getDriverName\(\)/i';

    /** @return list<Route> */
    private function routes(): array
    {
        return array_values(Router::getRoutes()->getRoutes());
    }

    /** @return list<string> rotanın cap: ara katmanındaki yetkiler */
    private function caps(Route $route): array
    {
        $caps = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'cap:')) {
                array_push($caps, ...preg_split('/[|,]/', substr($middleware, 4)));
            }
        }

        return array_values(array_filter(array_map('trim', $caps)));
    }

    /** Denetleyici yöntemi işlem türünü kendisi veriyor mu (Activity::type(...)); o zaman rota adı kullanılmaz. */
    private function setsOwnType(Route $route): bool
    {
        $action = $route->getAction('uses');
        if (! is_string($action) || ! str_contains($action, '@')) {
            return false;
        }
        $method = new \ReflectionMethod(...explode('@', $action, 2));
        $lines = array_slice(file((string) $method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

        return str_contains(implode('', $lines), 'Activity::type(');
    }

    /** @return iterable<string,string> göreli yol → içerik */
    private function phpFiles(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                yield str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)) => (string) file_get_contents($file->getPathname());
            }
        }
    }

    public function test_every_route_requires_a_capability_or_is_listed_as_public(): void
    {
        $public = [];
        foreach ($this->routes() as $route) {
            if ($this->caps($route)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $public[] = $method.' '.$route->uri();
            }
        }
        sort($public);
        $allowed = array_keys(self::PUBLIC_ROUTES);
        sort($allowed);
        $this->assertSame([], array_values(array_diff($public, $allowed)), 'Yetkisiz uç: cap: ekleyin ya da PUBLIC_ROUTES\'a gerekçesiyle yazın.');
        $this->assertSame([], array_values(array_diff($allowed, $public)), 'PUBLIC_ROUTES\'ta artık var olmayan ya da yetkiye bağlanmış uç: listeden silin.');
    }

    public function test_capabilities_used_by_routes_exist(): void
    {
        $known = Capability::keys();
        $unknown = [];
        foreach ($this->routes() as $route) {
            foreach ($this->caps($route) as $cap) {
                if (! in_array($cap, $known, true)) {
                    $unknown[] = $route->uri().' → '.$cap;
                }
            }
        }
        $this->assertSame([], $unknown, 'Capability\'de tanımsız yetki.');
    }

    public function test_named_write_routes_are_in_the_activity_catalog(): void
    {
        $missing = [];
        foreach ($this->routes() as $route) {
            $name = (string) $route->getName();
            $logged = in_array('activity', $route->gatherMiddleware(), true) && ($route->defaults['activity'] ?? null) !== false;
            if ($name !== '' && $logged && array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) && ! EventCatalog::has($name) && ! $this->setsOwnType($route)) {
                $missing[] = $name.' ('.$route->uri().')';
            }
        }
        $this->assertSame([], $missing, 'İşlem geçmişinde başlığı olmayan rota adı: ActivityCatalog ya da modülün events()\'ine ekleyin.');
    }

    public function test_settings_are_well_formed(): void
    {
        $bad = [];
        foreach (SettingsRegistry::all() as $key => $definition) {
            if (! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', (string) $key) || trim($definition->label) === '' || trim($definition->group) === '') {
                $bad[] = (string) $key;
            }
        }
        $this->assertSame([], $bad, 'Ayar anahtarı grup.ad biçiminde olmalı; etiket ve grup boş olmamalı.');
    }

    public function test_core_does_not_gain_new_module_dependencies(): void
    {
        $found = [];
        foreach ($this->phpFiles(app_path('Core')) as $file => $code) {
            foreach (token_get_all($code) as $token) {
                if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_starts_with(ltrim($token[1], '\\'), 'App\Modules\\')) {
                    $found[$file][] = substr(ltrim($token[1], '\\'), strlen('App\Modules\\'));
                }
            }
        }
        $new = [];
        foreach ($found as $file => $classes) {
            foreach (array_unique($classes) as $class) {
                if (! in_array($class, self::CORE_TO_MODULES[$file] ?? [], true)) {
                    $new[] = "app/Core/{$file} → App\\Modules\\{$class}";
                }
            }
        }
        $this->assertSame([], $new, 'Çekirdekten modüle yeni bağımlılık: kanca ya da kayıt kullanın; kaçınılmazsa CORE_TO_MODULES\'a gerekçeyle ekleyin.');
        foreach (self::CORE_TO_MODULES as $file => $classes) {
            $this->assertSame([], array_values(array_diff($classes, $found[$file] ?? [])), "CORE_TO_MODULES güncel değil ({$file}): kalkan bağımlılığı listeden silin.");
        }
    }

    /** Çatı (app/Core, app/Modules) projenin koduna (App\Project) bağlanmaz: proje yalnızca kayıtlarla katılır. */
    public function test_framework_does_not_depend_on_project_code(): void
    {
        $found = [];
        foreach (['Core', 'Modules'] as $dir) {
            foreach ($this->phpFiles(app_path($dir)) as $file => $code) {
                foreach (token_get_all($code) as $token) {
                    if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_starts_with(ltrim($token[1], '\\'), 'App\Project\\')) {
                        $found[] = "app/{$dir}/{$file} → ".ltrim($token[1], '\\');
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($found)), 'Çatı projenin sınıfını kullanıyor: türün kancasını (ContentType) ya da config/project.php kaydını kullanın.');
    }

    public function test_static_caches_are_reset_when_the_site_changes(): void
    {
        $sites = (string) file_get_contents(app_path('Core/Sites/Sites.php'));
        preg_match('/function forgetMemory\(\): void\s*\{(.*?)\n    \}/s', $sites, $m);
        $reset = $m[1] ?? '';
        $this->assertNotSame('', $reset, 'Sites::forgetMemory bulunamadı.');
        $missing = [];
        foreach ($this->phpFiles(app_path()) as $file => $code) {
            if (preg_match('/private static \??array \$\w+/', $code) && preg_match('/^(?:final |abstract )?class (\w+)/m', $code, $class)) {
                if (! isset(self::STATIC_NOT_SITE_DATA[$class[1]]) && ! str_contains($reset, $class[1].'::')) {
                    $missing[] = "{$class[1]} (app/{$file})";
                }
            }
        }
        $this->assertSame([], $missing, 'İstek içi statik önbellek site değişiminde sıfırlanmıyor: Sites::forgetMemory()\'ye ekleyin (ya da site verisi değilse STATIC_NOT_SITE_DATA\'ya gerekçeyle).');
    }

    public function test_database_specific_sql_stays_in_core_database(): void
    {
        $found = [];
        foreach ($this->phpFiles(app_path()) as $file => $code) {
            if (! str_starts_with($file, 'Core/Database/') && preg_match_all(self::DRIVER_SQL, $code, $m)) {
                $found[] = "app/{$file}: ".implode(', ', array_unique($m[0]));
            }
        }
        $this->assertSame([], $found, 'Veritabanına özgü SQL yalnızca app/Core/Database\'de (ör. Sql::like()); üç veritabanında da çalışmalı.');
    }
}
