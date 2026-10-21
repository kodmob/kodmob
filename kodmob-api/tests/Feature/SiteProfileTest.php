<?php

namespace Tests\Feature;

use App\Core\Content\Types;
use App\Core\Sites\Sites;
use App\Core\Support\Paths;
use App\Core\Support\SiteManifest;
use App\Modules\Seo\Services\ContentFilters;
use App\Modules\Seo\Services\SeoHead;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Siteye özgü olan (kategoriler, brif hizmetleri, sunucu metinleri, site adı) kodda değil
 * derlemededir: km-manifest.json `vocab` ve `strings` (SiteManifest).
 */
class SiteProfileTest extends TestCase
{
    public function test_vocabularies_come_from_the_build(): void
    {
        $this->assertSame('Web Sitesi', Types::get('projects')->shared()['categories']['options']['web']);
        $this->assertSame('Website', SiteManifest::vocab('projectCategories', 'en')['web']);
        $this->assertSame(['web', 'mobile', 'ecommerce', 'ai', 'custom', 'consulting', 'other'], array_keys(SiteManifest::vocab('briefServices')));

        $h = $this->admin();
        $id = $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'categories' => ['web', 'uydurma'], 'i18n' => ['tr' => ['title' => 'Tarla']]], $h)->assertCreated()->json('id');
        $this->assertSame(['web'], Types::get('projects')->find($id)->data['categories'], 'sözlükte olmayan kategori düşer');

        $this->postJson('/v1/briefs', [
            'name' => 'Ayşe Deneme', 'email' => 'ayse@example.com', 'services' => ['web', 'uydurma'],
            'message' => 'Kurumsal web sitesi için teklif almak istiyoruz, teşekkürler.', 'consent' => true, 'locale' => 'tr',
        ])->assertCreated();
        $this->assertSame(['web'], json_decode((string) DB::table('briefs')->value('services'), true));
        $this->getJson('/v1/admin/briefs', $h)->assertJsonPath('items.0.service_labels', ['Web Sitesi']);
    }

    public function test_values_are_kept_when_the_build_has_no_vocabulary(): void
    {
        // Sözlüğü taşımayan eski derleme: kategoriler silinmemeli.
        $root = sys_get_temp_dir().'/km-site-'.uniqid();
        mkdir($root);
        $manifest = json_decode((string) file_get_contents(base_path('tests/fixtures/site/km-manifest.json')), true);
        unset($manifest['vocab']);
        file_put_contents($root.'/km-manifest.json', json_encode($manifest));
        config(['kodmob.site_root' => $root]);

        $h = $this->admin();
        $id = $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'categories' => ['web', 'yeni-tur'], 'i18n' => ['tr' => ['title' => 'Tarla']]], $h)->assertCreated()->json('id');
        $this->assertSame(['web', 'yeni-tur'], Types::get('projects')->find($id)->data['categories']);
        @unlink($root.'/km-manifest.json');
        @rmdir($root);
    }

    public function test_server_texts_and_identity_come_from_the_build(): void
    {
        $html = '<h2>Bir</h2><p>a</p><h2>İki</h2><p>b</p><h2>Üç</h2><p>c</p>';
        $this->assertStringContainsString('<p class="toc-title">Contents</p>', ContentFilters::post($html, 'en'));
        $this->assertStringContainsString('<p class="toc-title">İçindekiler</p>', ContentFilters::post($html, 'tr'));
        // Derlemede olmayan dil: varsayılan dilin metni.
        $this->assertSame('İçindekiler', SiteManifest::string('toc', 'de'));

        // Kurum şeması: ad derlemeden, adres dilin gerçek ana sayfası (Türkçe öneksiz; /tr/ değil).
        $org = SeoHead::organization('tr', 'https://kodmob.test')['@graph'][0];
        $this->assertSame(['Kodmob', 'https://kodmob.test/'], [$org['name'], $org['url']]);
        $this->assertSame('https://kodmob.test/en/', SeoHead::organization('en', 'https://kodmob.test')['@graph'][0]['url']);
    }

    public function test_site_identity_from_the_panel_replaces_the_build_name_and_logo(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/settings', ['values' => ['site.name' => 'Yeni & Marka', 'site.logo' => 'yeni', 'site.logo_sub' => 'ajans']], $h)->assertOk()->assertJsonPath('errors', []);
        Sites::forgetMemory();

        $this->assertSame('Yeni & Marka', SiteManifest::siteName('en'));
        $this->assertSame('Talebinizi aldık · Yeni & Marka', SiteManifest::string('briefReplySubject', 'tr'));
        $router = Router::load(Paths::siteRoot());
        $this->assertSame([' · Yeni & Marka', 'yeni', 'ajans'], [$router->manifest()['strings']['tr']['titleSuffix'], $router->manifest()['strings']['tr']['brand'], $router->manifest()['strings']['en']['brandSub']]);

        // Kabuğa gömülü ad ve logo (başlık, öznitelik, metin, yapısal veri) içerik eklenmeden önce değişir.
        $file = $this->storage.'/kabuk.html';
        file_put_contents($file, '<title>Projeler · Kodmob</title><meta property="og:site_name" content="Kodmob"><a aria-label="Kodmob ana sayfa"><span class="site-logo-text">kodmob</span><span class="site-logo-sub">yazılım</span></a><script type="application/ld+json">{"name":"Kodmob"}</script>');
        $html = $router->shell($file, 'tr');
        foreach (['<title>Projeler · Yeni &amp; Marka</title>', 'content="Yeni &amp; Marka"', 'aria-label="Yeni &amp; Marka ana sayfa"', '<span class="site-logo-text">yeni</span><span class="site-logo-sub">ajans</span>', '{"name":"Yeni & Marka"}'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        $this->assertStringNotContainsString('odmob', $html);
        [, $page] = (new SitePage($router))->render($router->match('/'), '/');
        $this->assertStringContainsString('<title>Ana Sayfa · Yeni &amp; Marka</title>', $page);

        // Logo görseli yazının yerine; renkler theme.css'ten (sürümlü bağlantı kabukta).
        $png = tempnam(sys_get_temp_dir(), 'km').'.png';
        $image = imagecreatetruecolor(240, 60);
        imagepng($image, $png);
        $media = $this->post('/v1/admin/media', ['file' => new UploadedFile($png, 'logo.png', 'image/png', null, true)], $h)->assertCreated()->json('id');
        $this->putJson('/v1/settings', ['values' => ['site.color' => 'E11D48', 'site.color_dark' => '#1E1B4B']], $h)->assertOk()->assertJsonPath('errors', []);
        $errors = $this->putJson('/v1/settings', ['values' => ['site.logo_image' => $media, 'site.color_dark' => 'lacivert']], $h)->assertOk()->json('errors');
        $this->assertSame(['site.color_dark' => 'Koyu bant rengi: #1F5EFF gibi altı haneli renk kodu olmalı.'], $errors);
        Sites::forgetMemory();
        $router = Router::load(Paths::siteRoot());
        $html = $router->shell($file, 'tr');
        $this->assertMatchesRegularExpression('#<img class="site-logo-img" src="/media/[^"]+\.png" alt="Yeni &amp; Marka">#', $html);
        $this->assertStringNotContainsString('site-logo-text', $html);
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/api/v1/theme\.css\?v=[0-9a-f]{10}"></head>#', $router->shell(Paths::siteRoot().'/tr/index.html', 'tr'));
        $css = $this->get('/v1/theme.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->getContent();
        $this->assertStringContainsString(':root { --color-primary: #e11d48;', $css);
        $this->assertStringContainsString('--color-navy: #1e1b4b;', $css);
        $this->assertStringContainsString(':root.theme-dark {', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark) { :root:not(.theme-light) {', $css);

        // Boş ayar: derlemedeki değerler.
        $this->putJson('/v1/settings', ['values' => ['site.name' => '', 'site.logo' => '', 'site.logo_sub' => '', 'site.logo_image' => 0, 'site.color' => '', 'site.color_dark' => '']], $h)->assertOk();
        Sites::forgetMemory();
        $this->assertSame('Kodmob', SiteManifest::siteName());
        $html = Router::load(Paths::siteRoot())->shell($file, 'tr');
        $this->assertStringContainsString('<span class="site-logo-text">kodmob</span>', $html);
        $this->assertStringNotContainsString('theme.css', Router::load(Paths::siteRoot())->shell(Paths::siteRoot().'/tr/index.html', 'tr'));
        $this->get('/v1/theme.css')->assertNotFound();
    }
}
