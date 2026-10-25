<?php

namespace Tests\Feature;

use App\Core\Content\PageCache;
use App\Core\Settings\SettingsService;
use App\Core\Support\Paths;
use App\Modules\Commerce\CommerceModule;
use App\Modules\Content\Backup;
use App\Modules\Site\Legal\LegalPages;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use App\Modules\Site\SiteModule;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Yasal belgeler (Modules\Site\Legal; docs/yasal-raporu.md): şablon Proje Ayarları › Yasal ve açık modüllerle çizilir,
 * modülü kapalı belge 404 ve listelerde yok, panelden dil başına özelleştirme ve şablona dönüş, yetki, yedek. Test
 * sitesinin belgeleri tests/fixtures/site/km-legal.json'da (sözcüklerden bağımsız kısa şablonlar).
 */
class LegalTest extends TestCase
{
    private function env(?string $modules): void
    {
        if ($modules === null) {
            putenv('KODMOB_MODULES');
            unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);

            return;
        }
        putenv('KODMOB_MODULES='.$modules);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = $modules;
    }

    protected function setUp(): void
    {
        // Modüller uygulama açılırken seçilir: Mağaza açık senaryosu ortamı önceden verir.
        if (str_contains($this->name(), 'module_documents')) {
            $this->env('blog,briefs,commerce,stripe,newsletter');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->env(null);
    }

    private function render(string $path): array
    {
        $router = Router::load(Paths::siteRoot());
        $match = $router->match($path);

        return $match['kind'] === 'page' ? (new SitePage($router))->render($match, $path) : [0, ''];
    }

    private function legalSettings(): void
    {
        $s = SettingsService::instance();
        $s->set(SiteModule::LEGAL_NAME, 'Ayşe Yılmaz & Ortakları');
        $s->set(SiteModule::LEGAL_ADDRESS, 'Atatürk Cad. 1, Adana');
        $s->set(SiteModule::LEGAL_EMAIL, 'kvkk@ornek.test');
        $s->set(SiteModule::LEGAL_COURT, 'Adana');
        $s->set(SiteModule::LEGAL_KEP, 'ornek@hs01.kep.tr');
    }

    public function test_document_renders_from_template_with_settings_and_open_modules(): void
    {
        $this->legalSettings();

        [$status, $html] = $this->render('/yasal/gizlilik-politikasi/');
        $this->assertSame(200, $status);
        // Veri sorumlusu kutusu kaçışlı; ayarlar ve modüllerin verisi (Güvenlik 90 gün, Talepler 24 ay).
        $this->assertStringContainsString('<strong>Ayşe Yılmaz &amp; Ortakları</strong>', $html);
        $this->assertStringContainsString('KEP: ornek@hs01.kep.tr', $html);
        $this->assertStringContainsString('Güvenlik kayıtları 90 gün.', $html);
        $this->assertStringContainsString('Talepler 24 ay.', $html);
        $this->assertStringContainsString('Mahkeme: Adana', $html);
        // Mağaza kapalı: bölümü ve belgesi yok.
        $this->assertStringNotContainsString('Alışveriş', $html);
        // Başlıklara kimlik, içindekiler; şablondaki tablo kutusu iki kez sarılmaz; güncelleme tarihi dilin ay adıyla.
        $this->assertStringContainsString('<h2 id="veri-sorumlusu">Veri sorumlusu</h2>', $html);
        $this->assertStringContainsString('<li><a href="#bize-yazdiginizda">Bize yazdığınızda</a></li>', $html);
        $this->assertSame(1, substr_count($html, 'table-wrap'));
        $this->assertStringContainsString('<time datetime="2026-10-21">21 Ekim 2026</time>', $html);
        // Yan menü: açık belgeler, açık olan işaretli.
        $this->assertStringContainsString('<a href="/yasal/gizlilik-politikasi/" aria-current="page">Gizlilik politikası</a>', $html);
        $this->assertStringNotContainsString('/yasal/mesafeli-satis-sozlesmesi/', $html);

        // Modülü kapalı belge 404; özet sayfası yalnızca açık belgeleri listeler.
        $this->assertSame(404, $this->render('/yasal/mesafeli-satis-sozlesmesi/')[0]);
        $this->assertSame(404, $this->render('/yasal/bulten-aydinlatma-ve-onay/')[0]);
        [$status, $hub] = $this->render('/yasal/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('data-legal="privacy"', $hub);
        $this->assertStringNotContainsString('data-legal="shopTerms"', $hub);

        // İngilizce şablon ve eksik bilgide o dilin uyarısı.
        SettingsService::instance()->set(SiteModule::LEGAL_NAME, '');
        PageCache::flush();
        [, $en] = $this->render('/en/legal/privacy-policy/');
        $this->assertStringContainsString('[Company name: Project Settings › Legal]', $en);
        $this->assertStringContainsString('Write to kvkk@ornek.test.', $en);
    }

    public function test_module_documents_and_sections_appear_when_the_module_is_on(): void
    {
        $this->legalSettings();
        SettingsService::instance()->set(CommerceModule::SHIPPING_FEE, 50.0);
        SettingsService::instance()->set(CommerceModule::BANK_ENABLED, true);
        SettingsService::instance()->set(CommerceModule::BANK_IBAN, 'TR00 0000 0000 0000 0000 0000 00');

        [$status, $html] = $this->render('/yasal/gizlilik-politikasi/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<h2 id="alisveris">Alışveriş</h2>', $html);
        $this->assertStringContainsString('Havale', $html);
        $this->assertStringContainsString('· Stripe', $html);
        $this->assertStringContainsString('<a href="/yasal/mesafeli-satis-sozlesmesi/">Sözleşme</a>', $html);

        [$status, $terms] = $this->render('/yasal/mesafeli-satis-sozlesmesi/');
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('/Kargo: [^<]*50,00/u', $terms);
        $this->assertStringNotContainsString('ücretsiz', $terms);
        $this->assertSame(200, $this->render('/yasal/bulten-aydinlatma-ve-onay/')[0]);

        [, $hub] = $this->render('/yasal/');
        $this->assertStringContainsString('<h2>Alışveriş</h2>', $hub);
        $this->assertStringContainsString('data-legal="shopTerms"', $hub);
        $this->assertStringContainsString('data-legal="newsletterConsent"', $hub);
    }

    public function test_panel_customizes_per_language_and_resets_to_the_template(): void
    {
        $h = $this->admin();
        $list = $this->getJson('/v1/admin/legal', $h)->assertOk()->json();
        $privacy = collect($list['items'])->firstWhere('name', 'privacy');
        $this->assertSame(['tr', 'en', 'de'], array_keys($privacy['locales']));
        $this->assertNull($privacy['locales']['de']['url']);
        $this->assertFalse($privacy['locales']['tr']['customized']);
        $this->assertSame('/yasal/gizlilik-politikasi/', $privacy['locales']['tr']['url']);
        $this->assertNull(collect($list['items'])->firstWhere('name', 'shopTerms'));
        $this->assertContains('Veri sorumlusu (unvan)', $list['missing']);
        $this->assertContains('Yetkili mahkeme', $list['missing']);

        $show = $this->getJson('/v1/admin/legal/privacy/tr', $h)->assertOk()->json();
        $this->assertFalse($show['customized']);
        $this->assertStringContainsString('<h2>Veri sorumlusu</h2>', $show['template']);
        $this->assertStringContainsString('[Unvan: Proje Ayarları › Yasal]', $show['template']);

        $this->putJson('/v1/admin/legal/privacy/tr', ['body' => '<h2>Bizim metnimiz</h2><p>Özel <script>alert(1)</script>metin</p><table><tr><td>1</td></tr></table>'], $h)
            ->assertOk()->assertJsonPath('customized', true);
        [, $html] = $this->render('/yasal/gizlilik-politikasi/');
        $this->assertStringContainsString('<h2 id="bizim-metnimiz">Bizim metnimiz</h2>', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('<div class="table-wrap"><table>', $html);
        // Diğer dil şablonda kalır.
        $this->assertStringContainsString('Data controller', $this->render('/en/legal/privacy-policy/')[1]);
        $this->assertSame(1, DB::table('events')->where('event_type', 'legal.customized')->count());

        // Yedek: özelleştirme taşınır.
        $backup = (new Backup)->export();
        $this->assertArrayHasKey('privacy|tr', (array) $backup['legal']);

        $this->putJson('/v1/admin/legal/privacy/tr', ['body' => '<p> </p>'], $h)->assertStatus(422);
        $this->deleteJson('/v1/admin/legal/privacy/tr', [], $h)->assertOk();
        $this->assertStringContainsString('<h2 id="veri-sorumlusu">', $this->render('/yasal/gizlilik-politikasi/')[1]);
        $this->assertSame(1, DB::table('events')->where('event_type', 'legal.reset')->count());

        // Yedekten geri gelir.
        $this->assertSame(1, LegalPages::import(json_decode(json_encode($backup['legal']), true)));
        $this->assertStringContainsString('Bizim metnimiz', $this->render('/yasal/gizlilik-politikasi/')[1]);

        // Bilinmeyen ya da modülü kapalı belge, yetkisiz kullanıcı.
        $this->getJson('/v1/admin/legal/yok/tr', $h)->assertNotFound();
        $this->putJson('/v1/admin/legal/shopTerms/tr', ['body' => '<p>x</p>'], $h)->assertNotFound();
        $this->getJson('/v1/admin/legal', $this->as('editor'))->assertForbidden();
    }
}
