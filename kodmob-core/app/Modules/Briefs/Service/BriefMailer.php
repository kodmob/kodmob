<?php

namespace App\Modules\Briefs\Service;

use App\Core\Mail\SendMail;
use App\Core\Mail\SiteMail;
use App\Core\Settings\SettingsService;
use App\Core\Support\PanelUrl;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Briefs\BriefsModule;
use App\Modules\Briefs\Domain\Brief;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Talep e-postaları (sitenin e-posta şablonuyla, SiteMail):
 * - Yöneticiye bildirim (Türkçe; yanıtla → ziyaretçi; "Panelde aç" düğmesi).
 * - Ziyaretçiye otomatik yanıt (talebin dilinde). Metni ve hizmet adları sitenin sözlüğünden
 *   (kodmob-frontend/src/i18n → brief.reply, brief.services; SiteManifest).
 *
 * İkisi de arka planda gider (SendMail → Background): ziyaretçi SMTP'yi beklemez, gönderilemeyen
 * e-posta yeniden denenir ve talebi etkilemez. Sonuç Ayarlar > E-posta'daki gönderim kaydında.
 */
final class BriefMailer
{
    public function send(Brief $brief): void
    {
        $this->notifyAdmin($brief);
        $this->autoReply($brief);
    }

    /** Bildirim adresi: ayar, yoksa yöneticinin e-postası. */
    public static function recipient(): string
    {
        $configured = trim(SettingsService::instance()->string(BriefsModule::NOTIFY_EMAIL));
        if (Text::isEmail($configured)) {
            return $configured;
        }

        return (string) DB::table('users')->where('role', 'admin')->where('status', 'active')->orderBy('id')->value('email');
    }

    private function notifyAdmin(Brief $brief): void
    {
        $to = self::recipient();
        if ($to === '') {
            return;
        }
        $labels = SiteManifest::vocab(SubmitBrief::VOCAB);
        $services = implode(', ', array_map(static fn ($s) => $labels[$s] ?? $s, $brief->services()));
        $facts = [
            'Ad Soyad' => $brief->name(),
            'E-posta' => $brief->email(),
            'Telefon' => $brief->phone() ?? '—',
            'Şirket' => $brief->company() ?? '—',
            'Dil' => strtoupper($brief->locale()),
            'Hizmetler' => $services ?: '—',
            'Bütçe' => $brief->budget() ?? '—',
            'Süre' => $brief->timeline() ?? '—',
        ];
        if ($estimate = $brief->estimate()) {
            $facts['Hesaplayıcı tahmini'] = sprintf('%s – %s %s · %s', number_format($estimate['min'], 0, ',', '.'), number_format($estimate['max'], 0, ',', '.'), $estimate['currency'], $estimate['weeks']);
            if (! empty($estimate['items'])) {
                $facts['Seçimler'] = implode(', ', $estimate['items']);
            }
        }

        SendMail::to($to, new SiteMail(
            '['.SiteManifest::siteName().'] Yeni talep: '.$brief->name().($services !== '' ? ' · '.$services : ''),
            ['Yeni proje talebi (#'.$brief->id().'). Yanıtla ile doğrudan talep sahibine yazabilirsiniz.', $brief->message()],
            facts: $facts,
            action: ['label' => 'Panelde aç', 'url' => PanelUrl::absolute('briefs', ['id' => $brief->id()])],
            replyToAddress: $brief->email(),
            replyToName: $brief->name(),
            mailLocale: 'tr',
        ), 'brief.notify');
    }

    private function autoReply(Brief $brief): void
    {
        $subject = SiteManifest::string('briefReplySubject', $brief->locale());
        $body = SiteManifest::string('briefReplyBody', $brief->locale());
        if ($subject === '' || $body === '') {
            Log::warning('[Kodmob] Talep yanıtı gönderilmedi: sitenin derlemesinde brief.reply metni yok.');

            return;
        }
        $replyTo = self::recipient();
        SendMail::to($brief->email(), new SiteMail(
            $subject,
            SiteMail::paragraphs(str_replace('{name}', $brief->name(), $body)),
            replyToAddress: $replyTo !== '' ? $replyTo : null,
            mailLocale: $brief->locale(),
        ), 'brief.reply');
    }
}
