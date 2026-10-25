<?php

namespace Tests\Feature;

use App\Core\Content\Types;
use App\Core\Events\EventCatalog;
use App\Core\Module;
use App\Core\Modules;
use App\Core\ModuleState;
use App\Core\Security\Capability;
use App\Core\Sites\Sites;
use App\Core\Support\Paths;
use App\Core\Support\SiteManifest;
use App\Modules\Briefs\BriefsModule;
use App\Modules\Site\Render\Renderer;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Portfolio\ServiceType;
use Tests\TestCase;

/**
 * Modül kaydı (Core\Modules): isteğe bağlı modüller ve içerik türleri sitenin profilinden (km-manifest.json
 * `profile`; test sitesinde kodmob.com'unki gibi hepsi açık). Ortamdan kapatma: ModulesOffTest.
 */
class ModulesTest extends TestCase
{
    private const ALL = ['blog', 'briefs', 'estimate', 'jarvis', 'domains', 'sms', 'sites'];

    /** Profili verilen (ya da profilsiz) geçici site derlemesi. */
    private function site(?array $profile): void
    {
        $root = $this->storage.'/site-'.uniqid();
        File::ensureDirectoryExists($root);
        $manifest = json_decode((string) file_get_contents(base_path('tests/fixtures/site/km-manifest.json')), true);
        unset($manifest['profile']);
        file_put_contents($root.'/km-manifest.json', json_encode($profile === null ? $manifest : ['profile' => $profile] + $manifest));
        config(['kodmob.site_root' => $root, 'kodmob.modules' => null]);
        SiteManifest::forget();
        Types::forget();
    }

    /** Modül listesini değiştirir; istek içi önbellekler (rollerin yetkileri) tazelenir. */
    private function modules(array $keys): void
    {
        config(['kodmob.modules' => $keys]);
        Sites::forgetMemory();
    }

    public function test_profile_selects_the_modules(): void
    {
        $this->assertSame(self::ALL, Modules::keys());
        $this->assertContains(BriefsModule::class, Modules::classes());
        $this->assertArrayHasKey('posts', Types::editable());
        foreach (['km_view_briefs', 'km_use_jarvis', 'km_use_domains', 'km_manage_sms', 'km_manage_sites', 'km_manage_estimate', 'km_manage_posts'] as $cap) {
            $this->assertContains($cap, Capability::keys());
        }
        $this->assertSame(self::ALL, $this->getJson('/v1/me', $this->admin())->json('modules'));

        // Ortam (ya da yapılandırma) profilin üstündedir; bilinmeyen anahtar yok sayılır; çekirdek hep açık.
        config(['kodmob.modules' => ['briefs', 'olmayan']]);
        $this->assertSame(['briefs'], Modules::keys());
        $this->assertTrue(Modules::enabled('seo'));
        $this->assertFalse(Modules::enabled('jarvis'));
        $this->assertSame(['blog' => false, 'briefs' => true], array_slice(array_column(Modules::view(), 'enabled', 'key'), 0, 2));
    }

    public function test_without_profile_everything_stays_on(): void
    {
        // Eski derleme (profilsiz): canlıda çekirdek derlemeden önce yüklense de modül kaybolmaz.
        $this->site(null);
        $this->assertSame(self::ALL, Modules::keys());
        // Blok sayfaları (Site modülü) çatının türü: her projede.
        $this->assertSame(['projects', 'services', 'references', 'pages'], array_keys(Types::all()));

        // Yeni projenin profili sade başlangıcı açıkça yazar.
        $this->site(['modules' => Modules::STARTER]);
        $this->assertSame(['blog', 'briefs'], Modules::keys());
        foreach (['km_use_jarvis', 'km_use_domains', 'km_manage_sms', 'km_manage_sites', 'km_manage_estimate'] as $cap) {
            $this->assertNotContains($cap, Capability::keys());
        }
    }

    public function test_domains_sms_and_sites_close_on_their_own(): void
    {
        $h = $this->admin();
        $this->modules(['briefs', 'jarvis']);
        $this->getJson('/v1/jarvis', $h)->assertOk()->assertJsonPath('jobs', []);
        $this->getJson('/v1/jarvis/domains/options', $h)->assertNotFound();
        $this->postJson('/v1/jarvis/tick', [], $h)->assertNotFound();
        $this->getJson('/v1/system/sms', $h)->assertNotFound();
        $this->postJson('/v1/users/sms', ['ids' => [1], 'message' => 'Deneme'], $h)->assertNotFound();
        $this->assertFalse($this->getJson('/v1/users/options', $h)->assertOk()->json('messaging.sms'));
        $this->getJson('/v1/system/sites', $h)->assertNotFound();
        $this->assertNotContains('km_manage_sites', $this->getJson('/v1/me', $h)->json('capabilities'));

        // Domain arama Jarvis kapalıyken de açılabilir.
        $this->modules(['domains']);
        $this->getJson('/v1/jarvis/domains/options', $h)->assertOk()->assertJsonStructure(['tlds']);
        $this->getJson('/v1/jarvis', $h)->assertNotFound();
    }

