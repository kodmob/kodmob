<?php

namespace Tests\Feature;

use App\Core\Security\Capability;
use Tests\TestCase;

/** Rol bazlı yetkiler: sayfalar, işlemler, roller ekranı, yan menü. */
class AccessTest extends TestCase
{
    public function test_me_returns_role_capabilities_and_menu(): void
    {
        $me = $this->getJson('/v1/me', $this->as('author'))->assertOk();
        $me->assertJsonPath('role.slug', 'author')->assertJsonPath('role.label', 'Yazar')->assertJsonPath('is_admin', false)->assertJsonPath('menu', null);
        $this->assertContains(Capability::MANAGE_POSTS, $me->json('capabilities'));
        $this->assertNotContains('km_manage_projects', $me->json('capabilities'));

        $admin = $this->getJson('/v1/me', $this->admin())->assertOk();
        $this->assertSame(count(Capability::keys()), count($admin->json('capabilities')));
    }

    public function test_author_writes_drafts_and_cannot_publish_delete_or_manage(): void
    {
        $author = $this->as('author');
        $post = $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Yazarın Yazısı', 'locale' => 'tr', 'html' => '<p>Metin</p>'], $author)
            ->assertCreated()->assertJsonPath('status', 'draft');
        $id = $post->json('id');

        // Editör yayınlar; yazar düzenleyince yayın durumu değişmez.
        $this->putJson("/v1/admin/content/posts/$id", ['status' => 'publish', 'title' => 'Yazarın Yazısı', 'locale' => 'tr', 'html' => '<p>Metin</p>'], $this->as('editor'))
            ->assertOk()->assertJsonPath('status', 'publish');
        $this->putJson("/v1/admin/content/posts/$id", ['status' => 'draft', 'title' => 'Yazarın Yazısı 2', 'locale' => 'tr', 'html' => '<p>Metin</p>'], $author)
            ->assertOk()->assertJsonPath('status', 'publish');

        $this->deleteJson("/v1/admin/content/posts/$id", [], $author)->assertForbidden()->assertJsonPath('details.capability', Capability::DELETE_CONTENT);
        $this->getJson('/v1/admin/content/projects', $author)->assertForbidden();
        $this->getJson('/v1/users', $author)->assertForbidden()->assertJsonPath('code', 'km_forbidden');
        $this->getJson('/v1/briefs', $author)->assertForbidden();
        $this->getJson('/v1/security', $author)->assertForbidden();
        $this->getJson('/v1/roles', $this->as('editor'))->assertForbidden();
    }

    public function test_admin_manages_roles_and_changes_apply_immediately(): void
    {
        $admin = $this->admin();
        $roles = $this->getJson('/v1/roles', $admin)->assertOk();
        $this->assertSame(['admin', 'editor', 'author', 'sales'], array_column($roles->json('roles'), 'slug'));
        $this->assertNotEmpty($roles->json('capabilities.0.items'));

        $created = $this->postJson('/v1/roles', ['label' => 'İçerik Stajyeri', 'description' => 'Yalnızca blog', 'copy_from' => 'author'], $admin)
            ->assertCreated()->assertJsonPath('system', false);
        $slug = $created->json('slug');
        $this->assertSame('icerik-stajyeri', $slug);

        $intern = $this->as($slug, 'stajyer');
        $this->getJson('/v1/admin/content/projects', $intern)->assertForbidden();
        $this->putJson("/v1/roles/$slug/caps", ['caps' => [Capability::MANAGE_POSTS, 'km_manage_projects', Capability::MANAGE_ROLES]], $admin)
            ->assertOk()->assertJsonPath('caps', [Capability::ACCESS_PANEL, 'km_manage_projects', Capability::MANAGE_POSTS]);
        $this->getJson('/v1/admin/content/projects', $intern)->assertOk();

        // Hazır rol: değiştir, varsayılana dön; Yönetici kilitli.
        $this->putJson('/v1/roles/editor/caps', ['caps' => [Capability::MANAGE_POSTS]], $admin)->assertOk()->assertJsonPath('customized', true);
        $this->putJson('/v1/roles/editor/caps', ['caps' => null], $admin)->assertOk()->assertJsonPath('customized', false);
        $this->putJson('/v1/roles/admin/caps', ['caps' => []], $admin)->assertStatus(409)->assertJsonPath('code', 'km_role_locked');

        // Kullanıcısı olan rol silinemez; hazır rol hiç silinemez.
        $this->deleteJson("/v1/roles/$slug", [], $admin)->assertStatus(409)->assertJsonPath('code', 'km_role_in_use');
        $this->deleteJson('/v1/roles/editor', [], $admin)->assertStatus(409);
    }

    public function test_role_menu_is_validated_and_returned_in_me(): void
    {
        $admin = $this->admin();
        $this->putJson('/v1/roles/sales/menu', ['groups' => [['label' => 'Satış', 'items' => ['javascript:alert(1)']]]], $admin)
            ->assertStatus(422);
        $this->putJson('/v1/roles/sales/menu', ['groups' => [['label' => 'Satış', 'items' => ['#/briefs', 'domains', 'briefs', '#/jarvis/domains']]]], $admin)
            ->assertOk()->assertJsonPath('menu.0.items', ['briefs', 'domains']);
        $this->getJson('/v1/me', $this->as('sales'))->assertJsonPath('menu.0.label', 'Satış');

        // Başlıksız grup (varsayılan menüdeki Genel bakış, Proje Ayarları gibi) kaydedilir.
        $this->putJson('/v1/roles/sales/menu', ['groups' => [['key' => 'main', 'label' => '', 'items' => ['overview']], ['key' => 'project-settings', 'label' => '', 'items' => ['project-settings']]]], $admin)
            ->assertOk()->assertJsonPath('menu.0.label', '')->assertJsonPath('menu.1.key', 'project-settings');

        $this->putJson('/v1/roles/sales/menu', ['groups' => null], $admin)->assertOk()->assertJsonPath('menu', null);
    }

    public function test_settings_are_filtered_by_group_capability(): void
    {
        $this->getJson('/v1/settings', $this->as('author'))->assertForbidden();
        $editor = $this->as('editor');
        // Editör yalnızca SEO (km_manage_seo) ve çoklu dil (km_manage_languages) ayarlarını görür.
        $groups = array_values(array_unique(array_column($this->getJson('/v1/settings', $editor)->assertOk()->json('items'), 'group')));
        sort($groups);
        $this->assertSame(['SEO', 'Çoklu dil'], $groups);

        $this->putJson('/v1/roles/editor/caps', ['caps' => [Capability::MANAGE_SETTINGS]], $this->admin())->assertOk();
        $groups = array_unique(array_column($this->getJson('/v1/settings', $editor)->assertOk()->json('items'), 'group'));
        $this->assertNotContains('Güvenlik', $groups);
        $this->assertNotContains('Jarvis', $groups);
        $errors = $this->putJson('/v1/settings', ['values' => ['security.waf' => false]], $editor)->assertOk()->json('errors');
        $this->assertSame(['security.waf' => 'Bu ayarı değiştirme yetkiniz yok.'], $errors);

        $security = $this->getJson('/v1/settings?group=Güvenlik', $this->admin())->assertOk()->json('items');
        $this->assertContains('security.access_ttl_minutes', array_column($security, 'key'));
    }
}
