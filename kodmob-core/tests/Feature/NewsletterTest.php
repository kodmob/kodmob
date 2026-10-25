<?php

namespace Tests\Feature;

use App\Core\Mail\SiteMail;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Core\Support\Options;
use App\Modules\Newsletter\Campaigns;
use App\Modules\Newsletter\NewsletterMail;
use App\Modules\Newsletter\NewsletterModule;
use App\Modules\Newsletter\Subscribers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Bülten (Modules\Newsletter; docs/done-bulten-raporu.md): çift onaylı abonelik, abonelikten çıkma (tek tık dahil),
 * spam koruması, bülten gönderimi (dil, saatlik sınır, imleç, sonradan gelen abone), e-posta hatasında durma,
 * panel uçları ve yetki. Modül ortamdan açılır (test sitesinin profilinde yok).
 */
class NewsletterTest extends TestCase
{
    private const ENV = 'blog,briefs,newsletter';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    private function subscriber(string $email, string $status = Subscribers::ACTIVE, string $locale = 'tr'): int
    {
        return (int) DB::table('newsletter_subscribers')->insertGetId([
            'email' => $email, 'locale' => $locale, 'status' => $status, 'token' => bin2hex(random_bytes(24)), 'source' => 'block',
            'created_at' => Clock::now(), 'updated_at' => Clock::now(), 'confirmed_at' => $status === Subscribers::ACTIVE ? Clock::now() : null,
        ]);
    }

    private function token(string $email): string
    {
        return (string) DB::table('newsletter_subscribers')->where('email', $email)->value('token');
    }

    private function campaign(array $h, array $over = []): array
    {
        return $this->postJson('/v1/admin/newsletter/campaigns', $over + [
            'subject' => 'Ekim bülteni',
            'body' => '<h2>Yenilikler</h2><p>Merhaba, <a href="/blog/">bloga</a> göz atın.</p><img src="/media/a.jpg" alt=""><script>alert(1)</script>',
            'locale' => 'tr',
        ], $h)->assertOk()->json();
    }

    public function test_subscription_is_double_opt_in_and_unsubscribe_is_one_click(): void
    {
        Mail::fake();
        $this->postJson('/v1/newsletter', ['email' => 'Ayse@Ornek.test', 'name' => 'Ayşe', 'locale' => 'en', 'consent' => true, 'source' => 'block'])
            ->assertCreated()->assertJson(['pending' => true]);
        $row = DB::table('newsletter_subscribers')->where('email', 'ayse@ornek.test')->first();
        $this->assertSame(['pending', 'en', 'block'], [$row->status, $row->locale, $row->source]);
        $this->assertNotNull($row->ip_hash);
        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('ayse@ornek.test') && str_starts_with($m->subjectLine, 'Please confirm')
            && str_contains($m->action['url'] ?? '', '?confirm='.$row->token) && $m->lines[0] === 'Hello Ayşe,');

        // Aynı adres 10 dakika içinde: yeni e-posta yok (başkasının adresine e-posta yağdırılmasın).
        $this->postJson('/v1/newsletter', ['email' => 'ayse@ornek.test', 'locale' => 'en', 'consent' => true])->assertCreated();
        Mail::assertSentCount(1);

        $this->postJson('/v1/newsletter/confirm', ['token' => 'f'.str_repeat('0', 47)])->assertNotFound()->assertJsonPath('code', 'km_newsletter_link');
        $this->postJson('/v1/newsletter/confirm', ['token' => $row->token])->assertOk()->assertJson(['status' => 'active']);
        $this->postJson('/v1/newsletter/confirm', ['token' => $row->token])->assertOk()->assertJson(['status' => 'active']);
        $this->assertNotNull(DB::table('newsletter_subscribers')->where('id', $row->id)->value('confirmed_at'));
        $this->assertSame(1, DB::table('events')->where('event_type', 'newsletter.confirmed')->count());

        // Abone yeniden abone olmak isterse aynı yanıt, e-posta yok (listede kim olduğu anlaşılmasın).
        $this->postJson('/v1/newsletter', ['email' => 'ayse@ornek.test', 'locale' => 'en', 'consent' => true])->assertCreated()->assertJson(['pending' => true]);
        Mail::assertSentCount(1);

