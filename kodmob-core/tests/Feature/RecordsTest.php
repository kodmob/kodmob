<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Genel Sil ve Silinenler (ortak tablonun silme/geri yükleme sözleşmesi). */
class RecordsTest extends TestCase
{
    public function test_delete_restore_cycle_with_capabilities(): void
    {
        $admin = $this->admin();
        $editor = $this->as('editor');
        $id = $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Silinecek Proje']]], $admin)->assertCreated()->json('id');

        $plan = $this->getJson("/v1/records/plan?type=projects&id=$id", $editor)->assertOk();
        $plan->assertJsonPath('title', 'Silinecek Proje')->assertJsonPath('permanent', false)->assertJsonPath('notes.0', 'Sitede yayından kalkar.');
        $this->postJson('/v1/records/hide', ['type' => 'project', 'id' => $id], $this->as('author'))->assertForbidden();
        $this->postJson('/v1/records/hide', ['type' => 'project', 'id' => $id], $editor)->assertOk()->assertJsonPath('permanent', false);
        $this->getJson("/v1/admin/content/projects/$id", $admin)->assertNotFound();

        // Silinenler: Editör göremez (km_restore_records), Yönetici görür ve geri yükler.
        $this->getJson('/v1/records/hidden?type=project', $editor)->assertForbidden();
        $hidden = $this->getJson('/v1/records/hidden?type=project', $admin)->assertOk();
        $hidden->assertJsonPath('items.0.label', 'Silinecek Proje')->assertJsonPath('items.0.hidden_by_name', 'Editor');
        $this->postJson('/v1/records/restore', ['type' => 'project', 'id' => $id], $admin)->assertOk();
        $this->getJson("/v1/admin/content/projects/$id", $admin)->assertOk();
        $this->postJson('/v1/records/restore', ['type' => 'project', 'id' => $id], $admin)->assertNotFound();

        $types = array_column($this->getJson('/v1/history?scope=all', $admin)->json('items'), 'type');
        $this->assertSame(['records.restored', 'project.deleted', 'project.created'], array_slice($types, 0, 3));
    }

    public function test_restored_post_gets_free_slug(): void
    {
        $admin = $this->admin();
        $first = $this->postJson('/v1/admin/content/posts', ['title' => 'Aynı Başlık', 'locale' => 'tr', 'html' => '<p>1</p>'], $admin)->assertCreated()->json('id');
        $this->postJson('/v1/records/hide', ['type' => 'post', 'id' => $first], $admin)->assertOk();
        $this->postJson('/v1/admin/content/posts', ['title' => 'Aynı Başlık', 'locale' => 'tr', 'html' => '<p>2</p>'], $admin)->assertCreated();
        $this->postJson('/v1/records/restore', ['type' => 'post', 'id' => $first], $admin)->assertOk();
        $this->assertSame('ayni-baslik-2', DB::table('posts')->where('id', $first)->value('slug'));
    }

    public function test_brief_delete_is_permanent(): void
    {
        $admin = $this->admin();
        $id = $this->postJson('/v1/briefs', [
            'name' => 'Ayşe Deneme', 'email' => 'ayse@example.com', 'services' => ['web'], 'message' => 'Bir web sitesi istiyoruz, görüşelim.', 'consent' => true, 'locale' => 'tr',
        ])->assertCreated()->json('id');
        $this->getJson("/v1/records/plan?type=brief&id=$id", $admin)->assertOk()->assertJsonPath('permanent', true);
        $this->postJson('/v1/records/hide', ['type' => 'brief', 'id' => $id], $this->as('sales'))->assertForbidden();
        $this->postJson('/v1/records/hide', ['type' => 'brief', 'id' => $id], $admin)->assertOk()->assertJsonPath('permanent', true);
        $this->assertSame(0, DB::table('briefs')->count());
        $this->postJson('/v1/records/restore', ['type' => 'brief', 'id' => $id], $admin)->assertStatus(409);
    }
}
