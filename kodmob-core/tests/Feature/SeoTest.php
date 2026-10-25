<?php

namespace Tests\Feature;

use App\Core\Support\Paths;
use App\Modules\Blog\BlogPosts;
use App\Modules\Seo\Services\Llms;
use App\Modules\Seo\Services\SeoConfig;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SEO modülü: kayıt ve sayfa SEO'su, kapatma, yönlendirmeler, 404, analiz, robots, llms, içerik süzgeçleri. */
class SeoTest extends TestCase
{
    private function render(string $path): array
    {
        $router = Router::load(Paths::siteRoot());

        return (new SitePage($router))->render($router->match($path), $path);
    }

    private function project(array $h): int
    {
        return $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Harman', 'tagline' => 'Tarım', 'summary' => 'Dijital tarım platformu']]], $h)->assertCreated()->json('id');
    }

    public function test_record_seo_overrides_and_master_switch(): void
    {
        $h = $this->admin();
        $id = $this->project($h);

        $this->putJson("/v1/seo/meta/project/$id", [
            'locales' => ['tr' => ['title' => 'Tarım pazaryeri yazılımı', 'description' => 'Çiftçiyi alıcıyla buluşturan dijital pazaryeri.', 'focus' => 'tarım yazılımı']],
            'noindex' => true,
            'canonical' => '/projeler/',
        ], $h)->assertOk()->assertJsonPath('locales.tr.title', 'Tarım pazaryeri yazılımı')->assertJsonPath('noindex', true);
        $this->assertSame('seo.meta_updated', $this->getJson('/v1/history', $h)->json('items.0.type'));

        [, $html] = $this->render('/projeler/harman/');
        $this->assertStringContainsString('<title>Tarım pazaryeri yazılımı · Kodmob</title>', $html);
        $this->assertStringContainsString('content="Çiftçiyi alıcıyla buluşturan dijital pazaryeri."', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://kodmob.test/projeler/">', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        $this->assertStringNotContainsString('/projeler/harman/</loc>', $this->render('/sitemap.xml')[1]);

        // SEO tamamen kapalı: varsayılan başlık, robots dokunulmaz, harita eksiksiz.
        $this->putJson('/v1/settings', ['values' => ['seo.enabled' => false]], $h)->assertOk();
        [, $html] = $this->render('/projeler/harman/');
        $this->assertStringContainsString('<title>Harman · Tarım · Kodmob</title>', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('/projeler/harman/</loc>', $this->render('/sitemap.xml')[1]);
        $config = json_decode((string) file_get_contents(SeoConfig::dir().'/config.json'), true);
        $this->assertFalse($config['enabled']);
    }

    public function test_static_page_meta_verification_and_schema(): void
    {
        $h = $this->admin();
        $pages = $this->getJson('/v1/seo/pages', $h)->assertOk()->json('items');
        $home = collect($pages)->firstWhere('key', 'home-tr');
        $this->assertSame('Ana Sayfa · Kodmob', $home['default_title']);
        $this->assertSame('Varsayılan açıklama', $home['default_description']);

        $this->putJson('/v1/seo/pages', ['route' => 'home', 'locale' => 'tr', 'title' => 'Kodmob · Yazılım ajansı', 'description' => 'Web ve mobil yazılım.'], $h)->assertOk();
        $this->putJson('/v1/settings', ['values' => ['seo.verify_google' => 'abc123', 'site.phone' => '+90 555 000 00 00', 'seo.twitter' => 'kodmob']], $h)->assertOk();

        [, $html] = $this->render('/');
        $this->assertStringContainsString('<title>Kodmob · Yazılım ajansı</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Web ve mobil yazılım.">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Web ve mobil yazılım.">', $html);
        $this->assertStringContainsString('<meta name="google-site-verification" content="abc123">', $html);
        $this->assertStringContainsString('<meta name="twitter:site" content="@kodmob">', $html);
        $this->assertStringContainsString('"telephone":"+90 555 000 00 00"', $html);
        $this->assertStringContainsString('"@type":"WebSite"', $html);

        // Modül kapalıysa doğrulama etiketi çıkmaz.
        $this->putJson('/v1/settings', ['values' => ['seo.module.webmaster' => false]], $h)->assertOk();
        $this->assertStringNotContainsString('google-site-verification', $this->render('/')[1]);
    }

    public function test_redirects_validation_csv_and_compiled_config(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/seo/redirects', ['source' => '/eski', 'target' => '/eski/', 'code' => 301], $h)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['target']]]);
        $this->postJson('/v1/seo/redirects', ['source' => '([a-', 'is_regex' => true, 'target' => '/'], $h)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['source']]]);
        $this->postJson('/v1/seo/redirects', ['source' => '/panel/x', 'target' => '/'], $h)->assertStatus(422);

