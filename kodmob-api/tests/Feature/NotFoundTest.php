<?php

namespace Tests\Feature;

use App\Core\Support\Paths;
use App\Modules\Seo\Services\SeoConfig;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kapsamlı 404 (done-coklu-dil-raporu.md §4.7, Faz 6): başka dildeki kayıt adresi 301 ya da
 * "şu dillerde mevcut", silinen içerik 410 metni, "Bunu mu demek istediniz?" önerileri.
 * Router adımları (yazım farkı, yanlış dildeki bölüm adı) ortak vektörlerde: SiteUrlsTest.
 */
class NotFoundTest extends TestCase
{
    /** @return array{0:int,1:string,2:array<string,string>} */
    private function render(string $path): array
    {
        $router = Router::load(Paths::siteRoot());

        return (new SitePage($router))->render($router->match($path), $path);
    }

    /** Sayfaya gömülen içerik verisi (#km-data). */
    private function pageData(string $html): array
    {
        preg_match('#<script type="application/json" id="km-data">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? 'null', true) ?? [];
    }

    private function project(array $locales, array $i18n): array
    {
        return $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'locales' => $locales, 'i18n' => $i18n], $this->admin())->assertCreated()->json();
    }

    public function test_record_address_in_another_language(): void
    {
        $this->project(['tr' => ['status' => 'publish'], 'en' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla', 'slug' => 'tarla'], 'en' => ['title' => 'Field', 'slug' => 'field']]);
        $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Sera', 'slug' => 'sera']]);

        // Kayıt bu dilde de var: oradaki adresine.
        [$status, , $headers] = $this->render('/en/projects/tarla/');
        $this->assertSame([301, '/en/projects/field/'], [$status, $headers['Location'] ?? null]);
        [$status, , $headers] = $this->render('/projeler/field/');
        $this->assertSame([301, '/projeler/tarla/'], [$status, $headers['Location'] ?? null]);

        // Yalnızca Türkçe: 404 + "şu dillerde mevcut" (soft 404 değil: durum 404, yönlendirme yok).
        [$status, $html] = $this->render('/en/projects/sera/');
        $this->assertSame(404, $status);
        $this->assertSame([['code' => 'tr', 'label' => 'Türkçe', 'url' => '/projeler/sera/']], $this->pageData($html)['available'] ?? null);

        // Hiçbir dilde yok: düz 404.
        [$status, $html] = $this->render('/en/projects/hic-yok/');
        $this->assertSame(404, $status);
        $this->assertArrayNotHasKey('available', $this->pageData($html));
    }

    public function test_post_address_in_another_language(): void
    {
        $h = $this->admin();
        $date = now()->subDay()->format('Y-m-d\TH:i');
        $tr = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Merhaba', 'slug' => 'merhaba', 'locale' => 'tr', 'date' => $date], $h)->json();

        [$status, $html] = $this->render('/en/blog/merhaba/');
        $this->assertSame(404, $status);
        $this->assertSame('/blog/merhaba/', $this->pageData($html)['available'][0]['url'] ?? null);

        // İngilizcesi yayında: oraya.
        $en = $this->postJson("/v1/admin/content/posts/{$tr['id']}/translations", ['locale' => 'en', 'mode' => 'copy'], $h)->json('item');
        $en['title'] = 'Hello';
        $en['slug'] = 'hello';
        $en['status'] = 'publish';
        $en['date'] = $date;
        $this->putJson("/v1/admin/content/posts/{$en['id']}", $en, $h)->assertOk();
        [$status, , $headers] = $this->render('/en/blog/merhaba/');
        $this->assertSame([301, '/en/blog/hello/'], [$status, $headers['Location'] ?? null]);
    }

    public function test_deleted_content_page_says_gone(): void
    {
        $p = $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Eski', 'slug' => 'eski']]);
        $this->deleteJson('/v1/admin/content/projects/'.$p['id'], [], $this->admin())->assertNoContent();
        [$status, $html] = $this->render('/projeler/eski/');
        $this->assertSame(410, $status);
        $this->assertTrue($this->pageData($html)['gone'] ?? false);
    }

    public function test_broken_internal_links_are_flagged_and_notified(): void
    {
        $h = $this->admin();
        $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla Takip', 'slug' => 'tarla-takip']]);
        @mkdir(SeoConfig::dir(), 0755, true);
        $line = static fn ($p, $r) => json_encode(['p' => $p, 'r' => $r, 'b' => false, 't' => '2026-10-16T10:00:00+03:00'])."\n";
        file_put_contents(SeoConfig::dir().'/404.log', $line('/projeler/tarla-takp/', 'https://kodmob.test/blog/bir-yazi/').$line('/eski/', 'https://google.com/'));

        $items = collect($this->getJson('/v1/seo/404', $h)->assertOk()->json('items'))->keyBy('path');
        $this->assertTrue($items['/projeler/tarla-takp/']['internal']);
        $this->assertSame('/projeler/tarla-takip/', $items['/projeler/tarla-takp/']['suggestion']);
        $this->assertFalse($items['/eski/']['internal']);
        $this->getJson('/v1/seo/404?internal=1', $h)->assertJsonPath('total', 1);

        // Kırık iç bağlantı bildirimi SEO yetkisi olanlara bir kez gider.
        $this->assertSame(1, DB::table('notifications')->where('type', 'seo.broken_link')->where('user_id', $this->userId('yonetici'))->count());
        file_put_contents(SeoConfig::dir().'/404.log', $line('/projeler/tarla-takp/', 'https://kodmob.test/blog/bir-yazi/'));
        $this->getJson('/v1/seo/404', $h)->assertOk();
        $this->assertSame(1, DB::table('notifications')->where('type', 'seo.broken_link')->count());
    }

    public function test_link_checker_finds_broken_moved_and_deleted_links(): void
    {
        $h = $this->admin();
        $old = $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla', 'slug' => 'tarla']]);
        $old['i18n']['tr']['slug'] = 'tarla-takip';
        $this->putJson('/v1/admin/content/projects/'.$old['id'], $old, $h)->assertOk();
        $gone = $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Sera', 'slug' => 'sera']]);
        $this->deleteJson('/v1/admin/content/projects/'.$gone['id'], [], $h)->assertNoContent();
        $html = '<p><a href="/projeler/tarla/">taşındı</a> <a href="/projeler/sera/">silindi</a> <a href="/olmayan-sayfa/">kırık</a>'
            .' <a href="https://kodmob.test/projeler/tarla-takip/">sağlam</a> <a href="https://example.com/x">dış</a> <a href="/projeler/">sağlam</a></p>';
        $post = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Bağlantılar', 'locale' => 'tr', 'html' => $html, 'date' => now()->subDay()->format('Y-m-d\TH:i')], $h)->json();

        $items = collect($this->postJson('/v1/seo/links/check', [], $h)->assertOk()->json('items'))->keyBy('url');
        $this->assertSame(['/olmayan-sayfa/', '/projeler/sera/', '/projeler/tarla/'], $items->keys()->all(), 'sıra: kırık, silinmiş, yönlenen');
        $this->assertSame(['broken', 'gone', 'redirect'], $items->pluck('problem')->all());
        $this->assertSame('/projeler/tarla-takip/', $items['/projeler/tarla/']['target']);
        $this->assertSame(['posts', $post['id'], 'Bağlantılar', 'tr'], [$items['/olmayan-sayfa/']['type'], $items['/olmayan-sayfa/']['id'], $items['/olmayan-sayfa/']['title'], $items['/olmayan-sayfa/']['locale']]);
        $this->getJson('/v1/seo/links', $h)->assertOk()->assertJsonCount(3, 'items');

        // Yönlenen bağlantıyı güncelle: içerik yeni adrese bağlanır, kırıklar kalır.
        $fixed = $this->postJson('/v1/seo/links/fix', [], $h)->assertOk();
        $fixed->assertJsonPath('fixed', 1)->assertJsonCount(2, 'items');
        $this->assertStringContainsString('href="/projeler/tarla-takip/">taşındı', (string) DB::table('posts')->where('id', $post['id'])->value('html'));
        $this->assertDatabaseHas('events', ['event_type' => 'seo.links_fixed']);
    }

    public function test_link_fix_does_not_make_translations_stale(): void
    {
        $h = $this->admin();
        $date = now()->subDay()->format('Y-m-d\TH:i');
        $tr = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Merhaba', 'locale' => 'tr', 'html' => '<p><a href="/tr/projeler/#liste">bize yazın</a></p>', 'date' => $date], $h)->json();
        $en = $this->postJson("/v1/admin/content/posts/{$tr['id']}/translations", ['locale' => 'en', 'mode' => 'copy'], $h)->json('item');
        $en['status'] = 'publish';
        $en['date'] = $date;
        $this->putJson("/v1/admin/content/posts/{$en['id']}", $en, $h)->assertOk();

        $this->postJson('/v1/seo/links/fix', [], $h)->assertOk()->assertJsonPath('fixed', 2);
        $this->assertStringContainsString('href="/projeler/#liste"', (string) DB::table('posts')->where('id', $tr['id'])->value('html'));
        $this->assertFalse(collect($this->getJson("/v1/admin/content/posts/{$en['id']}", $h)->json('translations'))->firstWhere('code', 'en')['stale']);
    }

    public function test_suggestions_for_mistyped_addresses(): void
    {
        $this->project(['tr' => ['status' => 'publish'], 'en' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla Takip', 'slug' => 'tarla-takip'], 'en' => ['title' => 'Field Tracker', 'slug' => 'field-tracker']]);

        $items = $this->getJson('/v1/suggest?path=/projeler/tarla-takp/')->assertOk()->json('items');
        $this->assertSame(['url' => '/projeler/tarla-takip/', 'title' => 'Tarla Takip'], $items[0]);
        // Dil önekten: İngilizce adrese İngilizce öneriler.
        $this->assertSame('/en/projects/field-tracker/', $this->getJson('/v1/suggest?path=/en/projects/field-traker/')->json('items.0.url'));
        // Sabit sayfa (bölüm adı yanlış yazılmış).
        $this->assertSame('/projeler/', $this->getJson('/v1/suggest?path=/projelr/')->json('items.0.url'));
        // Benzemeyen ya da boş adres: öneri yok.
        $this->assertSame([], $this->getJson('/v1/suggest?path=/zzzzzzzzzzqqq/')->json('items'));
        $this->assertSame([], $this->getJson('/v1/suggest?path=/')->json('items'));
    }
}
