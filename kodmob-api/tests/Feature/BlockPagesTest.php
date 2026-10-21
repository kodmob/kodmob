<?php

namespace Tests\Feature;

use App\Core\Support\Paths;
use App\Modules\Content\Backup;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Blok sayfaları (Modules\Site\Pages\PageType; docs/done-onyuz-raporu.md Ö4): panelden açılan, içeriği Sayfa
 * düzenleyicinin bloklarından oluşan sayfalar. Adres kökte (`/:slug/`, en son eşlenen rota), bloklar sayfaya özel
 * bölgede (`page.main@<kimlik>`). Test sitesinin rotası tests/fixtures/site/km-manifest.json (`page`).
 */
class BlockPagesTest extends TestCase
{
    private function render(string $path, string $preview = ''): array
    {
        $router = Router::load(Paths::siteRoot());

        return (new SitePage($router))->render($router->match($path), $path, $preview);
    }

    /** @param array<string,mixed> $i18n */
    private function page(array $i18n, string $status = 'publish', array $locales = ['tr' => ['status' => 'publish']]): int
    {
        return (int) $this->postJson('/v1/admin/content/pages', ['status' => $status, 'locales' => $locales, 'i18n' => $i18n], $this->admin())
            ->assertCreated()->json('id');
    }

    public function test_page_lives_at_the_root_with_its_own_blocks(): void
    {
        $h = $this->admin();
        $id = $this->page(['tr' => ['title' => 'Yaz Kampanyası', 'description' => 'Bu yaz yüzde yirmi indirim.']]);
        $zone = "page.main@{$id}";
        $this->putJson("/v1/admin/layouts/{$zone}", ['blocks' => [['id' => 'p1', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'Kampanya notu']]]]], $h)
            ->assertOk()->assertJsonPath('zone', $zone);

        // Bölge yayınlanmadan sayfa boş (taslak bloklar ziyaretçiye gitmez); yayından sonra bloklarıyla.
        [$status, $html] = $this->render('/yaz-kampanyasi/');
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('Kampanya notu', $html);
        $this->postJson('/v1/admin/layouts/publish', [], $h)->assertOk()->assertJsonPath('published', [$zone]);

        [$status, $html] = $this->render('/yaz-kampanyasi/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<p class="note is-plain"><a href="/blog/">Kampanya notu</a>', $html);
        $this->assertStringNotContainsString('<!--km:zone', $html);
        $this->assertStringContainsString('<title>Yaz Kampanyası', $html);
        $this->assertStringContainsString('<meta name="description" content="Bu yaz yüzde yirmi indirim.">', $html);
        $this->assertStringContainsString('"@type":"WebPage"', $html);
        // Kırıntı yolu iki basamak: ana sayfa › sayfa.
        $this->assertMatchesRegularExpression('/"@type":"BreadcrumbList","itemListElement":\[\{[^\]]*"position":1[^\]]*"position":2,"name":"Yaz Kampanyası"[^\]]*\]/', $html);

        // Site haritasında; başka sayfanın bölgesi bu sayfaya karışmaz.
        $this->assertStringContainsString('<loc>https://kodmob.test/yaz-kampanyasi/</loc>', $this->render('/sitemap.xml')[1]);
        $this->assertStringNotContainsString('Kampanya notu', $this->render('/')[1]);

        // Kaydı olmayan kök adres ve iki parçalı adres 404.
        $this->assertSame(404, $this->render('/olmayan-sayfa/')[0]);
        $this->assertSame(404, $this->render('/yaz-kampanyasi/alt/')[0]);
        // Sitenin kendi sayfası önce eşlenir.
        $this->assertStringNotContainsString('Kampanya notu', $this->render('/blog/')[1]);
    }

    public function test_draft_page_opens_only_in_the_editor_preview(): void
    {
        $h = $this->admin();
        $id = $this->page(['tr' => ['title' => 'Etkinlik']], 'draft');
        $this->putJson("/v1/admin/layouts/page.main@{$id}", ['blocks' => [['id' => 'e1', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'Taslak etkinlik']]]]], $h)->assertOk();

        $this->assertSame(404, $this->render('/etkinlik/')[0]);
        $token = $this->postJson('/v1/admin/layouts/preview', [], $h)->assertOk()->json('token');
        [$status, $html] = $this->render('/etkinlik/', $token);
        $this->assertSame(200, $status);
        // Önizlemede bölge kaydın adıyla (düzenleyici bu adla tanır); şablonun bölgesi değil.
        $this->assertStringContainsString("<div data-km-zone=\"page.main@{$id}\"><div class=\"km-block\" data-km-block=\"note\" data-km-id=\"e1\">", $html);
        $this->assertStringContainsString('<div data-km-zone="site.bottom"></div>', $html);

        // Taslaktaki dil de önizlemede açılır.
        $other = $this->page(['tr' => ['title' => 'Gizli dil']], 'publish', ['tr' => ['status' => 'draft']]);
        $this->assertSame(404, $this->render('/gizli-dil/')[0]);
        $this->assertSame(200, $this->render('/gizli-dil/', $token)[0]);
        $this->assertGreaterThan($id, $other);
    }

    public function test_address_cannot_take_a_site_page_folder_or_language(): void
    {
        $h = $this->admin();
        $post = fn (array $tr) => $this->postJson('/v1/admin/content/pages', ['status' => 'draft', 'locales' => ['tr' => ['status' => 'publish'], 'en' => ['status' => 'publish']], 'i18n' => ['tr' => $tr, 'en' => ['title' => 'Page', 'slug' => 'blog']]], $h);

        // Sitenin sayfası (her iki dilde), panel klasörü (başlıktan türeyen adres de), dil öneki.
        $post(['title' => 'Bizim blog', 'slug' => 'blog'])->assertStatus(422)->assertJsonValidationErrors(['i18n.tr.slug', 'i18n.en.slug'], 'details.fields');
        $post(['title' => 'Panel'])->assertStatus(422)->assertJsonValidationErrors(['i18n.tr.slug'], 'details.fields');
        $post(['title' => 'İngilizce', 'slug' => 'en'])->assertStatus(422)->assertJsonValidationErrors(['i18n.tr.slug'], 'details.fields');

        $this->postJson('/v1/admin/content/pages', ['status' => 'draft', 'locales' => ['tr' => ['status' => 'publish']], 'i18n' => ['tr' => ['title' => 'Hakkımızda']]], $h)
            ->assertCreated()->assertJsonPath('i18n.tr.slug', 'hakkimizda');
    }

    public function test_editor_lists_pages_and_publish_takes_the_page_live(): void
    {
        $h = $this->admin();
        $id = $this->page(['tr' => ['title' => 'Kampanya']], 'draft');
        $index = $this->getJson('/v1/admin/layouts', $h)->assertOk();
        $page = collect($index->json('pages'))->firstWhere('name', "page@{$id}");
        $this->assertSame(['label' => 'Kampanya', 'dynamic' => null, 'record' => ['id' => $id, 'status' => 'draft', 'locales' => ['tr' => 'publish']], 'paths' => ['tr' => '/kampanya/'], 'zones' => ["page.main@{$id}", 'site.bottom']],
            array_diff_key($page, ['name' => 1]));
        // Şablonun kendisi (`records`) de listede: düzenleyici onu seçicide göstermez, yeni sayfa için kullanır.
        $this->assertTrue(collect($index->json('pages'))->firstWhere('name', 'page')['records']);
        $this->assertSame(['label' => 'Sayfa içeriği', 'defaults' => [], 'published' => null, 'draft' => null], array_intersect_key($index->json('zones')["page.main@{$id}"], array_flip(['label', 'defaults', 'published', 'draft'])));

        // Olmayan sayfanın ya da kayıt bölgesi olmayan bölgenin taslağı yazılmaz.
        $this->putJson('/v1/admin/layouts/page.main@999999', ['blocks' => []], $h)->assertNotFound();
        $this->putJson("/v1/admin/layouts/home.main@{$id}", ['blocks' => []], $h)->assertNotFound();

        // Yayınla: sayfa da yayına alınır (yetkisi olmayan rolde sayfa taslakta kalır).
        $this->postJson('/v1/admin/layouts/publish', ['pages' => [$id]], $this->as('author'))->assertForbidden();
        $this->postJson('/v1/admin/layouts/publish', ['pages' => [$id, 999999]], $h)->assertOk()->assertJsonPath('pages', [$id]);
        $this->assertSame('publish', DB::table('contents')->where('id', $id)->value('status'));
        $this->assertSame(200, $this->render('/kampanya/')[0]);
        $event = $this->getJson('/v1/history', $h)->assertOk()->json('items.0');
        $this->assertSame(['layouts.published', 'yayına alınan sayfa: Kampanya'], [$event['type'], $event['text']]);
    }

    public function test_link_field_points_to_a_block_page(): void
    {
        $h = $this->admin();
        $id = $this->page(['tr' => ['title' => 'Yaz Kampanyası'], 'en' => ['title' => 'Summer sale']], 'publish', ['tr' => ['status' => 'publish'], 'en' => ['status' => 'draft']]);
        $block = ['id' => 'l1', 'type' => 'note', 'data' => ['url' => "page:{$id}"], 'i18n' => ['tr' => ['text' => 'Kampanyaya git'], 'en' => ['text' => 'Go']]];

        $this->assertStringContainsString('<a href="/yaz-kampanyasi/"', $this->postJson('/v1/admin/layouts/render', ['locale' => 'tr', 'block' => $block], $h)->assertOk()->json('html'));
        // Sayfa bu dilde yayında değil: bağlantı boş.
        $this->assertStringContainsString('<a href=""', $this->postJson('/v1/admin/layouts/render', ['locale' => 'en', 'block' => $block], $h)->json('html'));
        $this->assertSame("page:{$id}", $this->putJson('/v1/admin/layouts/home.main', ['blocks' => [$block]], $h)->assertOk()->json('draft.0.data.url'));
    }

    public function test_backup_moves_page_blocks_to_the_new_page(): void
    {
        $h = $this->admin();
        $id = $this->page(['tr' => ['title' => 'Yedekli sayfa']]);
        $this->putJson("/v1/admin/layouts/page.main@{$id}", ['blocks' => [['id' => 'b1', 'type' => 'note', 'i18n' => ['tr' => ['text' => 'Yedekteki blok']]]]], $h)->assertOk();
        $this->postJson('/v1/admin/layouts/publish', [], $h)->assertOk();
        $backup = json_decode((string) json_encode((new Backup)->export()), true);
        $this->assertArrayHasKey("page.main@{$id}", $backup['layouts']);
        // Sayfası yedekte olmayan kayıt bölgesi alınmaz.
        $backup['layouts']['page.main@999999'] = ['published' => [], 'draft' => null];

        (new Backup)->import($backup);
        $new = (int) DB::table('contents')->where('type', 'km_page')->whereNull('deleted_at')->value('id');
        $this->assertNotSame($id, $new);
        $this->assertSame(["page.main@{$new}"], DB::table('page_layouts')->where('zone', 'like', 'page.main@%')->pluck('zone')->all());
        $this->assertStringContainsString('Yedekteki blok', $this->render('/yedekli-sayfa/')[1]);
    }
}
