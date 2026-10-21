<?php

namespace Tests\Feature;

use App\Core\Support\Paths;
use App\Modules\Seo\Services\AiBots;
use App\Modules\Seo\Services\AiVisibility;
use App\Modules\Seo\Services\SeoConfig;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Yapay zekâ görünürlüğü (SEO > Yapay zekâ): bot kataloğu, render.php kaydının içeri alınması, robots.txt'deki
 * durum, site denetimi ve içerik hazırlığı. render.php'nin kendisi uçtan uca testte (seo.spec.js).
 */
class AiVisibilityTest extends TestCase
{
    /** Test sitesini arama motorlarına açık hale getirir (fixture yayın öncesi modunda). */
    private function indexable(): void
    {
        $root = $this->storage.'/site';
        File::copyDirectory(base_path('tests/fixtures/site'), $root);
        $manifest = json_decode((string) file_get_contents($root.'/km-manifest.json'), true);
        file_put_contents($root.'/km-manifest.json', json_encode(['indexable' => true] + $manifest));
        config(['kodmob.site_root' => $root]);
    }

    private function log(array ...$lines): void
    {
        File::ensureDirectoryExists(SeoConfig::dir());
        file_put_contents(SeoConfig::dir().'/'.AiVisibility::LOG, implode("\n", array_map('json_encode', $lines))."\n", FILE_APPEND);
    }

