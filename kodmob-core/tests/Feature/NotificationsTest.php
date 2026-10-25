<?php

namespace Tests\Feature;

use App\Modules\Notifications\NotificationService;
use Tests\TestCase;

/** Bildirimler sayfası: sayfalı liste, süzgeçler, okundu / okunmadı, silme; herkes yalnızca kendisininkini görür. */
class NotificationsTest extends TestCase
{
    public function test_page_filters_by_status_and_kind(): void
    {
        $admin = $this->admin();
        $me = $this->userId('yonetici');
        $service = new NotificationService;
        for ($i = 1; $i <= 3; $i++) {
            $service->create($me, 'brief.received', "Talep $i");
        }
        $order = $service->create($me, 'commerce.order_placed', 'Sipariş');
        $service->create($me, 'seo.broken_link', 'Kırık bağlantı');
        $service->markRead($me, [$order]);

        $page = $this->getJson('/v1/notifications?page=1&per_page=2', $admin)->assertOk();
        $page->assertJsonPath('total', 5)->assertJsonPath('pages', 3)->assertJsonPath('unread', 4)->assertJsonCount(2, 'items');
        $page->assertJsonPath('items.0.title', 'Kırık bağlantı');
        $this->assertSame(['brief' => 3, 'commerce' => 1, 'seo' => 1], array_column($page->json('kinds'), 'count', 'key'));
        $this->assertSame('Talepler', $page->json('kinds.0.label'));

        $this->getJson('/v1/notifications?page=2&per_page=2', $admin)->assertJsonPath('items.0.title', 'Talep 3');
        $this->getJson('/v1/notifications?page=1&kind=brief', $admin)->assertJsonPath('total', 3);
        $this->getJson('/v1/notifications?page=1&status=read', $admin)->assertJsonPath('total', 1)->assertJsonPath('items.0.title', 'Sipariş');
        $this->getJson('/v1/notifications?page=1&status=unread&kind=commerce', $admin)->assertJsonPath('total', 0);
        // Geçersiz tür adı süzgeç sayılmaz.
        $this->getJson('/v1/notifications?page=1&kind=%25', $admin)->assertJsonPath('total', 5);
        // Zil eski biçimde kalır.
        $this->getJson('/v1/notifications', $admin)->assertOk()->assertJsonMissingPath('total')->assertJsonPath('unread', 4);
    }

    public function test_unread_and_delete_touch_only_own_notifications(): void
    {
        $admin = $this->admin();
        $editor = $this->as('editor');
        $me = $this->userId('yonetici');
        $other = $this->userId('editor');
        $service = new NotificationService;
        $a = $service->create($me, 'brief.received', 'A');
        $service->create($me, 'brief.received', 'B');
        $foreign = $service->create($other, 'brief.received', 'Editörün');

        $this->postJson('/v1/notifications/read', ['all' => true], $admin)->assertJsonPath('unread', 0);
        $this->postJson('/v1/notifications/unread', ['ids' => [$a, $foreign]], $admin)->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('/v1/notifications/delete', ['ids' => [$foreign]], $admin)->assertJsonPath('deleted', 0);

        // Okunmuşların hepsi: B gider, okunmamış A kalır.
        $this->postJson('/v1/notifications/delete', ['read' => true], $admin)->assertJsonPath('deleted', 1)->assertJsonPath('unread', 1);
        $this->assertSame(['A'], array_column($this->getJson('/v1/notifications?page=1', $admin)->json('items'), 'title'));
        $this->postJson('/v1/notifications/delete', ['ids' => [$a]], $admin)->assertJsonPath('deleted', 1);
        $this->getJson('/v1/notifications?page=1', $admin)->assertJsonPath('total', 0);

        $this->assertSame(['Editörün'], array_column($this->getJson('/v1/notifications?page=1', $editor)->json('items'), 'title'));
    }
}
