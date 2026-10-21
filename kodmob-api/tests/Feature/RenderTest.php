<?php

namespace Tests\Feature;

use App\Core\Support\Paths;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Tests\TestCase;

class RenderTest extends TestCase
{
    private function render(string $path): array
    {
        $router = Router::load(Paths::siteRoot());
        $match = $router->match($path);

        return $match['kind'] === 'page' || $match['kind'] === 'sitemap' ? (new SitePage($router))->render($match, $path) : [0, $match];
    }

    public function test_page_embeds_escaped_data_and_text_overrides(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Kapanış </script><b>', 'summary' => 'Özet']]], $h)->assertCreated();
        $this->putJson('/v1/admin/texts', ['values' => ['tr' => ['home.hero.title' => "İlk satır\nİkinci"]]], $h);

        [$status, $html] = $this->render('/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<h1>İlk satır<br>'."\n".'İkinci</h1>', $html);
        $this->assertStringNotContainsString('</script><b>', $html);
        $this->assertStringContainsString('</script>', $html);
        $this->assertFileExists(Paths::pageCache().'/'.SitePage::cacheName('/', 200, 'html'));
    }

    public function test_detail_page_head_and_not_found(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Harman', 'tagline' => 'Tarım', 'summary' => 'Dijital tarım platformu']]], $h);

        [$status, $html] = $this->render('/projeler/harman/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<title>Harman · Tarım · Kodmob</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://kodmob.test/projeler/harman/">', $html);
        $this->assertStringContainsString('"@type":"CreativeWork"', $html);

        [$status] = $this->render('/projeler/yok/');
        $this->assertSame(404, $status);
        $this->assertSame(['kind' => 'redirect', 'location' => '/projeler/harman/', 'code' => 301], Router::load(Paths::siteRoot())->match('/projeler/harman'));

        // Kayıt sayfasının yapısal verisi ve kırıntı yolu türün kancasından / rotanın listesinden.
        $this->assertStringContainsString('"headline":"Tarım"', $html);
        $this->assertStringContainsString('"name":"Projeler","item":"https://kodmob.test/projeler/"', $html);
    }

    /** Site araması: her kayıt adresini ve türünü sunucudan getirir (tarayıcı türleri bilmez). */
    public function test_search_index_carries_urls_and_kinds(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Harman', 'tagline' => 'Tarım', 'summary' => 'Özet']]], $h)->assertCreated();
        $this->postJson('/v1/admin/content/services', ['status' => 'publish', 'key' => 'web', 'i18n' => ['tr' => ['title' => 'Web', 'text' => 'Web siteleri']]], $h)->assertCreated();

        $index = $this->getJson('/v1/search-index?locale=tr')->assertOk()->json();
        $this->assertSame(['services', 'projects', 'pages', 'posts'], array_keys($index));
        $this->assertSame(['slug' => 'harman', 'title' => 'Harman', 'text' => 'Tarım · Özet', 'type' => 'project', 'url' => '/projeler/harman/'], $index['projects'][0]);
        // Her sayfada gelen tür kendi adındaki sayfanın bölümüne gider; test manifestinde hizmetler sayfası yok.
        $this->assertSame(['key' => 'web', 'type' => 'service', 'url' => null], array_intersect_key($index['services'][0], ['key' => 1, 'type' => 1, 'url' => 1]));
    }

    public function test_sitemap_lists_static_pages_and_projects(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Proje B']]], $h);

        [$status, $xml] = $this->render('/sitemap.xml');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<loc>https://kodmob.test/projeler/proje-b/</loc>', $xml);
        $this->assertStringContainsString('hreflang="x-default"', $xml);
    }

    public function test_content_change_flushes_page_cache(): void
    {
        $this->render('/');
        $this->assertNotEmpty(glob(Paths::pageCache().'/*.html'));
        $this->putJson('/v1/settings', ['values' => ['site.phone' => '1']], $this->admin());
        $this->assertEmpty(glob(Paths::pageCache().'/*.html'));
    }
}
