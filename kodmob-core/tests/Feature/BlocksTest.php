<?php

namespace Tests\Feature;

use App\Core\Content\PageCache;
use App\Core\Sites\Sites;
use App\Core\Support\Paths;
use App\Modules\Content\Backup;
use App\Modules\Content\PageTexts;
use App\Modules\Site\Blocks\BlockRenderer;
use App\Modules\Site\Blocks\Template;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sayfa düzenleyicinin blokları (Modules\Site\Blocks; docs/done-onyuz-raporu.md): şablon, bölgeye yazma, taslak ve
 * yayın, önizleme anahtarı, kitaplık (Keşfet), yedek. Test sitesinin kitaplığı tests/fixtures/site/km-blocks.json.
 */
class BlocksTest extends TestCase
{
    private function render(string $path, string $preview = ''): array
    {
        $router = Router::load(Paths::siteRoot());

        return (new SitePage($router))->render($router->match($path), $path, $preview);
    }

    public function test_template_matches_the_shared_examples(): void
    {
        $cases = json_decode((string) file_get_contents(base_path('tests/fixtures/blocks/template.json')), true)['cases'];
        foreach ($cases as $case) {
            $this->assertSame($case['html'], Template::render($case['template'], $case['data']), $case['name']);
        }
        $this->expectException(\InvalidArgumentException::class);
        Template::parse('{{#a}}açık kaldı');
    }

    public function test_published_layout_replaces_the_default_blocks(): void
    {
        $h = $this->admin();
        [, $html] = $this->render('/');
        $this->assertStringContainsString('<a href="/blog/">Varsayılan not</a>', $html);
        $this->assertStringNotContainsString('<!--km:zone', $html);

        $saved = $this->putJson('/v1/admin/layouts/home.main', ['blocks' => [
            ['type' => 'note', 'data' => ['url' => 'javascript:alert(1)', 'tone' => 'loud', 'extra' => 'x'], 'i18n' => ['tr' => ['text' => 'Yeni <b>not</b> & "tırnak"'], 'xx' => ['text' => 'yok']]],
            ['type' => 'list', 'i18n' => ['tr' => ['items' => [['title' => 'A', 'icon' => 'star', 'url' => 'route:blog'], ['title' => '', 'url' => ''], ['title' => 'B', 'icon' => 'yok-boyle']], 'body' => '<p>x</p><script>alert(1)</script>']]],
        ]], $h)->assertOk();
        // Temizlik: tehlikeli bağlantı, bilinmeyen alan ve dil, boş satır, bilinmeyen ikon, betik.
        $this->assertSame(['url' => '', 'tone' => 'loud'], $saved->json('draft.0.data'));
        $this->assertSame(['tr'], array_keys($saved->json('draft.0.i18n')));
        $this->assertSame([['title' => 'A', 'icon' => 'star', 'url' => 'route:blog'], ['title' => 'B', 'icon' => 'check', 'url' => '']], $saved->json('draft.1.i18n.tr.items'));
        $this->assertSame('<p>x</p>', $saved->json('draft.1.i18n.tr.body'));
        $this->assertMatchesRegularExpression('/^[a-z0-9]{1,16}$/', $saved->json('draft.1.id'));

        // Taslak ziyaretçiye görünmez.
        [, $html] = $this->render('/');
        $this->assertStringContainsString('Varsayılan not', $html);

        $this->postJson('/v1/admin/layouts/publish', [], $h)->assertOk()->assertJsonPath('published', ['home.main']);
        [, $html] = $this->render('/');
        $this->assertStringContainsString('<p class="note is-loud"><a href="">Yeni not &amp; &quot;tırnak&quot;</a><svg><use href="/assets/img/icons-test.svg#i-check"/></svg></p>', $html);
        $this->assertStringContainsString('<li data-n="1"><a href="/blog/">A</a><i>star</i></li>', $html);
        $this->assertStringContainsString('<div class="body"><p>x</p></div>', $html);
        $this->assertStringNotContainsString('data-km-field', $html);
        $this->assertStringNotContainsString('Varsayılan not', $html);
        // Liste bloğunun ihtiyacı (posts) sayfa verisine eklenir.
        $this->assertMatchesRegularExpression('/id="km-data">\{[^<]*"posts":/', $html);
        $this->assertSame(1, DB::table('events')->where('event_type', 'layouts.published')->count());

        // İngilizce sayfa: çevrilmemiş metin varsayılan dilinkinden; bağlantı İngilizce rotaya.
        [, $en] = $this->render('/en/');
        $this->assertStringContainsString('Yeni not &amp; &quot;tırnak&quot;', $en);
    }

