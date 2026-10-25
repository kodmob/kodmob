<?php

namespace Tests\Feature;

use App\Core\Content\Types;
use App\Core\Security\Capability;
use App\Core\Security\Roles;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\Content\Backup;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * İçerik çevirileri (done-coklu-dil-raporu.md Faz 4): kayıtta dil ekleme/kaldırma ve dil başına yayın,
 * eskimiş çeviri, blog çeviri grupları, Jarvis ile çeviri, adres geçmişi (301) ve silinen
 * içerik (410).
 */
class TranslationsTest extends TestCase
{
    private function project(array $locales, array $i18n, string $status = 'publish'): array
    {
        return $this->postJson('/v1/admin/content/projects', ['status' => $status, 'locales' => $locales, 'i18n' => $i18n], $this->admin())->assertCreated()->json();
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function render(string $path): array
    {
        $router = Router::load(Paths::siteRoot());

        return (new SitePage($router))->render($router->match($path), $path);
    }

    public function test_languages_are_added_published_and_removed_per_record(): void
    {
        $h = $this->admin();
        $p = $this->project(['tr' => ['status' => 'publish'], 'en' => ['status' => 'draft']], ['tr' => ['title' => 'Tarla'], 'en' => ['title' => 'Field']]);
        $this->assertSame(['tr' => ['status' => 'publish', 'stale' => false], 'en' => ['status' => 'draft', 'stale' => false]], $p['locales']);

        // İngilizce taslak: sitede yok.
        $this->getJson('/v1/page-data?route=project&locale=en&slug=field')->assertJsonPath('notFound', true);
        $this->getJson('/v1/page-data?route=project&locale=tr&slug=tarla')->assertJsonPath('entry.slugs', ['tr' => 'tarla']);

        // Yayına alınınca görünür.
        $p['locales']['en']['status'] = 'publish';
        $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->assertOk();
        $this->getJson('/v1/page-data?route=project&locale=en&slug=field')->assertJsonPath('entry.title', 'Field');

        // Dil kaldırılır: sitede yok, metni saklanır; yeniden eklenince geri gelir.
        unset($p['locales']['en']);
        $saved = $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->assertOk();
        $saved->assertJsonPath('i18n.en.title', 'Field');
        $this->assertArrayNotHasKey('en', $saved->json('locales'));
        $this->getJson('/v1/page-data?route=project&locale=en&slug=field')->assertJsonPath('notFound', true);
        $this->assertSame(['tr'], array_column($this->getJson('/v1/admin/content/projects', $h)->json('items.0.languages'), 'code'));

        // Eklenen dilde başlık gerekli; en az bir dil.
        $p['locales']['de'] = ['status' => 'draft'];
        $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['i18n.de.title']]]);
        $p['locales'] = [];
        $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['locales']]]);
    }

    public function test_translation_becomes_stale_when_source_changes(): void
    {
        $h = $this->admin();
        $p = $this->project(['tr' => ['status' => 'publish'], 'en' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla', 'summary' => 'Bir'], 'en' => ['title' => 'Field', 'summary' => 'One']]);

        // Kaynak (Türkçe) değişti → İngilizce güncellenmeli.
        $p['i18n']['tr']['summary'] = 'İki';
        $p = $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->assertOk()->json();
        $this->assertTrue($p['locales']['en']['stale']);
        $this->assertFalse($p['locales']['tr']['stale']);
        $this->assertTrue($this->getJson('/v1/admin/content/projects', $h)->json('items.0.languages.1.stale'));

        // Başka bir alan kaydı eskimeyi kaldırmaz; çeviri değişince ya da "Güncel say" ile kalkar.
        $p['categories'] = ['web'];
        $this->assertTrue($this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->json('locales.en.stale'));
        $p['locales']['en']['current'] = true;
        $p = $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->json();
        $this->assertFalse($p['locales']['en']['stale']);
        $p['i18n']['tr']['summary'] = 'Üç';
        $p = $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->json();
        $p['i18n']['en']['summary'] = 'Three';
        $this->assertFalse($this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->json('locales.en.stale'));
    }

    public function test_author_cannot_publish_a_language(): void
    {
        $p = $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla']]);
        Roles::setCaps('author', [Capability::ACCESS_PANEL, Capability::MANAGE_POSTS, 'km_manage_projects']);
        $p['locales']['en'] = ['status' => 'publish'];
        $p['i18n']['en']['title'] = 'Field';
        $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $this->as('author'))->assertOk()
            ->assertJsonPath('locales.en.status', 'draft')->assertJsonPath('locales.tr.status', 'publish');
    }

    public function test_legacy_records_without_language_state_keep_working(): void
    {
        $data = ['i18n' => ['tr' => ['title' => 'Eski', 'slug' => 'eski'], 'en' => ['title' => 'Old', 'slug' => 'old'], 'de' => ['title' => '']]];
        DB::table('contents')->insert(['type' => 'km_project', 'status' => 'publish', 'position' => 0, 'title' => 'Eski', 'data' => Text::json($data), 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/v1/page-data?route=project&locale=en&slug=old')->assertJsonPath('entry.title', 'Old');
        $this->assertSame(['tr', 'en'], array_keys(Types::get('projects')->withLanguages($data)['locales']));
    }

    public function test_post_translation_group(): void
    {
        $h = $this->admin();
        $tr = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Merhaba Dünya', 'locale' => 'tr', 'html' => '<p>Selam</p>'], $h)->assertCreated()->json();
        $this->assertArrayNotHasKey('translation', $tr);

        // Kopya çeviri: taslak, aynı grup; aynı dilde ikincisi olmaz.
        $created = $this->postJson("/v1/admin/content/posts/{$tr['id']}/translations", ['locale' => 'en', 'mode' => 'copy'], $h)->assertCreated();
        $en = $created->json('item');
        $this->assertSame('draft', $en['status']);
        $this->assertSame('<p>Selam</p>', $en['html']);
        $this->postJson("/v1/admin/content/posts/{$tr['id']}/translations", ['locale' => 'en'], $h)->assertStatus(409)->assertJsonPath('code', 'km_translation_exists');
        $translations = collect($this->getJson("/v1/admin/content/posts/{$tr['id']}", $h)->json('translations'))->keyBy('code');
        $this->assertSame($en['id'], $translations['en']['id']);
        $this->assertTrue($translations['tr']['source']);

        // Çeviri yazılır ve yayınlanır: site karşılıkları bağlı.
        $en['title'] = 'Hello World';
        $en['slug'] = 'hello-world';
        $en['html'] = '<p>Hi</p>';
        $en['status'] = 'publish';
        $en = $this->putJson("/v1/admin/content/posts/{$en['id']}", $en, $h)->assertOk()->json();
        $this->getJson('/v1/page-data?route=post&locale=tr&slug=merhaba-dunya')->assertJsonPath('entry.slugs', ['tr' => 'merhaba-dunya', 'en' => 'hello-world']);

        // Kaynak değişince çeviri güncellenmeli.
        $tr['html'] = '<p>Selam, yeni</p>';
        $this->putJson("/v1/admin/content/posts/{$tr['id']}", $tr, $h)->assertOk();
        $this->assertTrue(collect($this->getJson("/v1/admin/content/posts/{$en['id']}", $h)->json('translations'))->firstWhere('code', 'en')['stale']);

        // Aynı grupta dil çakışması kaydedilmez.
        $en['locale'] = 'tr';
        $this->putJson("/v1/admin/content/posts/{$en['id']}", $en, $h)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['locale']]]);

        // Ayır ve yeniden bağla.
        $this->postJson("/v1/admin/content/posts/{$en['id']}/unlink", [], $h)->assertOk();
        $this->getJson('/v1/page-data?route=post&locale=tr&slug=merhaba-dunya')->assertJsonPath('entry.slugs', ['tr' => 'merhaba-dunya']);
        $this->postJson("/v1/admin/content/posts/{$tr['id']}/link", ['post_id' => $en['id']], $h)->assertOk();
        $this->getJson('/v1/page-data?route=post&locale=en&slug=hello-world')->assertJsonPath('entry.slugs', ['tr' => 'merhaba-dunya', 'en' => 'hello-world']);
        $this->assertDatabaseHas('events', ['event_type' => 'post.translation_linked']);
    }

    public function test_jarvis_translation_uses_engine_and_keeps_shape(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/translate/projects', ['from' => 'tr', 'to' => 'en', 'fields' => ['title' => 'Tarla']], $h)
            ->assertStatus(422)->assertJsonPath('code', 'km_ai_not_configured');
        $this->getJson('/v1/me', $h)->assertJsonPath('translator', false);

        app()->instance('i18n.translator', static function (array $fields, string $from, string $to) {
            array_walk_recursive($fields, static function (&$v) use ($to) {
                $v = is_string($v) ? strtoupper($to).':'.$v : $v;
            });

            return $fields;
        });
        $this->getJson('/v1/me', $h)->assertJsonPath('translator', true);
        $this->postJson('/v1/admin/translate/projects', ['from' => 'tr', 'to' => 'en', 'fields' => ['title' => 'Tarla', 'features' => [['title' => 'Hızlı', 'text' => 'Çok']]]], $h)->assertOk()
            ->assertJsonPath('fields.title', 'EN:Tarla')->assertJsonPath('fields.features.0.text', 'EN:Çok');
        $this->postJson('/v1/admin/translate/projects', ['from' => 'tr', 'to' => 'tr', 'fields' => ['title' => 'x']], $h)->assertStatus(422);

        // Biçimi bozan çeviri kabul edilmez.
        app()->instance('i18n.translator', static fn () => ['başka' => 'şey']);
        $this->postJson('/v1/admin/translate/projects', ['from' => 'tr', 'to' => 'en', 'fields' => ['title' => 'Tarla']], $h)->assertStatus(502)->assertJsonPath('code', 'km_translate_failed');

        // Blog: Jarvis çevirisiyle yeni yazı.
        app()->instance('i18n.translator', static fn (array $f, string $from, string $to) => array_map(static fn ($v) => 'EN '.$v, $f));
        $tr = $this->postJson('/v1/admin/content/posts', ['title' => 'Merhaba', 'locale' => 'tr', 'html' => '<p>Selam</p>'], $h)->json();
        $en = $this->postJson("/v1/admin/content/posts/{$tr['id']}/translations", ['locale' => 'en', 'mode' => 'ai'], $h)->assertCreated()->json('item');
        $this->assertSame('EN Merhaba', $en['title']);
        $this->assertSame('en-merhaba', $en['slug']);
    }

    public function test_changed_slug_redirects_and_deleted_content_is_gone(): void
    {
        $h = $this->admin();
        $p = $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla', 'slug' => 'tarla']]);
        $p['i18n']['tr']['slug'] = 'tarla-takip';
        $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->assertOk();

        [$status, , $headers] = $this->render('/projeler/tarla/');
        $this->assertSame(301, $status);
        $this->assertSame('/projeler/tarla-takip/', $headers['Location']);
        $this->assertSame(200, $this->render('/projeler/tarla-takip/')[0]);

        // Eski adres yeniden kullanılırsa artık yeni kayda aittir.
        $other = $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla', 'slug' => 'tarla']]);
        $this->assertSame(200, $this->render('/projeler/tarla/')[0]);

        // Silinen proje: 410; çöpten geri alınınca açılır.
        $this->deleteJson('/v1/admin/content/projects/'.$other['id'], [], $h)->assertNoContent();
        $this->assertSame(410, $this->render('/projeler/tarla/')[0]);
        $this->postJson('/v1/records/restore', ['type' => 'project', 'id' => $other['id']], $h)->assertOk();
        $this->assertSame(200, $this->render('/projeler/tarla/')[0]);

        // Blog: dili değişen yazı yeni dildeki adresine gider.
        $post = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Yanlış Dil', 'slug' => 'wrong-language', 'locale' => 'tr', 'date' => now()->subDay()->format('Y-m-d\TH:i')], $h)->json();
        $post['locale'] = 'en';
        $this->putJson("/v1/admin/content/posts/{$post['id']}", $post, $h)->assertOk();
        [$status, , $headers] = $this->render('/blog/wrong-language/');
        $this->assertSame([301, '/en/blog/wrong-language/'], [$status, $headers['Location'] ?? null]);
        $this->assertSame(404, $this->render('/blog/hic-olmadi/')[0]);
    }

    public function test_backup_round_trip_keeps_languages_and_groups(): void
    {
        $h = $this->admin();
        $this->project(['tr' => ['status' => 'publish'], 'en' => ['status' => 'draft']], ['tr' => ['title' => 'Tarla'], 'en' => ['title' => 'Field']]);
        $tr = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Merhaba', 'locale' => 'tr'], $h)->json();
        $this->postJson("/v1/admin/content/posts/{$tr['id']}/translations", ['locale' => 'en', 'mode' => 'copy'], $h)->assertCreated();

        $backup = (new Backup)->export();
        (new Backup)->import($backup);

        $project = Types::get('projects')->records(false)[0];
        $this->assertSame('draft', Types::get('projects')->languages($project->data)['en']['status']);
        $groups = DB::table('posts')->whereNull('deleted_at')->pluck('translation_group')->unique();
        $this->assertCount(1, $groups, 'çeviri grubu korunur');
    }

    public function test_backup_keeps_old_addresses(): void
    {
        $h = $this->admin();
        $p = $this->project(['tr' => ['status' => 'publish']], ['tr' => ['title' => 'Tarla', 'slug' => 'tarla']]);
        $p['i18n']['tr']['slug'] = 'tarla-takip';
        $this->putJson('/v1/admin/content/projects/'.$p['id'], $p, $h)->assertOk();
        $post = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Eski', 'slug' => 'eski', 'locale' => 'tr', 'date' => now()->subDay()->format('Y-m-d\TH:i')], $h)->json();
        $post['slug'] = 'yeni';
        $this->putJson("/v1/admin/content/posts/{$post['id']}", $post, $h)->assertOk();

        // Geri yüklemede kayıtlar yeni kimlik alır: eski adresler yeni kayda yönlenir (çöpteki eskiye değil).
        $backup = (new Backup)->export();
        $this->assertCount(2, $backup['slug_history']);
        $result = (new Backup)->import($backup);
        $this->assertSame(2, $result['counts']['slugs']);

        [$status, , $headers] = $this->render('/projeler/tarla/');
        $this->assertSame([301, '/projeler/tarla-takip/'], [$status, $headers['Location'] ?? null]);
        [$status, , $headers] = $this->render('/blog/eski/');
        $this->assertSame([301, '/blog/yeni/'], [$status, $headers['Location'] ?? null]);

        // Yeni sunucu (geçmiş boş): yedekten gelir.
        DB::table('slug_history')->delete();
        (new Backup)->import($backup);
        $this->assertSame(301, $this->render('/projeler/tarla/')[0]);
    }
}
