<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Genel işlem kaydı ve İşlem Geçmişi (İşlemlerim / Tüm İşlemler). */
class HistoryTest extends TestCase
{
    public function test_every_panel_write_is_logged_with_readable_title(): void
    {
        $admin = $this->admin();
        $id = $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Tarla Takip']]], $admin)->assertCreated()->json('id');
        $this->putJson('/v1/admin/texts', ['values' => ['home.hero.title' => 'Merhaba']], $admin)->assertOk();
        $this->postJson('/v1/notifications/read', [], $admin)->assertOk();
        $this->postJson('/v1/admin/content/projects', ['i18n' => []], $admin)->assertStatus(422);

        $mine = $this->getJson('/v1/history', $admin)->assertOk();
        $mine->assertJsonPath('scope.key', 'mine')->assertJsonPath('total', 2);
        $mine->assertJsonPath('items.0.type', 'texts.updated')->assertJsonPath('items.0.title', 'Sayfa metinleri değişti')->assertJsonPath('items.0.group', 'site');
        $mine->assertJsonPath('items.1.type', 'project.created')
            ->assertJsonPath('items.1.title', 'Proje eklendi')
            ->assertJsonPath('items.1.subject.label', 'Tarla Takip')
            ->assertJsonPath('items.1.subject.url', "/panel/projeler/$id")
            ->assertJsonPath('items.1.user_name', 'Yönetici');

        $detail = $this->getJson('/v1/history/'.$mine->json('items.1.id'), $admin)->assertOk();
        $detail->assertJsonPath('subject_events.0.current', true);
    }

    public function test_scopes_mine_department_and_all(): void
    {
        $admin = $this->admin();
        $editor = $this->as('editor');
        $this->postJson('/v1/admin/content/projects', ['i18n' => ['tr' => ['title' => 'Yöneticinin Projesi']]], $admin)->assertCreated();
        $this->postJson('/v1/users', ['username' => 'satis1', 'email' => 's@kodmob.test', 'display_name' => 'Satış Bir', 'role' => 'sales', 'password' => 'Satis-sifre-2026'], $admin)->assertCreated();
        $this->postJson('/v1/admin/content/posts', ['title' => 'Editörün Yazısı', 'locale' => 'tr', 'html' => '<p>x</p>'], $editor)->assertCreated();

        // İşlemlerim: yalnızca kendi.
        $this->assertSame(['post.created'], array_column($this->getJson('/v1/history', $editor)->json('items'), 'type'));
        // Tüm İşlemler (bölüm): içerik grubu görünür, kullanıcı işlemleri görünmez.
        $all = array_column($this->getJson('/v1/history?scope=all', $editor)->json('items'), 'type');
        $this->assertContains('project.created', $all);
        $this->assertNotContains('users.created', $all);
        // Yönetici her şeyi görür; grup sekme sayıları gelir.
        $admin = $this->getJson('/v1/history?scope=all', $admin)->assertOk();
        $this->assertContains('users.created', array_column($admin->json('items'), 'type'));
        $this->assertSame(1, $admin->json('facets.group.users'));

        $stats = $this->getJson('/v1/history/stats?scope=all', $this->admin())->assertOk();
        $this->assertGreaterThanOrEqual(3, $stats->json('total.count'));
        $this->getJson('/v1/history/options', $editor)->assertOk()->assertJsonStructure(['groups', 'users']);
    }

    public function test_detail_of_hidden_scope_is_forbidden(): void
    {
        $admin = $this->admin();
        $this->postJson('/v1/users', ['username' => 'yazar1', 'email' => 'y@kodmob.test', 'display_name' => 'Yazar Bir', 'role' => 'author', 'password' => 'Yazar-sifre-2026'], $admin)->assertCreated();
        $id = $this->getJson('/v1/history', $admin)->json('items.0.id');
        $this->getJson("/v1/history/$id", $this->as('author'))->assertForbidden();
    }
}