    public function test_preview_draws_drafts_with_editor_marks_and_is_not_cached(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/admin/layouts/home.main', ['blocks' => [
            ['id' => 'a1', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'Taslak']]],
            ['id' => 'a2', 'type' => 'note', 'hidden' => true, 'i18n' => ['tr' => ['text' => 'Gizli']]],
        ]], $h)->assertOk();
        $token = $this->postJson('/v1/admin/layouts/preview', [], $h)->assertOk()->json('token');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);

        [$status, $html, $headers] = $this->render('/', $token);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<div data-km-zone="home.main"><div class="km-block" data-km-block="note" data-km-id="a1">', $html);
        $this->assertStringContainsString('data-km-field="text">Taslak</a>', $html);
        // Gizli blok önizlemede soluk çizilmek üzere işaretli; düzenlenmemiş bölge de sarılı.
        $this->assertStringContainsString('data-km-id="a2" data-km-hidden>', $html);
        $this->assertStringContainsString('<div data-km-zone="site.bottom"></div>', $html);
        $this->assertSame('no-store', $headers['Cache-Control']);
        $this->assertFileDoesNotExist(Paths::pageCache().'/'.SitePage::cacheName('/', 200, 'html'));

        // Geçersiz anahtar: olağan sayfa (taslak yok, önbelleğe yazılır).
        [, $html] = $this->render('/', str_repeat('0', 40));
        $this->assertStringContainsString('Varsayılan not', $html);
        $this->assertStringNotContainsString('data-km-zone', $html);

        // Yetkisi olmayan rol düzenleyiciyi açamaz.
        $this->getJson('/v1/admin/layouts', $this->as('author'))->assertForbidden();
        $this->getJson('/v1/admin/layouts', $this->as('editor'))->assertOk();
    }

    public function test_editor_payload_revisions_and_discard(): void
    {
        $h = $this->admin();
        $index = $this->getJson('/v1/admin/layouts', $h)->assertOk();
        $this->assertTrue($index->json('built'));
        $this->assertSame(['note', 'list', 'extra'], array_keys($index->json('blocks')));
        $this->assertSame('d1', $index->json('zones')['home.main']['defaults'][0]['id']);
        $this->assertNull($index->json('zones')['home.main']['published']);
        $this->assertSame(['home.main', 'site.bottom'], $index->json('pages.0.zones'));

        $v1 = [['id' => 'x1', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'Bir']]]];
        $this->putJson('/v1/admin/layouts/home.main', ['blocks' => $v1], $h)->assertOk();
        $this->postJson('/v1/admin/layouts/publish', ['zones' => ['home.main']], $h)->assertOk();
        $this->putJson('/v1/admin/layouts/home.main', ['blocks' => [['id' => 'x2', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'İki']]]]], $h)->assertOk();
        $this->postJson('/v1/admin/layouts/publish', [], $h)->assertOk();

        $revisions = $this->getJson('/v1/admin/layouts/home.main/revisions', $h)->assertOk()->json('items');
        $this->assertCount(1, $revisions);
        $this->assertSame([1, 'Yönetici'], [$revisions[0]['count'], $revisions[0]['user']]);
        $this->postJson('/v1/admin/layouts/home.main/revisions/'.$revisions[0]['id'], [], $h)->assertOk()->assertJsonPath('draft.0.i18n.tr.text', 'Bir');
        $this->assertSame('Bir', $this->getJson('/v1/admin/layouts', $h)->json('zones')['home.main']['draft'][0]['i18n']['tr']['text']);

        $this->deleteJson('/v1/admin/layouts/home.main/draft', [], $h)->assertOk()->assertJsonPath('draft', null);
        [, $html] = $this->render('/');
        $this->assertStringContainsString('İki', $html);

        // Bilinmeyen bölge, kapalı modülün bloğu, sınır.
        $this->putJson('/v1/admin/layouts/yok.bolge', ['blocks' => []], $h)->assertNotFound();
        $this->putJson('/v1/admin/layouts/home.main', ['blocks' => [['type' => 'shopnote']]], $h)->assertStatus(422);
        $this->putJson('/v1/admin/layouts/home.main', ['blocks' => array_fill(0, 41, ['type' => 'note'])], $h)->assertStatus(422);
        // Boş liste bölgeyi boşaltır (varsayılana dönmez).
        $this->putJson('/v1/admin/layouts/home.main', ['blocks' => []], $h)->assertOk();
        $this->postJson('/v1/admin/layouts/publish', [], $h)->assertOk();
        [, $html] = $this->render('/');
        $this->assertStringNotContainsString('km-block', $html);
    }

    public function test_single_block_render_for_the_editor(): void
    {
        $h = $this->admin();
        $html = $this->postJson('/v1/admin/layouts/render', ['locale' => 'en', 'block' => ['id' => 'b1', 'type' => 'note', 'i18n' => ['en' => ['text' => 'Hi']]]], $h)
            ->assertOk()->json('html');
        // Verilmeyen ortak alan bloğun varsayılanından (route:blog → İngilizce adres).
        $this->assertSame('<div class="km-block" data-km-block="note" data-km-id="b1"><p class="note is-plain"><a href="/en/blog/" data-km-field="text">Hi</a><svg><use href="#i-check"/></svg></p></div>', $html);
    }

    public function test_zone_render_returns_every_block(): void
    {
        $items = $this->postJson('/v1/admin/layouts/render', ['locale' => 'tr', 'blocks' => [
            ['id' => 'a1', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'Bir']]],
            ['id' => 'a1', 'type' => 'note', 'hidden' => true, 'i18n' => ['tr' => ['text' => 'İki']]],
        ]], $this->admin())->assertOk()->json('items');
        $this->assertCount(2, $items);
        // Yinelenen kimlik yenilenir; gizli blok önizlemede işaretli.
        $this->assertSame('a1', $items[0]['id']);
        $this->assertNotSame('a1', $items[1]['id']);
        $this->assertStringContainsString('data-km-hidden', $items[1]['html']);
    }

    public function test_jarvis_writes_translates_and_suggests(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/layouts/assist', ['type' => 'note', 'locale' => 'tr', 'values' => ['text' => 'a'], 'instruction' => 'x'], $h)
            ->assertStatus(422)->assertJsonPath('code', 'km_ai_not_configured');

        $seen = [];
        app()->instance('blocks.assist', function (string $task, array $request) use (&$seen) {
            $seen[$task] = $request;

            return $task === 'write'
                ? ['text' => mb_strtoupper($request['texts']['text'])]
                : ['blocks' => [['type' => 'note', 'texts' => ['text' => 'Öneri']], ['type' => 'yok', 'texts' => []], ['type' => 'extra', 'texts' => ['text' => 'Kitaplıkta değil']]]];
        });
        app()->instance('i18n.translator', static function (array $fields, string $from, string $to) {
            array_walk_recursive($fields, static function (&$v) use ($to) {
                $v = "[{$to}] {$v}";
            });

            return $fields;
        });
        $this->assertTrue($this->getJson('/v1/admin/layouts', $h)->json('ai'));

        // Yalnızca bloğun dile bağlı metinleri gider (bağlantı, ton gitmez).
        $this->postJson('/v1/admin/layouts/assist', ['type' => 'note', 'locale' => 'tr', 'values' => ['text' => 'merhaba', 'url' => '/gizli'], 'instruction' => 'büyük harf'], $h)
            ->assertOk()->assertJsonPath('texts.text', 'MERHABA');
        $this->assertSame(['text' => 'merhaba'], $seen['write']['texts']);
        $this->postJson('/v1/admin/layouts/assist', ['type' => 'note', 'locale' => 'tr', 'values' => ['text' => 'a'], 'instruction' => ' '], $h)->assertStatus(422);

        $i18n = $this->postJson('/v1/admin/layouts/translate', ['type' => 'list', 'from' => 'tr', 'to' => ['en', 'tr', 'xx'], 'values' => ['items' => [['title' => 'Bir', 'icon' => 'star', 'url' => '/a/']], 'body' => '<p>Metin</p>']], $h)
            ->assertOk()->json('i18n');
        $this->assertSame(['en'], array_keys($i18n));
        $this->assertSame(['items' => [['title' => '[en] Bir']], 'body' => '[en] <p>Metin</p>'], $i18n['en']);

        $this->postJson('/v1/admin/layouts/suggest', ['page' => 'Ana sayfa', 'locale' => 'tr', 'brief' => 'tur acentesi'], $h)
            ->assertOk()->assertJsonPath('blocks', [['type' => 'note', 'values' => ['text' => 'Öneri']]]);
        $this->assertSame(['note', 'list'], $seen['suggest']['library']);
    }

    public function test_discover_adds_blocks_to_the_library(): void
    {
        $h = $this->admin();
        $items = collect($this->getJson('/v1/admin/blocks', $h)->assertOk()->json('items'))->keyBy('type');
        $this->assertSame(['note', 'list', 'extra'], $items->keys()->all());
        $this->assertSame([true, false, false], [$items['note']['inLibrary'], $items['extra']['inLibrary'], $items['extra']['core']]);

        $this->putJson('/v1/admin/blocks/extra', ['added' => true], $h)->assertOk()->assertJsonPath('inLibrary', true);
        $this->assertSame(1, DB::table('events')->where('event_type', 'blocks.library')->count());
        // Varsayılan kitaplıktaki blok çıkarılamaz.
        $this->putJson('/v1/admin/blocks/note', ['added' => false], $h)->assertOk()->assertJsonPath('inLibrary', true);
        $this->putJson('/v1/admin/blocks/shopnote', ['added' => true], $h)->assertStatus(422);
        // Araç Pazarı'nı yöneten de görür; yazar göremez.
        $this->getJson('/v1/admin/blocks', $this->as('author'))->assertForbidden();
    }

    /**
     * Bloklara geçen sayfanın "Sayfa metinleri"nde değiştirilmiş metni (ilk bloğun `texts`'i: alan → sözlük
     * anahtarı) düzenlenmemiş bölgede ve düzenleyicinin ilk bloklarında görünür; bölge yayınlanınca bloğunki geçerli.
     */
    public function test_changed_page_texts_reach_the_default_blocks(): void
    {
        $h = $this->admin();
        PageTexts::replace(['tr' => ['home.note' => 'Panelde değişen not']]);
        PageCache::flush();

        [, $html] = $this->render('/');
        $this->assertStringContainsString('<a href="/blog/">Panelde değişen not</a>', $html);
        $this->assertStringNotContainsString('Varsayılan not', $html);
        // Başka dilde değişiklik yok: kabuktaki hali (test sitesinde iki dil aynı kabuğu kullanır).
        $en = $this->render('/en/')[1];
        $this->assertStringContainsString('Varsayılan not', $en);
        $this->assertStringNotContainsString('Panelde değişen not', $en);
        $this->assertSame('Panelde değişen not', $this->getJson('/v1/admin/layouts', $h)->json('zones')['home.main']['defaults'][0]['i18n']['tr']['text']);

        $this->putJson('/v1/admin/layouts/home.main', ['blocks' => [['id' => 'n1', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'Düzenleyicide yazılan']]]]], $h)->assertOk();
        $this->postJson('/v1/admin/layouts/publish', [], $h)->assertOk();
        [, $html] = $this->render('/');
        $this->assertStringContainsString('Düzenleyicide yazılan', $html);
        $this->assertStringNotContainsString('Panelde değişen not', $html);
    }

    public function test_link_to_a_closed_tools_page_is_empty(): void
    {
        $root = sys_get_temp_dir().'/km-site-'.uniqid();
        mkdir($root);
        $manifest = json_decode((string) file_get_contents(Paths::siteRoot().'/km-manifest.json'), true);
        $manifest['routes'][] = ['name' => 'estimate', 'locale' => 'tr', 'path' => '/teklif/', 'shell' => 'tr/index.html', 'needs' => [], 'module' => 'estimate', 'sitemap' => true];
        file_put_contents($root.'/km-manifest.json', json_encode($manifest));
        $renderer = new BlockRenderer(Router::load($root, '/yok'));
        $modules = static function (array $keys): void {
            config(['kodmob.modules' => $keys]);
            Sites::forgetMemory();
        };

        $modules(['blog', 'estimate']);
        $this->assertSame('/teklif/', $renderer->href('route:estimate', 'tr'));
        $modules(['blog']);
        $this->assertSame('', $renderer->href('route:estimate', 'tr'));
        $this->assertSame('/blog/', $renderer->href('route:blog', 'tr'));
        @unlink($root.'/km-manifest.json');
        @rmdir($root);
    }

    public function test_backup_carries_layouts(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/admin/layouts/home.main', ['blocks' => [['id' => 'k1', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'Yedekte']]]]], $h)->assertOk();
        $this->postJson('/v1/admin/layouts/publish', [], $h)->assertOk();
        $backup = (new Backup)->export();
        $this->assertSame('Yedekte', $backup['layouts']->{'home.main'}['published'][0]['i18n']['tr']['text']);

        DB::table('page_layouts')->delete();
        $result = (new Backup)->import(json_decode((string) json_encode($backup), true));
        $this->assertSame(1, $result['counts']['layouts']);
        [, $html] = $this->render('/');
        $this->assertStringContainsString('Yedekte', $html);
    }
}
