<?php

namespace Tests\Feature;

use App\Core\Support\Paths;
use App\Modules\Seo\Services\Llms;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Tests\TestCase;

/**
 * Site adresleri (Faz 3): varsayılan dil öneksiz, eski adresler 301, yayında olmayan dil
 * 302. Vektörler tests/fixtures/site-urls.json'da; ön yüz (tv/tests/site-urls.test.mjs)
 * aynı adresleri gerçek rota tablosundan üretir.
 */
class SiteUrlsTest extends TestCase
{
    private array $vectors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/site-urls.json')), true);
    }

    public function test_urls_match_shared_vectors(): void
    {
        $router = Router::load(Paths::siteRoot(), '/yok');
        $this->assertSame('tr', $router->defaultLocale());
        foreach ($this->vectors['urls'] as $v) {
            $this->assertSame($v['url'], $router->url($v['name'], $v['locale'], $v['slug'] ?? ''), $v['name'].' '.$v['locale']);
        }
    }

    public function test_paths_resolve_to_pages_redirects_and_404(): void
    {
        $this->assertMatches(Router::load(Paths::siteRoot(), '/yok'), $this->vectors['matches']);
    }

    public function test_root_redirects_only_when_default_language_is_prefixed(): void
    {
        $this->assertSame('page', Router::load(Paths::siteRoot(), '/yok')->match('/')['kind']);

        // Varsayılan dil önekli bir derleme: kök adres o dilin ana sayfasına 302.
        $root = sys_get_temp_dir().'/km-site-'.uniqid();
        mkdir($root);
        $manifest = json_decode((string) file_get_contents(Paths::siteRoot().'/km-manifest.json'), true);
        $manifest['locales']['tr']['prefix'] = 'tr';
        foreach ($manifest['routes'] as &$route) {
            if ($route['locale'] === 'tr') {
                foreach (['path', 'pattern'] as $key) {
                    if (isset($route[$key])) {
                        $route[$key] = '/tr'.$route[$key];
                    }
                }
            }
        }
        unset($route);
        file_put_contents($root.'/km-manifest.json', json_encode($manifest));
        $match = Router::load($root, '/yok')->match('/');
        $this->assertSame(['redirect', '/tr/', 302], [$match['kind'], $match['location'], $match['code']]);
        @unlink($root.'/km-manifest.json');
        @rmdir($root);
    }

    public function test_inactive_language_redirects_to_default(): void
    {
        $file = sys_get_temp_dir().'/km-i18n-'.uniqid().'.json';
        file_put_contents($file, json_encode(['default' => 'tr', 'languages' => $this->vectors['inactive']['languages']]));
        $router = Router::load(Paths::siteRoot(), $file);
        $this->assertSame(['tr'], $router->activeLocales());
        $this->assertMatches($router, $this->vectors['inactive']['matches']);
        @unlink($file);
    }

    public function test_rendered_pages_list_only_active_languages(): void
    {
        // Derlenmiş kopya yokken derlemedeki bütün diller yayında.
        $sitemap = $this->render('/sitemap.xml')[1];
        $this->assertStringContainsString('<loc>https://kodmob.test/</loc>', $sitemap);
        $this->assertStringContainsString('hreflang="x-default" href="https://kodmob.test/"', $sitemap);
        $this->assertStringContainsString('<loc>https://kodmob.test/en/</loc>', $sitemap);
        $this->assertStringContainsString('hreflang="en"', $this->render('/')[1]);

        // İngilizce pasif: haritadan ve sayfa başından çıkar, sayfa verisi yalnızca Türkçe'yi taşır.
        $this->putJson('/v1/languages/en', ['active' => false], $this->admin())->assertOk();
        $sitemap = $this->render('/sitemap.xml')[1];
        $this->assertStringNotContainsString('https://kodmob.test/en/', $sitemap);
        $this->assertStringNotContainsString('x-default', $sitemap, 'tek dilde x-default yok');
        [$status, $html] = $this->render('/');
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="tr"', $html);
        $this->assertStringContainsString('"languages":["tr"', $html);
    }

    public function test_language_registry_hreflang_and_og_reach_the_site(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/languages/en', ['hreflang' => 'en-GB', 'og' => 'en_GB'], $h)->assertOk();
        [, $html] = $this->render('/');
        $this->assertStringContainsString('hreflang="en-GB" href="https://kodmob.test/en/"', $html);
        $this->assertStringNotContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('<meta property="og:locale:alternate" content="en_GB">', $html);
        $this->assertStringContainsString('<meta property="og:locale" content="tr_TR">', $html, 'değişmeyen dil olduğu gibi');
        $this->assertStringContainsString('hreflang="en-GB" href="https://kodmob.test/en/"', $this->render('/sitemap.xml')[1]);

        // Pasif dilin og:locale:alternate'i ve tek dil kalınca x-default da çıkar.
        $this->putJson('/v1/languages/en', ['active' => false], $h)->assertOk();
        [, $html] = $this->render('/');
        $this->assertStringNotContainsString('og:locale:alternate', $html);
        $this->assertStringNotContainsString('x-default', $html);
    }

    public function test_llms_txt_survives_a_language_without_headings(): void
    {
        // Derlemeye sonradan eklenen dil (bölüm başlığı tanımlı değil): İngilizce başlıklar, dilin kendi adı.
        $root = sys_get_temp_dir().'/km-site-'.uniqid();
        mkdir($root);
        $manifest = json_decode((string) file_get_contents(Paths::siteRoot().'/km-manifest.json'), true);
        $manifest['locales']['fr'] = ['prefix' => 'fr', 'label' => 'Français', 'short' => 'FR', 'intl' => 'fr-FR', 'og' => 'fr_FR'];
        foreach ($manifest['routes'] as $route) {
            if ($route['locale'] === 'en' && ($route['name'] ?? '') === 'home') {
                $manifest['routes'][] = ['locale' => 'fr', 'path' => '/fr/'] + $route;
            }
        }
        file_put_contents($root.'/km-manifest.json', json_encode($manifest));
        config(['kodmob.site_root' => $root]);
        $this->postJson('/v1/languages', ['code' => 'fr', 'name' => 'Fransızca', 'native' => 'Français'], $this->admin())->assertSuccessful();
        $this->putJson('/v1/languages/fr', ['active' => true], $this->admin())->assertOk();

        $llms = Llms::build(false);
        $this->assertStringContainsString('## Français', $llms);
        $this->assertStringContainsString('### Pages', $llms);
        @unlink($root.'/km-manifest.json');
        @rmdir($root);
    }

    private function assertMatches(Router $router, array $cases): void
    {
        foreach ($cases as $v) {
            $match = $router->match($v['path']);
            $label = $v['path'].(isset($v['why']) ? ' ('.$v['why'].')' : '');
            if (isset($v['redirect'])) {
                $this->assertSame(['kind' => 'redirect', 'location' => $v['redirect'], 'code' => $v['code']], $match, $label);

                continue;
            }
            $this->assertSame($v['kind'], $match['kind'], $label);
            if (isset($v['name'])) {
                $this->assertSame($v['name'], $match['route']['name'], $label);
            }
            $this->assertSame($v['locale'], $match['locale'], $label);
            if (isset($v['slug'])) {
                $this->assertSame($v['slug'], $match['slug'], $label);
            }
            if (isset($v['status'])) {
                $this->assertSame($v['status'], $match['status'], $label);
            }
        }
    }

    /** @return array{0:int,1:string} */
    private function render(string $path): array
    {
        $router = Router::load(Paths::siteRoot());

        return (new SitePage($router))->render($router->match($path), $path);
    }
}
