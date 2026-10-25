<?php

namespace Tests\Feature;

use App\Core\Content\Locales;
use App\Core\Support\Paths;
use App\Modules\I18n\I18nConfig;
use App\Modules\Site\Render\Router;
use Tests\TestCase;

/**
 * Çoklu Dil sayfası (done-coklu-dil-raporu.md §5, Faz 8) ve sayfa metinlerinin Jarvis çevirisi (Faz 5):
 * genel bakış, sağlık, adresler, ziyaretçi ayarları, tek dilli mod.
 */
class LanguagePageTest extends TestCase
{
    private function project(array $locales, array $i18n): array
    {
        return $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'locales' => $locales, 'i18n' => $i18n], $this->admin())->assertCreated()->json();
    }

    public function test_overview_and_health_show_missing_translations(): void
    {
        $h = $this->admin();
        $this->project(['tr' => ['status' => 'publish'], 'en' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla'], 'en' => ['title' => 'Field']]);
        $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Sera']]);
        $date = now()->subDay()->format('Y-m-d\TH:i');
        $tr = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Merhaba', 'locale' => 'tr', 'html' => '<p>Bir</p>', 'date' => $date], $h)->json();
        $en = $this->postJson("/v1/admin/content/posts/{$tr['id']}/translations", ['locale' => 'en', 'mode' => 'copy'], $h)->json('item');
        $this->putJson("/v1/admin/content/posts/{$en['id']}", ['status' => 'publish', 'date' => $date] + $en, $h)->assertOk();
        $tr['html'] = '<p>İki</p>';
        $this->putJson("/v1/admin/content/posts/{$tr['id']}", $tr, $h)->assertOk();

        $overview = $this->getJson('/v1/languages/overview', $h)->assertOk();
        $en = collect($overview->json('languages'))->firstWhere('code', 'en');
        $this->assertSame(['published' => 1, 'missing' => 1, 'stale' => 0], $en['content']['projects']);
        $this->assertSame(['published' => 1, 'missing' => 0, 'stale' => 1], $en['content']['posts']);
        $this->assertTrue($en['live']);
        $this->assertGreaterThan(0, $overview->json('health.warnings'));

        $health = collect($this->getJson('/v1/languages/health', $h)->assertOk()->json('items'))->keyBy('key');
        $this->assertSame(1, $health['missing.projects.en']['count']);
        $this->assertSame(['route' => 'list', 'params' => ['type' => 'projects'], 'query' => ['translation' => 'missing']], $health['missing.projects.en']['link']);

        $this->getJson('/v1/languages/overview', $this->as('author'))->assertForbidden();
    }

    public function test_urls_list_build_paths_and_history(): void
    {
        $h = $this->admin();
        $p = $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla', 'slug' => 'tarla']]);
        $p['i18n']['tr']['slug'] = 'tarla-2';
        $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->assertOk();

        $urls = $this->getJson('/v1/languages/urls', $h)->assertOk();
        $project = collect($urls->json('routes'))->firstWhere('name', 'project');
        $this->assertSame(['tr' => '/projeler/:slug/', 'en' => '/en/projects/:slug/'], $project['paths']);
        $this->assertSame('/projeler/tarla/', $urls->json('history.0.from'));
        $this->assertSame(1, $urls->json('history_total'));
    }

    public function test_visitor_links_reach_the_404_page(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/languages/visitor', ['popular' => ['tr' => [['label' => 'Hizmetler', 'url' => '/hizmetler/'], ['label' => 'Kötü', 'url' => 'javascript:alert(1)']]]], $h)
            ->assertOk()->assertJsonPath('popular.tr', [['label' => 'Hizmetler', 'url' => '/hizmetler/']]);
        $this->assertDatabaseHas('events', ['event_type' => 'languages.visitor_updated']);
        $this->getJson('/v1/page-data?route=notFound&locale=tr')->assertJsonPath('popular.0.url', '/hizmetler/');
        $this->getJson('/v1/page-data?route=notFound&locale=en')->assertJsonPath('popular', []);
    }

    public function test_single_language_mode(): void
    {
        $h = $this->admin();
        $this->assertSame(['tr', 'en', 'de'], Locales::active());
        $this->getJson('/v1/page-data?route=home&locale=tr')->assertJsonPath('langBanner', true);

        $this->putJson('/v1/settings', ['values' => ['i18n.enabled' => false]], $h)->assertOk();
        $this->assertSame(['tr'], Locales::active());
        $this->assertFalse(json_decode((string) file_get_contents(I18nConfig::file()), true)['enabled']);
        $match = Router::load(Paths::siteRoot(), I18nConfig::file())->match('/en/projects/');
        $this->assertSame(['redirect', '/projeler/', 302], [$match['kind'], $match['location'] ?? null, $match['code'] ?? null]);
        $this->getJson('/v1/page-data?route=home&locale=tr')->assertJsonPath('languages', ['tr'])->assertJsonPath('langBanner', false);

        $this->putJson('/v1/settings', ['values' => ['i18n.enabled' => true]], $h)->assertOk();
        $this->assertSame(['tr', 'en', 'de'], Locales::active());
    }

    public function test_texts_translate_with_jarvis_sends_only_the_texts(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/texts/translate', ['from' => 'tr', 'to' => 'en', 'texts' => ['home.hero.title' => 'Merhaba']], $h)
            ->assertStatus(422)->assertJsonPath('code', 'km_ai_not_configured');

        $seen = [];
        app()->instance('i18n.translator', static function (array $fields, string $from, string $to) use (&$seen) {
            $seen = $fields;

            return array_map(static fn ($v) => 'EN '.$v, $fields);
        });
        $this->postJson('/v1/admin/texts/translate', ['from' => 'tr', 'to' => 'en', 'texts' => ['home.hero.title' => 'Merhaba', 'home.hero.lead' => '', 'x' => ['dizi']]], $h)
            ->assertOk()->assertJsonPath('texts', ['home.hero.title' => 'EN Merhaba']);
        $this->assertSame(['home.hero.title' => 'Merhaba'], $seen, 'çeviri motoruna yalnızca dolu metinler gider');
        $this->postJson('/v1/admin/texts/translate', ['from' => 'tr', 'to' => 'en', 'texts' => ['a' => 'b']], $this->as('author'))->assertForbidden();
    }
}