        $id = $this->postJson('/v1/seo/redirects', ['source' => 'https://kodmob.com/eski-proje/', 'target' => '/projeler/', 'code' => 301], $h)->assertCreated()->assertJsonPath('source', '/eski-proje')->json('id');
        $this->postJson('/v1/seo/redirects', ['source' => '/eski-proje', 'target' => '/x/'], $h)->assertStatus(422);
        $this->postJson('/v1/seo/redirects', ['source' => '^/blog/(\d+)/(.+)$', 'is_regex' => true, 'target' => '/blog/$2/', 'code' => 302], $h)->assertCreated();
        $this->postJson('/v1/seo/redirects', ['source' => '/kaldirildi', 'code' => 410], $h)->assertCreated()->assertJsonPath('target', '');

        $this->postJson('/v1/seo/redirects/test', ['url' => 'https://kodmob.com/blog/12/yazi'], $h)->assertOk()->assertJsonPath('location', '/blog/yazi/')->assertJsonPath('code', 302);
        $this->postJson('/v1/seo/redirects/test', ['url' => '/eski-proje/?a=1'], $h)->assertJsonPath('rule.id', $id);

        $config = json_decode((string) file_get_contents(SeoConfig::dir().'/config.json'), true);
        $this->assertSame([$id, '/projeler/', 301], $config['redirects']['exact']['/eski-proje']);
        $this->assertSame('#^/blog/(\d+)/(.+)$#u', $config['redirects']['regex'][0][1]);

        // render.php'nin yazdığı tıklanmalar listede sayılır.
        file_put_contents(SeoConfig::dir().'/hits.log', "$id\t2026-10-15T10:00:00+03:00\n$id\t2026-10-15T11:00:00+03:00\n");
        $list = $this->getJson('/v1/seo/redirects?q=eski', $h)->assertOk();
        $list->assertJsonPath('total', 1)->assertJsonPath('items.0.hits', 2);

