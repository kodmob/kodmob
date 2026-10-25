<?php

namespace App\Modules\System;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Jobs\Background;
use App\Core\Mail\MailLog;
use App\Core\Mail\MailSettings;
use App\Core\Mail\SiteMail;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ayarlar > E-posta (gönderim ayarları, deneme, son gönderimler) ve Ayarlar > Arka plan (cron,
 * kuyruk, başarısız işler). Yetki: km_manage_settings.
 */
final class BackgroundController extends Controller
{
    public function mail(): array
    {
        return MailSettings::view() + ['log' => MailLog::recent()];
    }

    public function saveMail(Request $request): array
    {
        return MailSettings::save($this->input($request)) + ['log' => MailLog::recent()];
    }

    /** Deneme e-postası (kuyruksuz, hemen): sonuç ya da sunucunun hata mesajı döner. */
    public function testMail(Request $request): array
    {
        $to = trim((string) ($this->input($request)['to'] ?? '')) ?: (string) $request->user()->email;
        if (! Text::isEmail($to)) {
            throw ValidationException::field('to', 'Geçerli bir e-posta adresi yazın.');
        }
        MailSettings::apply();
        $mail = new SiteMail(
            'Deneme e-postası · '.SiteManifest::siteName(),
            ['Bu e-posta panelden gönderilen bir denemedir. Size ulaştıysa sitenin e-posta ayarları çalışıyor: talep bildirimleri ve ziyaretçilere giden yanıtlar bu yoldan gider.'],
            facts: ['Yöntem' => (string) config('mail.default'), 'Gönderen' => trim(config('mail.from.name').' <'.config('mail.from.address').'>')],
            mailLocale: 'tr',
        );
        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            MailLog::record($to, $mail->subjectLine, 'test', false, $e->getMessage());
            throw new DomainException('km_mail_failed', 'Gönderilemedi: '.mb_substr($e->getMessage(), 0, 300), 422);
        }
        MailLog::record($to, $mail->subjectLine, 'test', true);

        return ['sent' => true, 'to' => $to, 'log' => MailLog::recent()];
    }

    public function jobs(): array
    {
        return Background::status() + ['failed_jobs' => Background::failed()];
    }

    public function retry(string $uuid): array
    {
        Background::retry($uuid);

        return $this->jobs();
    }

    public function forget(string $uuid): array
    {
        Background::forget($uuid);

        return $this->jobs();
    }
}
