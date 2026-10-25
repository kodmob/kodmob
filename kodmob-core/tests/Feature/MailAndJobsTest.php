<?php

namespace Tests\Feature;

use App\Core\Jobs\Background;
use App\Core\Mail\MailSettings;
use App\Core\Mail\SendMail;
use App\Core\Mail\SiteMail;
use App\Core\Support\Clock;
use App\Core\Support\Options;
use App\Core\Support\SiteManifest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailAndJobsTest extends TestCase
{
    public function test_smtp_settings_keep_the_password_secret(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/system/mail', ['mailer' => 'smtp', 'host' => ''], $h)->assertStatus(422)->assertJsonPath('details.fields.host', fn ($m) => str_contains($m, 'SMTP'));

        $view = $this->putJson('/v1/system/mail', [
            'mailer' => 'smtp', 'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl',
            'username' => 'info@kodmob.test', 'password' => 'çok-gizli', 'from_address' => 'info@kodmob.test', 'from_name' => 'Kodmob',
        ], $h)->assertOk()->json();
        $this->assertTrue($view['password_set']);
        $this->assertArrayNotHasKey('password', $view);
        $this->assertStringStartsWith('enc:', Options::get('mail_settings')['password'], 'şifre şifreli saklanır');
        $this->assertStringNotContainsString('çok-gizli', (string) DB::table('events')->get()->toJson(), 'işlem kaydına girmez');

        // Boş şifre eskisini korur; uygulanınca Laravel'in SMTP ayarı olur.
        $this->putJson('/v1/system/mail', ['password' => ''], $h)->assertJsonPath('password_set', true);
        MailSettings::apply();
        $this->assertSame(['smtp', 'smtp.hostinger.com', 'smtps', 'çok-gizli', 'info@kodmob.test'], [
            config('mail.default'), config('mail.mailers.smtp.host'), config('mail.mailers.smtp.scheme'), config('mail.mailers.smtp.password'), config('mail.from.address'),
        ]);
        $this->putJson('/v1/system/mail', ['clear_password' => true], $h)->assertJsonPath('password_set', false);
    }

    public function test_sender_name_defaults_to_the_site_name(): void
    {
        // Ne panelde ne .env'de (MAIL_FROM_NAME) ad yok: sitenin adı.
        config(['app.name' => 'Kodmob', 'mail.from.name' => 'Kodmob']);
        MailSettings::apply();
        $this->assertSame(SiteManifest::siteName(), config('mail.from.name'));

        // .env'de verilmiş ad korunur; paneldeki ad ikisinin de üstündedir.
        config(['mail.from.name' => 'Şirket A.Ş.']);
        MailSettings::apply();
        $this->assertSame('Şirket A.Ş.', config('mail.from.name'));
        $this->putJson('/v1/system/mail', ['from_name' => 'Paneldeki Ad'], $this->admin())->assertOk();
        MailSettings::apply();
        $this->assertSame('Paneldeki Ad', config('mail.from.name'));
    }

    public function test_test_mail_is_sent_and_logged(): void
    {
        Mail::fake();
        $h = $this->admin();
        $this->postJson('/v1/system/mail/test', ['to' => 'deneme@kodmob.test'], $h)->assertOk()->assertJsonPath('sent', true)->assertJsonPath('log.0.kind', 'test');
        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('deneme@kodmob.test') && str_contains($m->subjectLine, 'Deneme'));
        $this->postJson('/v1/system/mail/test', ['to' => 'adres-degil'], $h)->assertStatus(422);
    }

    public function test_brief_mails_use_the_site_template(): void
    {
        Mail::fake();
        $this->admin();
        $this->postJson('/v1/briefs', [
            'name' => 'Ayşe Deneme', 'email' => 'ayse@example.com', 'services' => ['web'],
            'message' => 'Kurumsal web sitesi için teklif almak istiyoruz, teşekkürler.', 'consent' => true, 'locale' => 'en',
        ])->assertCreated();

        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('yonetici@kodmob.test') && $m->hasReplyTo('ayse@example.com')
            && str_contains($m->subjectLine, 'Yeni talep: Ayşe Deneme · Web Sitesi') && $m->action['url'] !== '');
        Mail::assertSent(SiteMail::class, fn (SiteMail $m) => $m->hasTo('ayse@example.com') && $m->subjectLine === 'We received your request · Kodmob'
            && $m->lines[0] === 'Hi Ayşe Deneme,' && $m->mailLocale === 'en');
        $this->assertSame(2, DB::table('mail_log')->where('status', 'sent')->count());

        // Şablon HTML'i ve düz metni: kaçışlı, site adıyla.
        $html = (new SiteMail('Konu', ['A & <b>'], facts: ['E-posta' => 'x@y.z']))->render();
        $this->assertStringContainsString('A &amp; &lt;b&gt;', $html);
        $this->assertStringContainsString('Kodmob', $html);
    }

    public function test_without_cron_jobs_run_after_the_response_with_cron_they_queue(): void
    {
        Mail::fake();
        config(['queue.default' => 'database']);
        $mail = fn () => new SendMail('a@kodmob.test', new SiteMail('Konu', ['Metin']), 'test');

        // Cron hiç çalışmadı: iş kuyruğa girmez, yanıt gönderildikten sonra çalışır.
        $this->assertFalse(Background::cronAlive());
        Background::dispatch($mail());
        $this->assertSame(0, DB::table('jobs')->count());
        Mail::assertNothingSent();
        $this->app->terminate();
        Mail::assertSent(SiteMail::class, 1);

        // Cron çalışıyor: kuyruğa girer, zamanlanmış görev boşaltır.
        Options::set('cron_heartbeat', Clock::now());
        $this->assertTrue(Background::cronAlive());
        Background::dispatch($mail());
        $this->assertSame(1, DB::table('jobs')->count());
        Artisan::call('queue:work', ['--stop-when-empty' => true]);
        $this->assertSame(0, DB::table('jobs')->count());
        Mail::assertSent(SiteMail::class, 2);

        $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();
        foreach (['cron:heartbeat', 'queue:drain', 'mail:prune'] as $task) {
            $this->assertContains($task, $names);
        }
    }

    public function test_failed_jobs_can_be_retried_or_removed(): void
    {
        $h = $this->admin();
        $payload = json_encode(['uuid' => 'u-1', 'displayName' => SendMail::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 3, 'data' => []]);
        foreach (['u-1', 'u-2'] as $uuid) {
            DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => str_replace('u-1', $uuid, $payload), 'exception' => "RuntimeException: SMTP yok\n#0 ...", 'failed_at' => now()]);
        }

        $this->getJson('/v1/system/jobs', $h)->assertOk()->assertJsonPath('failed', 2)
            ->assertJsonPath('failed_jobs.0.name', 'E-posta gönderimi')->assertJsonPath('failed_jobs.0.error', 'RuntimeException: SMTP yok')
            ->assertJsonPath('cron.alive', false);
        $this->postJson('/v1/system/jobs/u-1/retry', [], $h)->assertOk()->assertJsonPath('failed', 1);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->deleteJson('/v1/system/jobs/u-2', [], $h)->assertOk()->assertJsonPath('failed', 0);

        $editor = $this->as('editor');
        $this->getJson('/v1/system/jobs', $editor)->assertForbidden();
    }
}
