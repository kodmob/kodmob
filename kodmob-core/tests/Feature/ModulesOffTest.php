<?php

namespace Tests\Feature;

use App\Core\Content\Types;
use App\Core\Modules;
use App\Core\Search\SearchRegistry;
use App\Core\Security\Capability;
use App\Core\Security\Roles;
use App\Core\Settings\SettingsRegistry;
use App\Modules\Briefs\BriefsModule;
use App\Modules\Jarvis\JarvisModule;
use Tests\TestCase;

/**
 * İsteğe bağlı modüller kapalıyken (uygulama KODMOB_MODULES=estimate ile açılır: blog, talepler ve
 * Jarvis kapalı): sınıfları yüklenmez, yetkileri yok, rotaları 404, ayarları ve arama grupları yok.
 */
class ModulesOffTest extends TestCase
{
    private const ENV = 'estimate';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    public function test_closed_modules_are_gone_everywhere(): void
    {
        $this->assertSame(['estimate'], Modules::keys());
        $this->assertNotContains(BriefsModule::class, Modules::classes());
        $this->assertNotContains(JarvisModule::class, Modules::classes());

        // Yetkiler: yönetici dahil kimsede yok; hazır rollerden de düşer.
        $caps = Capability::keys();
        foreach (['km_view_briefs', 'km_manage_briefs', 'km_use_jarvis', 'km_use_domains', 'km_manage_posts'] as $cap) {
            $this->assertNotContains($cap, $caps, $cap);
        }
        $this->assertContains('km_manage_estimate', $caps);
        $this->assertContains('km_manage_ai', $caps, 'yapay zekâ ayarı çekirdek (içerik çevirisi de kullanır)');
        $this->assertContains('km_manage_projects', $caps, 'içerik türleri content_types listesinden');
        $this->assertNotContains('km_view_briefs', Roles::capsOf('editor'));

        $admin = $this->admin();
        $me = $this->getJson('/v1/me', $admin)->assertOk()->json();
        $this->assertSame(['estimate'], $me['modules']);
        $this->assertNotContains('km_use_jarvis', $me['capabilities']);

        // Rotalar 404 (kapalı modülün ucu yokmuş gibi), açık modülünki çalışır.
        $this->postJson('/v1/briefs', ['name' => 'A'])->assertNotFound()->assertJsonPath('code', 'km_not_found');
        $this->getJson('/v1/briefs', $admin)->assertNotFound();
        $this->getJson('/v1/jarvis/conversations', $admin)->assertNotFound();
        $this->getJson('/v1/system/ai', $admin)->assertOk();
        $this->getJson('/v1/admin/content/posts', $admin)->assertNotFound();
        $this->getJson('/v1/admin/estimate', $admin)->assertOk();

        // Blog içerik türü değil, ayarları ve arama sağlayıcıları yok.
        $this->assertArrayNotHasKey('posts', Types::editable());
        $groups = array_unique(array_column($this->getJson('/v1/settings', $admin)->assertOk()->json('items'), 'group'));
        $this->assertNotContains('Talepler', $groups);
        $this->assertNotContains('Jarvis', $groups);
        $this->assertNull(SettingsRegistry::get(BriefsModule::NOTIFY_EMAIL));
        $this->assertSame([], array_intersect(['posts', 'briefs', 'jarvis'], array_keys(SearchRegistry::providers())));
        $this->assertArrayNotHasKey('posts', $this->getJson('/v1/admin/overview', $admin)->assertOk()->json('counts'));
    }
}