        $csv = $this->get('/v1/seo/redirects/export', $h)->assertOk()->getContent();
        $this->assertStringContainsString('/eski-proje;/projeler/;301', $csv);
        $result = $this->postJson('/v1/seo/redirects/import', ['csv' => "source;target;code\n/a;/;301\n/eski-proje;/blog/;301\nbozuk;;"], $h)->assertOk();
        $result->assertJsonPath('added', 1)->assertJsonPath('updated', 1);
        $this->assertCount(1, $result->json('errors'));
    }

    public function test_404_log_import_and_actions(): void
    {
        $h = $this->admin();
        @mkdir(SeoConfig::dir(), 0755, true);
        $line = static fn ($p, $bot = false) => json_encode(['p' => $p, 'r' => 'https://google.com/', 'b' => $bot, 't' => '2026-10-15T10:00:00+03:00'])."\n";
        file_put_contents(SeoConfig::dir().'/404.log', $line('/eski-sayfa').$line('/eski-sayfa').$line('/x.php', true));

        $list = $this->getJson('/v1/seo/404?sort=hits', $h)->assertOk();
        $list->assertJsonPath('total', 2)->assertJsonPath('items.0.path', '/eski-sayfa')->assertJsonPath('items.0.hits', 2)->assertJsonPath('facets.status.new', 2);

        $this->postJson('/v1/seo/redirects', ['source' => '/eski-sayfa', 'target' => '/'], $h)->assertCreated();
        $this->assertSame('redirected', DB::table('seo_404')->where('path', '/eski-sayfa')->value('status'));

        $botId = DB::table('seo_404')->where('path', '/x.php')->value('id');
        $this->postJson('/v1/seo/404/status', ['ids' => [$botId], 'status' => 'ignored'], $h)->assertOk()->assertJsonPath('updated', 1);
        $this->postJson('/v1/seo/404/delete', ['ids' => [$botId]], $h)->assertOk()->assertJsonPath('deleted', 1);
    }

    public function test_analysis_robots_llms_and_content_filters(): void
    {
        $h = $this->admin();
        $analysis = $this->postJson('/v1/seo/analyze', [
            'kind' => 'post', 'locale' => 'tr', 'title' => 'Laravel ile hızlı web', 'description' => 'Kısa',
            'focus' => 'laravel', 'slug' => 'laravel-ile-hizli-web', 'html' => '<h1>Yanlış</h1><p>Laravel güçlü.</p>',
        ], $h)->assertOk();
        $checks = collect($analysis->json('checks'))->keyBy('key');
        $this->assertSame('good', $checks['focus_title']['status']);
        $this->assertSame('good', $checks['focus_slug']['status']);
        $this->assertSame('bad', $checks['headings']['status']);
        $this->assertLessThan(70, $analysis->json('score'));

        $this->putJson('/v1/seo/robots', ['extra' => 'bu bir kural değil'], $h)->assertStatus(422);
        $this->putJson('/v1/seo/robots', ['extra' => "Disallow: /teklif-hesapla/\n# not"], $h)->assertOk()->assertJsonPath('indexable', false)->assertJsonPath('preview', "User-agent: *\nDisallow: /\n");
        $this->putJson('/v1/settings', ['values' => ['seo.ai_bots' => 'block']], $h)->assertOk();
        $robots = SeoConfig::robots();
        $this->assertStringContainsString('Disallow: /teklif-hesapla/', $robots);
        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /", $robots);
        $this->assertStringContainsString('Sitemap: https://kodmob.test/sitemap.xml', $robots);

        $this->project($h);
        $post = (int) $this->postJson('/v1/admin/content/posts', [
            'status' => 'publish', 'title' => 'Rehber', 'locale' => 'tr', 'excerpt' => 'Özet',
            'html' => '<p>Giriş <a href="https://laravel.com/docs">belgeler</a> ve <a href="/iletisim/">iletişim</a>.</p><h2>Bir</h2><p>a</p><h2>İki</h2><p>b</p><h3>Üç</h3><p>c</p>',
        ], $h)->assertCreated()->json('id');
        $html = (new BlogPosts)->findBySlug('rehber', 'tr')['html'];
        $this->assertStringContainsString('<nav class="toc" aria-label="İçindekiler">', $html);
        $this->assertStringContainsString('<a href="#bir">Bir</a>', $html);
        $this->assertStringContainsString('<h2 id="iki">', $html);
        $this->assertStringContainsString('href="https://laravel.com/docs" target="_blank" rel="noopener"', $html);
        $this->assertStringNotContainsString('href="/iletisim/" target', $html);

        $llms = Llms::build(false);
        $this->assertStringStartsWith('# ', $llms);
        $this->assertStringContainsString('- [Harman](https://kodmob.test/projeler/harman/): Dijital tarım platformu', $llms);
        $this->putJson("/v1/seo/meta/post/$post", ['noindex' => true], $h)->assertOk();
        $this->assertStringNotContainsString('[Rehber]', Llms::build(false));
        $this->assertStringContainsString('#### Harman', Llms::build(true));

        $content = $this->getJson('/v1/seo/content', $h)->assertOk()->json('items');
        $this->assertSame(['project', 'post'], array_values(array_unique(array_column($content, 'type'))));
        $this->getJson('/v1/seo', $h)->assertOk()->assertJsonPath('state.enabled', true)->assertJsonStructure(['modules', 'stats', 'checks', 'urls']);
    }

    public function test_indexnow_skips_when_site_is_not_indexable(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/settings', ['values' => ['seo.module.indexnow' => true]], $h)->assertOk();
        $info = $this->getJson('/v1/seo/indexnow', $h)->assertOk();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $info->json('key'));
        $this->postJson('/v1/seo/indexnow/submit', ['urls' => ['https://kodmob.test/']], $h)->assertOk()->assertJsonPath('status', 'skipped');
        $this->assertSame($info->json('key'), json_decode((string) file_get_contents(SeoConfig::dir().'/config.json'), true)['indexnow']);
    }
}
