<?php

namespace Tests\Feature;

use App\Core\Security\Roles;
use Tests\TestCase;

/**
 * Değişiklik akışı (Modules\Live\Changes): panel tablolarının "1 yeni kayıt · 1 güncelleme" şeridi.
 */
class ChangesTest extends TestCase
{
    private function project(array $h, string $title): array
    {
        return $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'locales' => ['tr' => ['status' => 'publish']], 'i18n' => ['tr' => ['title' => $title]]], $h)->assertCreated()->json();
    }

    public function test_changes_since_cursor_by_type(): void
    {
        $admin = $this->admin();
        $editor = $this->as('editor');
        $kept = $this->project($admin, 'Eski');
        $cursor = $this->getJson('/v1/changes/cursor', $admin)->assertOk()->json('cursor');

        // Başka bir kullanıcı: bir yeni kayıt, bir güncelleme, bir silme, sıralama.
        $new = $this->project($editor, 'Yeni');
        $kept['i18n']['tr']['title'] = 'Eski (güncel)';
        $this->putJson('/v1/admin/content/projects/'.$kept['id'], $kept, $editor)->assertOk();
        $this->deleteJson('/v1/admin/content/projects/'.$new['id'], [], $editor)->assertNoContent();
        $this->postJson('/v1/admin/content/projects/order', ['ids' => [$kept['id']]], $editor)->assertSuccessful();
        $this->postJson('/v1/admin/content/posts', ['title' => 'Yazı', 'locale' => 'tr'], $editor)->assertCreated();

        $changes = $this->getJson("/v1/changes?since=$cursor&types=project", $admin)->assertOk();
        $items = collect($changes->json('items'));
        $this->assertSame(['project'], $items->pluck('type')->unique()->values()->all(), 'yalnızca istenen tür');
        $this->assertTrue($items->firstWhere(fn ($i) => $i['id'] === $new['id'] && $i['created'])['created']);
        $this->assertNotNull($items->first(fn ($i) => $i['id'] === $kept['id'] && ! $i['created'] && ! $i['deleted'] && ! $i['reordered']));
        $this->assertNotNull($items->first(fn ($i) => $i['id'] === $new['id'] && $i['deleted']));
        $this->assertNotNull($items->first(fn ($i) => $i['reordered']));
        $this->assertFalse($items->first()['mine']);
        $this->assertFalse($changes->json('truncated'));
        $this->assertGreaterThan($cursor, $changes->json('cursor'));

        // İmleçten sonrası boş.
        $this->getJson('/v1/changes?since='.$changes->json('cursor').'&types=project', $admin)->assertJsonPath('items', []);
    }

    public function test_types_follow_capabilities(): void
    {
        $admin = $this->admin();
        $cursor = $this->getJson('/v1/changes/cursor', $admin)->json('cursor');
        $this->project($admin, 'Gizli');
        Roles::setCaps('author', ['km_access_panel', 'km_manage_posts']);
        $this->getJson("/v1/changes?since=$cursor&types=project,post", $this->as('author'))->assertOk()->assertJsonPath('items', []);
        $this->getJson('/v1/changes/cursor')->assertUnauthorized();
    }
}
