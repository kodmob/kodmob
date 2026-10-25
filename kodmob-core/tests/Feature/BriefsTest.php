<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BriefsTest extends TestCase
{
    private array $valid = [
        'name' => 'Ayşe Deneme',
        'email' => 'ayse@example.com',
        'company' => 'Deneme AŞ',
        'services' => ['web', 'ai'],
        'message' => 'Kurumsal web sitesi için teklif almak istiyoruz, teşekkürler.',
        'consent' => true,
        'locale' => 'en',
    ];

    public function test_submit_notifies_admin_and_lists_in_panel(): void
    {
        $h = $this->admin();
        $id = $this->postJson('/v1/briefs', $this->valid)->assertCreated()->assertJsonPath('received', true)->json('id');

        $notes = $this->getJson('/v1/notifications', $h)->assertOk();
        $notes->assertJsonPath('unread', 1)->assertJsonPath('items.0.title', 'Yeni talep: Ayşe Deneme')->assertJsonPath('items.0.url', "/panel/talepler/$id");

        $this->getJson('/v1/admin/briefs?status=new', $h)->assertJsonPath('total', 1)->assertJsonPath('facets.status.new', 1);
        $this->getJson("/v1/admin/briefs/$id", $h)->assertJsonPath('status', 'read')->assertJsonPath('locale', 'en');
        $this->postJson("/v1/admin/briefs/$id/status", ['status' => 'archived'], $h)->assertJsonPath('status', 'archived');
        $this->postJson("/v1/admin/briefs/$id/status", ['status' => 'archived'], $h)->assertStatus(409);

        $this->deleteJson("/v1/admin/briefs/$id", [], $h)->assertNoContent();
        $this->assertSame(0, DB::table('events')->where('subject_type', 'brief')->where('subject_id', $id)->count());
    }

    /** Talebin yolu: yeni → okundu → yanıtlandı; listeden "Okundu say", arşiv ve geri alma. */
    public function test_status_flow_read_replied_archive(): void
    {
        $h = $this->admin();
        $id = $this->postJson('/v1/briefs', $this->valid)->assertCreated()->json('id');
        $url = "/v1/admin/briefs/$id/status";

        // Listeden okundu sayılır (açmadan); talep yeniyken önceden 409 dönüyordu.
        $this->postJson($url, ['status' => 'read'], $h)->assertOk()->assertJsonPath('status', 'read');
        $this->postJson($url, ['status' => 'replied'], $h)->assertOk()->assertJsonPath('status', 'replied');
        $this->postJson($url, ['status' => 'read'], $h)->assertOk()->assertJsonPath('status', 'replied', 'yanıtlanan geri "okundu" olmaz');
        $this->getJson('/v1/admin/briefs?status=replied', $h)->assertJsonPath('total', 1);
        $this->postJson($url, ['status' => 'archived'], $h)->assertJsonPath('status', 'archived');
        $this->postJson($url, ['status' => 'replied'], $h)->assertStatus(409);
        $this->postJson($url, ['status' => 'reopen'], $h)->assertJsonPath('status', 'read');
        $this->postJson($url, ['status' => 'bilinmeyen'], $h)->assertStatus(422);
    }

    public function test_spam_trap_validation_and_rate_limit(): void
    {
        $this->postJson('/v1/briefs', $this->valid + ['website' => 'spam'])->assertCreated()->assertJsonPath('id', null);
        $this->assertSame(0, DB::table('briefs')->count());

        $this->postJson('/v1/briefs', ['name' => 'A', 'email' => 'x', 'services' => [], 'message' => 'kısa'])
            ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['name', 'email', 'services', 'message', 'consent']]]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/briefs', $this->valid)->assertCreated();
        }
        $this->postJson('/v1/briefs', $this->valid)->assertStatus(429)->assertJsonPath('code', 'km_rate_limited');
    }

    /** İletişim formu (başlangıç profilinin formu) hizmet seçimi istemez; proje brifi ister. */
    public function test_contact_form_needs_no_services(): void
    {
        $contact = ['source' => 'contact', 'services' => []] + $this->valid;
        $id = $this->postJson('/v1/briefs', $contact)->assertCreated()->json('id');
        $this->assertSame('contact', DB::table('briefs')->where('id', $id)->value('source'));

        $this->postJson('/v1/briefs', ['services' => []] + $this->valid)
            ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['services']]]);
    }

    public function test_mail_failure_does_not_lose_brief(): void
    {
        $this->admin();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP yok'));
        $this->postJson('/v1/briefs', $this->valid)->assertCreated();
        $this->app->terminate();
        $this->assertSame(1, DB::table('briefs')->count());
        // Gönderilemeyen iki e-posta (bildirim, yanıt) kayıtta hatasıyla.
        $this->assertSame(['SMTP yok', 'SMTP yok'], DB::table('mail_log')->where('status', 'failed')->pluck('error')->all());
    }
}
