<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use Tests\TestCase;

/** Ortak çoklu kullanıcı yönetimi. */
class UsersTest extends TestCase
{
    public function test_admin_creates_user_who_can_log_in(): void
    {
        $admin = $this->admin();
        $this->postJson('/v1/users', ['username' => 'a!', 'email' => 'ayse@kodmob.test', 'display_name' => 'Ayşe', 'role' => 'editor', 'password' => 'kisa'], $admin)
            ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['username']]]);
        $this->postJson('/v1/users', ['username' => 'ayse', 'email' => 'ayse@kodmob.test', 'display_name' => 'Ayşe', 'role' => 'editor', 'password' => 'kisa'], $admin)
            ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['password']]]);
        $created = $this->postJson('/v1/users', ['username' => 'ayse', 'email' => 'ayse@kodmob.test', 'display_name' => 'Ayşe', 'phone' => '+90 555 000 00 00', 'role' => 'editor', 'password' => 'Editor-sifre-2026'], $admin)
            ->assertCreated()->assertJsonPath('role_label', 'Editör')->assertJsonPath('status', 'active');
        $this->postJson('/v1/users', ['username' => 'ayse', 'email' => 'baska@kodmob.test', 'display_name' => 'Kopya', 'role' => 'editor', 'password' => 'Editor-sifre-2026'], $admin)
            ->assertStatus(422)->assertJsonPath('details.fields.username', 'Bu kullanıcı adı kullanılıyor.');

        $login = $this->postJson('/v1/auth/token', ['username' => 'ayse', 'password' => 'Editor-sifre-2026'])->assertOk();
        $this->getJson('/v1/me', ['Authorization' => 'Bearer '.$login->json('token')])->assertOk()->assertJsonPath('role.slug', 'editor');

        $list = $this->getJson('/v1/users?q=ayş', $admin)->assertOk();
        $list->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $created->json('id'))->assertJsonPath('items.0.sessions', 1);
        $this->assertSame(1, $this->getJson('/v1/users', $admin)->json('facets.role.editor'));
        $this->getJson('/v1/users/'.$created->json('id'), $admin)->assertOk()->assertJsonCount(1, 'session_list');
    }

    public function test_deactivation_ends_access_immediately(): void
    {
        $admin = $this->admin();
        $editor = $this->as('editor');
        $id = $this->userId('editor');
        $this->getJson('/v1/me', $editor)->assertOk();

        $this->postJson("/v1/users/$id/deactivate", [], $admin)->assertOk()->assertJsonPath('status', 'passive');
        $this->getJson('/v1/me', $editor)->assertStatus(401);
        $this->postJson('/v1/auth/token', ['username' => 'editor', 'password' => 'gizli-sifre-123'])->assertStatus(403)->assertJsonPath('code', 'jwt_auth_failed');

        $this->postJson("/v1/users/$id/activate", [], $admin)->assertOk()->assertJsonPath('status', 'active');
        $this->postJson('/v1/auth/token', ['username' => 'editor', 'password' => 'gizli-sifre-123'])->assertOk();
    }

    public function test_self_and_last_admin_are_protected(): void
    {
        $admin = $this->admin();
        $me = $this->userId('yonetici');
        $this->postJson("/v1/users/$me/deactivate", [], $admin)->assertStatus(409)->assertJsonPath('code', 'km_user_self');
        $this->putJson("/v1/users/$me", ['role' => 'editor'], $admin)->assertStatus(409);

        // Kullanıcı yöneten ama Yönetici olmayan rol: Yönetici veremez, Yöneticiye dokunamaz.
        $this->putJson('/v1/roles/editor/caps', ['caps' => ['km_view_users', 'km_manage_users']], $admin)->assertOk();
        $editor = $this->as('editor');
        $this->postJson('/v1/users', ['username' => 'yeni', 'email' => 'yeni@kodmob.test', 'display_name' => 'Yeni', 'role' => 'admin', 'password' => 'Yeni-sifre-2026'], $editor)
            ->assertForbidden();
        $this->postJson("/v1/users/$me/deactivate", [], $editor)->assertForbidden();

        // İkinci Yönetici varken ilki pasife alınabilir; son kalan alınamaz.
        $this->as(User::ADMIN, 'ikinci');
        $second = $this->userId('ikinci');
        $this->postJson("/v1/users/$second/deactivate", [], $admin)->assertOk();
        $this->assertSame(1, User::query()->where('role', 'admin')->where('status', 'active')->count());
    }

    public function test_password_change_by_admin_ends_user_sessions(): void
    {
        $admin = $this->admin();
        $editor = $this->as('editor');
        $id = $this->userId('editor');
        $this->putJson("/v1/users/$id", ['display_name' => 'Editör Yeni', 'password' => 'Yeni-sifre-2026'], $admin)->assertOk()->assertJsonPath('display_name', 'Editör Yeni');
        $this->getJson('/v1/me', $editor)->assertStatus(401)->assertJsonPath('code', 'km_session_ended');
        $history = $this->getJson('/v1/history', $admin)->json('items.0');
        $this->assertSame('users.updated', $history['type']);
        $this->assertStringContainsString('Ad: Editor → Editör Yeni', $history['text']);
        $this->assertStringNotContainsString('Yeni-sifre-2026', json_encode($history));
    }
}
