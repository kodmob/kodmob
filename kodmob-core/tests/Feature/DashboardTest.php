<?php

namespace Tests\Feature;

use App\Core\Dashboard\Widgets;
use App\Modules\Notifications\NotificationService;
use Tests\TestCase;

/** Genel bakış kartları: modüllerden katalog, yetkiye göre, kişinin düzeni (sıra, boyut, gizli), varsayılana dön. */
class DashboardTest extends TestCase
{
    public function test_default_layout_follows_the_catalog_and_permissions(): void
    {
        $admin = $this->admin();
        (new NotificationService)->create($this->userId('yonetici'), 'brief.received', 'Yeni talep: Ayşe');

        $res = $this->getJson('/v1/admin/dashboard', $admin)->assertOk()->assertJsonPath('custom', false);
        $keys = array_column($res->json('widgets'), 'key');
        foreach (['content', 'notifications', 'recent', 'briefs', 'activity'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertSame($keys, array_column($res->json('layout'), 'key'), 'varsayılan düzen katalog sırası');
        $byKey = array_column($res->json('widgets'), null, 'key');
        $this->assertSame('stats', $byKey['content']['type']);
        $this->assertSame('Yeni talep: Ayşe', $byKey['notifications']['data']['items'][0]['title']);
        $this->assertSame('1 okunmamış bildirim.', $byKey['notifications']['data']['note']);

        // Yazar talepleri görmez: kart da yok (kişisel veri).
        $author = array_column($this->getJson('/v1/admin/dashboard', $this->as('author'))->json('widgets') ?? [], 'key');
        $this->assertNotContains('briefs', $author);
    }

    public function test_layout_is_saved_per_person_and_hidden_widgets_are_not_computed(): void
    {
        $admin = $this->admin();
        $saved = $this->putJson('/v1/me/dashboard', ['items' => [
            ['key' => 'briefs', 'size' => 'lg'],
            ['key' => 'content', 'size' => 'sm', 'hidden' => true],
            ['key' => 'olmayan-kart', 'size' => 'md'],
        ]], $admin)->assertOk()->assertJsonPath('custom', true);

        $layout = $saved->json('layout');
        $this->assertSame(['briefs', 'lg', false], [$layout[0]['key'], $layout[0]['size'], $layout[0]['hidden']]);
        $this->assertSame(['content', 'sm', true], [$layout[1]['key'], $layout[1]['size'], $layout[1]['hidden']]);
        $this->assertNotContains('olmayan-kart', array_column($layout, 'key'));
        $this->assertContains('activity', array_column($layout, 'key'), 'düzende olmayan kart sona eklenir');
        $content = array_column($saved->json('widgets'), null, 'key')['content'];
        $this->assertNull($content['data'], 'gizli kartın verisi hesaplanmaz');

        // Başka kişinin düzeni etkilenmez.
        $this->assertFalse($this->getJson('/v1/admin/dashboard', $this->as('editor'))->json('custom'));

        $this->putJson('/v1/me/dashboard', ['items' => [['key' => 'Büyük Harf']]], $admin)->assertStatus(422)->assertJsonPath('details.fields.items', 'Kart düzeni geçersiz.');
        $this->putJson('/v1/me/dashboard', ['items' => [['key' => 'briefs', 'size' => 'dev']]], $admin)->assertStatus(422);

        $this->deleteJson('/v1/me/dashboard', [], $admin)->assertOk()->assertJsonPath('custom', false);
    }

    public function test_a_failing_widget_does_not_break_the_others(): void
    {
        $layout = Widgets::layout(['a' => ['size' => 'md'], 'b' => ['size' => 'sm']], [['key' => 'b', 'size' => 'lg'], ['key' => 'b'], ['key' => 'x']]);
        $this->assertSame([['key' => 'b', 'size' => 'lg', 'hidden' => false], ['key' => 'a', 'size' => 'md', 'hidden' => false]], $layout);
        $this->assertNull(Widgets::clean('metin'));
        $this->assertSame([['key' => 'a', 'size' => 'md', 'hidden' => false]], Widgets::clean([['key' => 'a'], ['key' => 'a']]));
    }
}