    public function test_profile_selects_content_types_and_pages_skip_closed_ones(): void
    {
        // Her sayfada gelen türler profilin `needs`'inde (kodmob.com: hizmetler, alt bilgi listesi).
        $this->site(['modules' => ['blog'], 'contentTypes' => ['services'], 'needs' => ['services']]);
        $this->assertSame(['services', 'pages'], array_keys(Types::all()));
        $this->assertNull(Types::get('projects'));
        $this->assertSame(['page'], array_keys(Types::paged()), 'projeler kapalı: kayıt sayfası olan tür yalnızca blok sayfası');
        $this->assertNotContains('km_manage_projects', Capability::keys());
        $this->getJson('/v1/admin/content/projects', $this->admin())->assertNotFound();
        $this->getJson('/v1/page-data?route=home&locale=tr')->assertOk()->assertJsonPath('services', []);
        $this->getJson('/v1/page-data?route=project&locale=tr&slug=yok')->assertOk()->assertJsonPath('notFound', true);

        $this->site(['modules' => ['blog'], 'contentTypes' => ['services']]);
        $this->assertArrayNotHasKey('services', $this->getJson('/v1/page-data?route=home&locale=tr')->assertOk()->json());
    }

    /** Projenin kodu (config/project.php) yoksa çatı türsüz çalışır: sayfa verisi, site haritası, SEO listesi boş türle. */
    public function test_framework_runs_without_project_types(): void
    {
        config(['project.content_types' => []]);
        $this->site(['modules' => ['blog', 'briefs']]);
        $this->assertSame(['pages'], array_keys(Types::all()), 'çatının türü (blok sayfaları) kalır');
        $this->assertSame(['pages', 'posts'], array_keys(Types::editable()));
        $this->getJson('/v1/page-data?route=home&locale=tr&needs=projects,posts')->assertOk()->assertJsonMissingPath('projects')->assertJsonPath('posts', []);
        $this->getJson('/v1/page-data?route=project&locale=tr&slug=x')->assertOk()->assertJsonPath('notFound', true);
        $this->getJson('/v1/search-index?locale=tr')->assertOk()->assertExactJson(['pages' => [], 'posts' => []]);
        $router = Router::load(Paths::siteRoot());
        [$status, $xml] = (new SitePage($router))->render($router->match('/sitemap.xml'), '/sitemap.xml');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<urlset', $xml);
        $this->getJson('/v1/admin/content/posts', $this->admin())->assertOk();
    }

    /** Araç Pazarı: kurulu araç bu projede çalışma anında kapanır / açılır; kurulu olmayan açılamaz. */
    public function test_tools_toggle_per_project_at_runtime(): void
    {
        $this->site(['modules' => ['blog', 'briefs', 'domains', 'jarvis']]);
        $h = $this->admin();
        $catalog = collect($this->getJson('/v1/system/tools', $h)->assertOk()->json('items'))->keyBy('key');
        $this->assertSame([true, true, 'tool'], [$catalog['domains']['installed'], $catalog['domains']['enabled'], $catalog['domains']['kind']]);
        $this->assertSame([false, false, 'base'], [$catalog['sms']['installed'], $catalog['sms']['enabled'], $catalog['blog']['kind']]);

        $this->putJson('/v1/system/tools/domains', ['enabled' => false], $h)->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('installed', true);
        $this->assertNotContains('domains', $this->getJson('/v1/me', $h)->json('modules'));
        $this->getJson('/v1/jarvis/domains/options', $h)->assertNotFound();
        $this->assertNotContains('km_use_domains', Capability::keys());
        $this->assertFileExists($this->app->storagePath('framework/modules.json'));

        $this->putJson('/v1/system/tools/domains', ['enabled' => true], $h)->assertOk()->assertJsonPath('enabled', true);
        $this->getJson('/v1/jarvis/domains/options', $h)->assertOk();
        $this->putJson('/v1/system/tools/sms', ['enabled' => true], $h)->assertStatus(422);
        $this->putJson('/v1/system/tools/blog', ['enabled' => false], $h)->assertStatus(422)->assertJsonPath('details.fields.key', fn ($m) => str_contains($m, 'temel'));
        $this->putJson('/v1/system/tools/domains', ['enabled' => false], $this->as('editor'))->assertForbidden();

        // Kapalı aracın site sayfası (rotanın `module`'ü) bulunamaz; site haritasına girmez.
        ModuleState::set('domains', false);
        $this->assertTrue(Renderer::moduleOff(['name' => 'x', 'module' => 'domains']));
        $this->assertFalse(Renderer::moduleOff(['name' => 'x', 'module' => 'blog']));
        $this->assertFalse(Renderer::moduleOff(['name' => 'x']));
        ModuleState::set('domains', true);
    }