        // E-posta istemcisinin tek tık çıkması (RFC 8058): adreste anahtar, gövdede List-Unsubscribe=One-Click.
        $this->call('POST', '/v1/newsletter/unsubscribe?token='.$row->token, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertJson(['status' => 'unsubscribed']);
        $this->postJson('/v1/newsletter/unsubscribe', ['token' => $row->token])->assertOk();
        $this->assertSame(1, DB::table('events')->where('event_type', 'newsletter.unsubscribed')->count());
        // Eski onay bağlantısı aboneliği geri açmaz.
        $this->postJson('/v1/newsletter/confirm', ['token' => $row->token])->assertOk()->assertJson(['status' => 'unsubscribed']);
    }

    public function test_form_validation_bots_and_rate_limit(): void
    {
        Mail::fake();
        $this->postJson('/v1/newsletter', ['email' => 'gecersiz', 'locale' => 'tr'])->assertStatus(422)
            ->assertJsonPath('details.fields.email', 'Geçerli bir e-posta adresi yazın.')
            ->assertJsonPath('details.fields.consent', 'Onay kutusunu işaretleyin.');
        // Tuzak alan ve 3 sn'den hızlı gönderim: başarılı yanıt, kayıt yok.
        $this->postJson('/v1/newsletter', ['email' => 'bot@ornek.test', 'consent' => true, 'website' => 'http://spam'])->assertCreated();
        $this->postJson('/v1/newsletter', ['email' => 'hizli@ornek.test', 'consent' => true, 'started_at' => (int) (microtime(true) * 1000)])->assertCreated();
        $this->assertSame(0, DB::table('newsletter_subscribers')->count());
        Mail::assertNothingSent();

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/v1/newsletter', ['email' => "kisi{$i}@ornek.test", 'consent' => true])->assertCreated();
        }
        $this->postJson('/v1/newsletter', ['email' => 'kisi6@ornek.test', 'consent' => true])->assertStatus(429);
    }

    public function test_campaign_sends_in_parts_within_hourly_limit_to_confirmed_subscribers_of_its_language(): void
    {
        $h = $this->admin();
        $a = $this->subscriber('a@ornek.test');
        $this->subscriber('b@ornek.test');
        $c = $this->subscriber('c@ornek.test');
        $this->subscriber('en@ornek.test', locale: 'en');
        $this->subscriber('bekleyen@ornek.test', Subscribers::PENDING);
        $this->subscriber('cikan@ornek.test', Subscribers::UNSUBSCRIBED);

        $campaign = $this->campaign($h);
        $this->assertSame('draft', $campaign['status']);
        $this->assertStringNotContainsString('<script', $campaign['body']);
        $list = $this->getJson('/v1/admin/newsletter/campaigns', $h)->assertOk()->json();
        $this->assertSame(4, $list['audience']['']);
        $this->assertSame(3, $list['audience']['tr']);

        // Deneme kendi adresime; abonelere bir şey gitmez.
        Mail::fake();
        $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/test", [], $h)->assertOk()->assertJson(['to' => 'yonetici@kodmob.test']);
        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $m) => $m->hasTo('yonetici@kodmob.test') && $m->subjectLine === '[Deneme] Ekim bülteni' && $m->token === null);
        Mail::assertSentCount(1);

        SettingsService::instance()->set(NewsletterModule::HOURLY_LIMIT, 2);
        $started = $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/send", [], $h)->assertOk()->json();
        $this->assertSame(['sending', 3], [$started['status'], $started['total']]);
        $this->putJson("/v1/admin/newsletter/campaigns/{$campaign['id']}", ['subject' => 'Değişti', 'body' => '<p>x</p>'], $h)->assertStatus(409);
        $this->deleteJson("/v1/admin/newsletter/campaigns/{$campaign['id']}", [], $h)->assertStatus(409);
        // Başladıktan sonra gelen abone bu bülteni almaz.
        $this->subscriber('yeni@ornek.test');

        Mail::fake();
        $this->assertSame(2, (new Campaigns)->tick());
        $this->assertSame(0, (new Campaigns)->tick(), 'saatlik sınır doldu');
        Mail::assertSent(NewsletterMail::class, 2);
        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $m) => $m->hasTo('a@ornek.test') && $m->token === $this->token('a@ornek.test'));
        Mail::assertNotSent(NewsletterMail::class, fn (NewsletterMail $m) => $m->hasTo('en@ornek.test') || $m->hasTo('bekleyen@ornek.test') || $m->hasTo('cikan@ornek.test'));
        $this->assertSame(['sending', 2], [(new Campaigns)->find($campaign['id'])['status'], (new Campaigns)->find($campaign['id'])['sent']]);

        // Bir saat sonra kalan gider; bülten biter, yöneticiye bildirim.
        Options::forget('newsletter_quota');
        $this->assertSame(1, (new Campaigns)->tick());
        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $m) => $m->hasTo('c@ornek.test'));
        Mail::assertNotSent(NewsletterMail::class, fn (NewsletterMail $m) => $m->hasTo('yeni@ornek.test'));
        $done = (new Campaigns)->find($campaign['id']);
        $this->assertSame(['sent', 3, 0], [$done['status'], $done['sent'], $done['failed']]);
        $this->assertNotNull($done['finished_at']);
        $this->assertSame(1, DB::table('notifications')->where('type', 'newsletter.campaign_sent')->count());
        $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/send", [], $h)->assertStatus(409);

        // E-postanın içeriği: mutlak adresler, abonelikten çıkma bağlantısı ve List-Unsubscribe başlıkları.
        $mail = new NewsletterMail('Ekim bülteni', $done['body'], 'tr', $this->token('a@ornek.test'));
        $html = $mail->render();
        $this->assertStringContainsString('src="https://kodmob.test/media/a.jpg"', $html);
        $this->assertStringContainsString('href="https://kodmob.test/blog/"', $html);
        $this->assertStringContainsString('?unsubscribe='.$this->token('a@ornek.test'), $html);
        $this->assertStringContainsString('Abonelikten çık', $html);
        $headers = $mail->headers()->text;
        $this->assertSame('<https://kodmob.test/api/v1/newsletter/unsubscribe?token='.$this->token('a@ornek.test').'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertStringContainsString('bloga (https://kodmob.test/blog/)', NewsletterMail::plain(NewsletterMail::prepare($done['body'])));

        $this->assertSame(1, DB::table('events')->where('event_type', 'newsletter.campaign_started')->count());
        $this->assertSame(1, DB::table('events')->where('event_type', 'newsletter.campaign_tested')->count());
        $this->assertSame($a + 0, (int) DB::table('newsletter_subscribers')->where('email', 'a@ornek.test')->value('id'));
        $this->assertGreaterThan($c, (int) DB::table('newsletter_subscribers')->where('email', 'yeni@ornek.test')->value('id'));
    }

    public function test_mail_server_errors_stop_the_campaign_and_resume_retries_the_same_subscribers(): void
    {
        $h = $this->admin();
        for ($i = 1; $i <= 6; $i++) {
            $this->subscriber("kisi{$i}@ornek.test");
        }
        $campaign = $this->campaign($h);
        $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/send", [], $h)->assertOk();

        Mail::extend('broken', static fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Bağlantı reddedildi');
            }

            public function __toString(): string
            {
                return 'broken://';
            }
        });
        config(['mail.mailers.broken' => ['transport' => 'broken'], 'mail.default' => 'broken']);
        $this->assertSame(0, (new Campaigns)->tick());
        $stopped = (new Campaigns)->find($campaign['id']);
        $this->assertSame(['paused', 0, 0], [$stopped['status'], $stopped['sent'], $stopped['failed']]);
        $this->assertStringContainsString('Bağlantı reddedildi', (string) $stopped['error']);
        $this->assertSame(1, DB::table('notifications')->where('type', 'newsletter.campaign_stopped')->count());
        $this->assertSame(5, DB::table('mail_log')->where('kind', 'newsletter')->where('status', 'failed')->count());

        // Ayar düzeldi: "Devam et" aynı abonelerden sürer, hepsi alır.
        config(['mail.default' => 'array']);
        Mail::fake();
        $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/send", [], $h)->assertOk()->assertJsonPath('status', 'sending');
        $done = $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/continue", [], $h)->assertOk()->json();
        $this->assertSame(['sent', 6, 0, null], [$done['status'], $done['sent'], $done['failed'], $done['error']]);
        Mail::assertSent(NewsletterMail::class, 6);
    }

    public function test_panel_subscribers_pause_and_permissions(): void
    {
        Mail::fake();
        $h = $this->admin();
        $added = $this->postJson('/v1/admin/newsletter/subscribers', ['email' => 'Yeni@Ornek.test', 'name' => 'Yeni', 'locale' => 'tr'], $h)->assertOk()->json();
        $this->assertSame(['yeni@ornek.test', 'pending', 'panel'], [$added['email'], $added['status'], $added['source']]);
        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('yeni@ornek.test') && str_contains($m->subjectLine, 'onaylayın'));
        $this->postJson('/v1/newsletter/confirm', ['token' => $this->token('yeni@ornek.test')])->assertOk();
        $this->postJson('/v1/admin/newsletter/subscribers', ['email' => 'yeni@ornek.test'], $h)->assertStatus(422)->assertJsonPath('details.fields.email', 'Bu adres zaten abone.');
        $this->subscriber('eski@ornek.test', Subscribers::PENDING);
        DB::table('newsletter_subscribers')->where('email', 'eski@ornek.test')->update(['updated_at' => Clock::carbon(Clock::now())->subDays(Subscribers::PENDING_DAYS + 1)->format('Y-m-d H:i:s')]);

        $list = $this->getJson('/v1/admin/newsletter/subscribers', $h)->assertOk()->json();
        $this->assertSame(['pending' => 1, 'active' => 1, 'unsubscribed' => 0], $list['counts']);
        $this->assertSame(1, (new Subscribers)->purgePending());
        $this->deleteJson('/v1/admin/newsletter/subscribers/'.$added['id'], [], $h)->assertOk();
        $this->assertSame(0, DB::table('newsletter_subscribers')->count());
        $this->assertSame(1, DB::table('events')->where('event_type', 'newsletter.subscriber_deleted')->count());

        // Duraklat ve sürdür; taslak silinir.
        $this->subscriber('a@ornek.test');
        $campaign = $this->campaign($h, ['locale' => '']);
        $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/pause", [], $h)->assertStatus(409);
        $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/send", [], $h)->assertOk();
        $this->postJson("/v1/admin/newsletter/campaigns/{$campaign['id']}/pause", [], $h)->assertOk()->assertJsonPath('status', 'paused');
        $this->assertSame(0, (new Campaigns)->tick());
        // Parça sürerken duraklatılırsa kalanı gitmez (ilk e-postadan sonra panel duraklatır).
        $this->subscriber('b@ornek.test');
        $this->subscriber('c@ornek.test');
        $paused = $this->campaign($h, ['locale' => '', 'subject' => 'Yarıda']);
        $this->postJson("/v1/admin/newsletter/campaigns/{$paused['id']}/send", [], $h)->assertOk();
        Mail::fake();
        Mail::shouldReceive('to')->once()->andReturnUsing(function () use ($paused) {
            DB::table('newsletter_campaigns')->where('id', $paused['id'])->update(['status' => Campaigns::PAUSED]);

            return new class
            {
                public function send(): void {}
            };
        });
        $this->assertSame(1, (new Campaigns)->tick(only: $paused['id']));
        $stoppedMidway = (new Campaigns)->find($paused['id']);
        $this->assertSame(['paused', 1, 0], [$stoppedMidway['status'], $stoppedMidway['sent'], $stoppedMidway['failed']]);
        $draft = $this->campaign($h, ['subject' => 'Taslak']);
        $this->putJson("/v1/admin/newsletter/campaigns/{$draft['id']}", ['subject' => 'x', 'body' => ''], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.subject', 'Konuyu yazın.')->assertJsonPath('details.fields.body', 'Bültenin içeriğini yazın.');
        $this->deleteJson("/v1/admin/newsletter/campaigns/{$draft['id']}", [], $h)->assertOk();

        // Editör (yetkisi yok) göremez; yetki Yönetici'de.
        $editor = $this->as('editor');
        $this->getJson('/v1/admin/newsletter/subscribers', $editor)->assertForbidden();
        $this->postJson('/v1/admin/newsletter/campaigns', ['subject' => 'x'], $editor)->assertForbidden();
        $this->getJson('/v1/me', $h)->assertOk()->assertJsonFragment(['km_manage_newsletter']);
    }
}
