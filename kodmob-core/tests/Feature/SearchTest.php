<?php

namespace Tests\Feature;

use App\Core\Content\Types;
use App\Core\Search\SearchQuery;
use App\Core\Search\SearchRegistry;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Panel araması (üst çubuk): kayıt grupları, yetki, adresler. */
class SearchTest extends TestCase
{
    public function test_records_are_found_by_permission(): void
    {
        $admin = $this->admin();
        $this->postJson('/v1/admin/content/projects', ['status' => 'draft', 'i18n' => ['tr' => ['title' => 'Zeytin Kooperatifi', 'slug' => 'zeytin-kooperatifi']]], $admin)->assertCreated();
        $post = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Zeytin hasadı rehberi', 'locale' => 'tr', 'html' => '<p>Metin</p>'], $admin)->assertCreated()->json('id');
        DB::table('briefs')->insert(['locale' => 'tr', 'name' => 'Ayşe Zeytinci', 'email' => 'ayse@example.com', 'services' => '[]', 'message' => 'Merhaba', 'status' => 'new', 'ip_hash' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/v1/seo/redirects', ['source' => '/eski-zeytin/', 'target' => '/projeler/zeytin-kooperatifi/', 'code' => 301], $admin)->assertCreated();
        User::query()->create(['username' => 'zeynep', 'display_name' => 'Zeynep Zeytin', 'email' => 'zeynep@kodmob.test', 'password' => 'gizli-sifre-123', 'role' => 'author', 'status' => User::PASSIVE]);

        $groups = collect($this->getJson('/v1/search?q=zeytin', $admin)->assertOk()->json('groups'))->keyBy('key');
        $this->assertSame(['projects', 'posts'], $groups->keys()->take(2)->all(), 'içerik türleri önce');
        $this->assertEqualsCanonicalizing(['projects', 'posts', 'users', 'briefs', 'redirects'], $groups->keys()->all());
        $this->assertSame(['Zeytin Kooperatifi', 'Çalışma', 'Taslak'], [$groups['projects']['items'][0]['title'], $groups['projects']['items'][0]['subtitle'], $groups['projects']['items'][0]['badge']]);
        $this->assertSame("/panel/blog/{$post}", $groups['posts']['items'][0]['url']);
        $this->assertSame(['Yeni', 'ayse@example.com'], [$groups['briefs']['items'][0]['badge'], $groups['briefs']['items'][0]['subtitle']]);
        $this->assertSame(['Pasif', 'zeynep · Yazar'], [$groups['users']['items'][0]['badge'], $groups['users']['items'][0]['subtitle']]);
        $this->assertStringStartsWith('/panel/seo/yonlendirmeler?q=', $groups['redirects']['items'][0]['url']);

        // Yazar yalnızca blogu görür; Editör kullanıcıları görmez.
        $author = collect($this->getJson('/v1/search?q=zeytin', $this->as('author'))->json('groups'))->pluck('key')->all();
        $this->assertSame(['posts'], $author);
        $editor = collect($this->getJson('/v1/search?q=zeytin', $this->as('editor'))->json('groups'))->pluck('key')->all();
        $this->assertNotContains('users', $editor);
        $this->assertContains('briefs', $editor);

        // Kısa sorgu boş; joker karakterler düz metin sayılır.
        $this->getJson('/v1/search?q=z', $admin)->assertExactJson(['groups' => []]);
        $this->getJson('/v1/search?q=%25%25', $admin)->assertExactJson(['groups' => []]);
        $this->getJson('/v1/search?q=zeytin')->assertStatus(401);
    }

    public function test_module_types_are_searchable_by_default(): void
    {
        // Kayıttaki her içerik türü (kodmob:module ile eklenenler dahil) aranır.
        $this->assertSame(array_keys(Types::editable()), array_slice(array_keys(SearchRegistry::providers()), 0, count(Types::editable())));
        $this->assertSame('', SearchQuery::statusBadge('publish'));
    }
}