    public function test_catalog_recognises_user_agents(): void
    {
        $this->assertSame('GPTBot', AiBots::match('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot'));
        $this->assertSame('Claude-User', AiBots::match('Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)'));
        $this->assertSame('Perplexity-User', AiBots::match('Mozilla/5.0 (compatible; Perplexity-User/1.0)'));
        $this->assertSame('Googlebot', AiBots::match('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
        $this->assertNull(AiBots::match('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36'));
        $this->assertSame(1, preg_match(AiBots::pattern(), 'meta-externalagent/1.1'));
        $this->assertNotContains('Googlebot', AiBots::robotsTokens(), 'arama motoru "yapay zekâyı engelle" ile engellenmez');
        $this->assertContains('Google-Extended', AiBots::robotsTokens());
    }

    public function test_log_is_imported_and_reported(): void
    {
        $h = $this->admin();
        $this->indexable();
        $this->log(
            ['b' => 'GPTBot', 'p' => '/projeler/', 'c' => 200, 't' => now()->subDay()->toAtomString()],
            ['b' => 'gptbot', 'p' => '/projeler/', 'c' => 200, 't' => now()->toAtomString()],
            ['b' => 'ClaudeBot', 'p' => '/', 'c' => 200, 't' => now()->toAtomString()],
            ['b' => 'Googlebot', 'p' => '/', 'c' => 200, 't' => now()->toAtomString()],
            ['b' => 'BilinmeyenBot', 'p' => '/', 'c' => 200, 't' => now()->toAtomString()],
        );
        file_put_contents(SeoConfig::dir().'/'.AiVisibility::LOG, "bozuk satır\n", FILE_APPEND);

        $report = $this->getJson('/v1/seo/ai', $h)->assertOk()->json();
        $bots = collect($report['bots'])->keyBy('name');
        $this->assertSame([2, 1, 'OpenAI', 'training'], [$bots['GPTBot']['hits'], $bots['GPTBot']['pages'], $bots['GPTBot']['company'], $bots['GPTBot']['purpose']]);
        $this->assertSame('engine', $bots['Googlebot']['purpose']);
        $this->assertArrayNotHasKey('BilinmeyenBot', $bots->all());
        $this->assertSame(['training' => 3, 'search' => 0, 'user' => 0, 'engine' => 1], $report['totals']);
        // En çok okunan sayfalar yalnızca yapay zekâ botlarından (Googlebot sayılmaz).
        $this->assertSame([['/projeler/', 2], ['/', 1]], array_map(static fn ($p) => [$p['path'], $p['hits']], $report['pages']));
        $this->assertCount(30, $report['daily']);
        $this->assertFileDoesNotExist(SeoConfig::dir().'/'.AiVisibility::LOG, 'günlük içeri alınınca boşalır');

        // İkinci içeri alma aynı satırı büyütür.
        $this->log(['b' => 'GPTBot', 'p' => '/projeler/', 'c' => 404, 't' => now()->addMinute()->toAtomString()]);
        AiVisibility::import();
        $row = DB::table('seo_ai_hits')->where('bot', 'GPTBot')->where('day', now()->toDateString())->first();
        $this->assertSame([2, 404], [(int) $row->hits, (int) $row->status], 'bugünün satırı büyür; dünkü ayrı kalır');
        $this->assertSame(4, (int) DB::table('seo_ai_hits')->count());
    }

    public function test_robots_state_and_site_checks(): void
    {
        $h = $this->admin();
        // Yayın öncesi modunda bütün botlar engelli.
        $this->assertTrue(AiVisibility::blockedBots()['Googlebot']);

        $this->indexable();
        $this->assertSame([], array_keys(array_filter(AiVisibility::blockedBots())));
        $report = $this->getJson('/v1/seo/ai', $h)->json();
        $checks = collect($report['checks'])->keyBy('key');
        $this->assertSame('ok', $checks['robots']['status']);
        $this->assertSame('ok', $checks['ssr']['status']);
        $this->assertSame('info', $checks['visits']['status']);

        $this->putJson('/v1/settings', ['values' => ['seo.ai_bots' => 'block']], $h)->assertOk();
        $this->putJson('/v1/seo/robots', ['extra' => "User-agent: Googlebot\nUser-agent: bingbot\nDisallow: /\n\nUser-agent: *\nDisallow: /gizli/"], $h)->assertOk();
        $blocked = AiVisibility::blockedBots();
        $this->assertTrue($blocked['GPTBot'] && $blocked['Claude-SearchBot'] && $blocked['Googlebot'] && $blocked['bingbot']);
        $this->assertFalse($blocked['Applebot'], '"Disallow: /gizli/" kökü engellemez');
        $this->assertStringContainsString("User-agent: Claude-SearchBot\nDisallow: /", SeoConfig::robots());
        $this->assertStringNotContainsString('User-agent: Applebot'."\n", SeoConfig::robots());
        $checks = collect($this->getJson('/v1/seo/ai', $h)->json('checks'))->keyBy('key');
        $this->assertSame('warning', $checks['robots']['status']);
        $this->assertStringContainsString('GPTBot', $checks['robots']['detail']);
    }

    public function test_stale_compiled_config_is_refreshed_on_render(): void
    {
        File::ensureDirectoryExists(SeoConfig::dir());
        file_put_contents(SeoConfig::dir().'/config.json', json_encode(['enabled' => true, 'redirects' => []]));
        $router = Router::load(Paths::siteRoot());
        (new SitePage($router))->render($router->match('/'), '/');
        $compiled = json_decode((string) file_get_contents(SeoConfig::dir().'/config.json'), true);
        $this->assertSame(SeoConfig::SCHEMA, $compiled['schema']);
        $this->assertSame(1, preg_match($compiled['aiBots'], 'Mozilla/5.0 (compatible; ClaudeBot/1.0)'));
    }

    public function test_content_readiness_scores(): void
    {
        $h = $this->admin();
        $long = str_repeat('Tarım üreticilerini alıcılarla buluşturan dijital pazar yeri. ', 30);
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Hazır Proje', 'summary' => 'Tarım üreticilerini alıcılarla buluşturan, teklif ve sipariş yöneten dijital pazar yeri.', 'about' => $long]]], $h)->assertCreated();
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Eksik Proje']]], $h)->assertCreated();

        $content = collect($this->getJson('/v1/seo/ai', $h)->json('content'))->keyBy('title');
        $this->assertSame(100, $content['Hazır Proje']['score']);
        $this->assertSame([], $content['Hazır Proje']['missing']);
        $this->assertLessThan(50, $content['Eksik Proje']['score']);
        $this->assertStringContainsString('özet', implode(' ', $content['Eksik Proje']['missing']));
        $this->assertSame('Eksik Proje', $this->getJson('/v1/seo/ai', $h)->json('content.0.title'), 'en düşük puan önce');
    }
}