    /** Araç Pazarı › Keşfet: çatının aracı derlemesiz eklenir (takıldıklarıyla birlikte), panelden eklenen çıkarılır. */
    public function test_framework_tools_are_added_and_removed_from_the_panel(): void
    {
        $this->site(['modules' => ['blog', 'briefs']]);
        $h = $this->admin();
        $this->assertFalse(Modules::enabled('hotels'));
        $this->putJson('/v1/system/tools/hotels', ['enabled' => true], $h)->assertStatus(422)->assertJsonPath('details.fields.key', "Oteller bu projede kurulu değil; önce Keşfet'ten ekleyin.");

        $res = $this->putJson('/v1/system/tools/hotels/install', ['installed' => true], $h)->assertOk();
        $this->assertSame(['Mağaza', 'Rezervasyon'], $res->json('with'));
        Types::forget();
        foreach (['commerce', 'booking', 'hotels'] as $key) {
            $this->assertTrue(Modules::enabled($key), $key);
        }
        $this->assertContains('km_manage_hotels', Capability::keys());
        $this->assertArrayHasKey('hotels', Types::all());
        $items = collect($res->json('items'))->keyBy('key');
        $this->assertSame([true, true, true], [$items['hotels']['installed'], $items['hotels']['enabled'], $items['hotels']['added']]);
        $this->assertFalse($items['blog']['added']);
        $this->assertSame(['added' => ['booking', 'commerce', 'hotels']], array_intersect_key(json_decode((string) file_get_contents($this->app->storagePath('framework/modules.json')), true), ['added' => 1]));

        // Profildeki araç panelden çıkarılmaz; takılı araç varken altındaki de çıkmaz.
        $this->putJson('/v1/system/tools/blog/install', ['installed' => false], $h)->assertStatus(422);
        $this->putJson('/v1/system/tools/commerce/install', ['installed' => false], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.key', 'Mağaza çıkarılamaz: önce Rezervasyon çıkarılmalı.');
        foreach (['hotels', 'booking', 'commerce'] as $key) {
            $this->putJson("/v1/system/tools/{$key}/install", ['installed' => false], $h)->assertOk();
        }
        $this->assertFalse(Modules::enabled('commerce'));
        // Bir ekleme, üç çıkarma (reddedilenler yazılmaz).
        $this->assertSame(4, DB::table('events')->where('event_type', 'tools.installed')->count());
        $this->putJson('/v1/system/tools/commerce/install', ['installed' => true], $this->as('editor'))->assertForbidden();
    }

    /** Projenin modülü (config/project.php `modules`): her zaman açık; yetkileri ve olayları kayda girer. */
    public function test_project_module_adds_capabilities_and_events(): void
    {
        config(['project.modules' => [ProjectReportsModule::class]]);
        $this->assertContains(ProjectReportsModule::class, Modules::classes());
        (new AppServiceProvider($this->app))->boot();
        Sites::forgetMemory();

        $this->assertSame(['label' => 'Raporları görme', 'group' => 'Proje'], Capability::all()['km_view_reports']);
        $this->assertContains('km_view_reports', $this->getJson('/v1/me', $this->admin())->json('capabilities'));
        $this->assertTrue(EventCatalog::has('reports.exported'));

        $this->expectException(\InvalidArgumentException::class);
        Capability::define(['view_reports' => ['label' => 'Öneksiz']]);
    }

    /** Modülün içerik türü (Module::contentTypes): profil listesine bağlı değil, modülle gelir; adı çakışırsa projeninki kalır. */
    public function test_module_brings_content_types(): void
    {
        $this->site(['modules' => self::ALL, 'contentTypes' => ['projects']]);
        $this->assertSame(['projects', 'pages'], array_keys(Types::all()));

        config(['project.modules' => [ProjectReportsModule::class]]);
        Types::forget();
        $this->assertSame(['projects', 'pages', 'services'], array_keys(Types::all()), 'modülün türü profilde olmasa da açık');
        $this->assertArrayHasKey('services', Types::editable());
        $this->assertStringContainsString('services', Types::pattern());

        config(['project.modules' => []]);
        Types::forget();
        $this->assertArrayNotHasKey('services', Types::all(), 'modül yokken türü de yok');
    }
}

/** Projenin modülüne örnek (testte): bir yetki, bir olay, bir içerik türü. */
class ProjectReportsModule extends Module
{
    public function capabilities(): array
    {
        return ['km_view_reports' => ['label' => 'Raporları görme']];
    }

    public function events(): array
    {
        return ['reports.exported' => ['Rapor dışa aktarıldı']];
    }

    public function contentTypes(): array
    {
        return [ServiceType::class];
    }
}
