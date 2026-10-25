<?php

namespace App\Modules\System;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Health\Health;
use App\Core\Mail\MailLog;
use App\Core\Mail\MailSettings;
use App\Core\Mail\SiteMail;
use App\Core\Settings\SettingsService;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sistem Sağlığı › Bildir: sağlık özetini teknik servise e-postayla gönderir (elle ya da ayda bir).
 * Alıcı ve aylık gönderim Ayarlar'da görünür (`health.report_to`, `health.report_monthly`); geliştirici
 * müşteri kurulumunda `.env` HEALTH_REPORT_TO ile önceden doldurabilir. Rapor
 * gizli değil, kapatılabilir. Yalnızca kartların özeti gider; içerik, talep ya da kişisel veri gitmez.
 */
final class HealthReport
{
    public const TO = 'health.report_to';

    public const MONTHLY = 'health.report_monthly';

    public static function recipient(): string
    {
        return trim(SettingsService::instance()->string(self::TO));
    }

    /** Kuyruksuz, hemen: gönderilemezse sunucunun hatası döner (Bildir düğmesi). */
    public static function send(): string
    {
        $to = self::recipient();
        if (! Text::isEmail($to)) {
            throw ValidationException::field('to', 'Önce Ayarlar › Sistem sağlığı\'nda rapor alıcısının e-posta adresini yazın.');
        }
        MailSettings::apply();
        $mail = self::mail(Health::report());
        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            MailLog::record($to, $mail->subjectLine, 'health', false, $e->getMessage());
            throw new DomainException('km_mail_failed', 'Gönderilemedi: '.mb_substr($e->getMessage(), 0, 300), 422);
        }
        MailLog::record($to, $mail->subjectLine, 'health', true);

        return $to;
    }

    /** Zamanlanmış (ayın 1'i): açıksa ve alıcı varsa gönderir; hata günlüğe ve gönderim kaydına düşer. */
    public static function monthly(): void
    {
        if (SettingsService::instance()->bool(self::MONTHLY) && Text::isEmail(self::recipient())) {
            try {
                self::send();
            } catch (Throwable) {
                // Kayıt send() içinde; bir sonraki ay yeniden denenir.
            }
        }
    }

    /** @param  array{generated_at:string,level:string,sections:list<array<string,mixed>>}  $report */
    public static function mail(array $report): SiteMail
    {
        $levels = ['ok' => 'iyi durumda', 'warn' => 'dikkat gerekiyor', 'critical' => 'kritik bulgu var'];
        $lines = ['Kurulumun sistem sağlığı özeti: '.($levels[$report['level']] ?? $report['level']).'.'];
        $findings = [];
        foreach ($report['sections'] as $s) {
            foreach ($s['flags'] as $f) {
                if ($f['level'] !== 'info') {
                    $findings[] = ($f['level'] === 'critical' ? '[Kritik] ' : '[Dikkat] ').$s['title'].': '.$f['text'].($f['fix'] ? ' → '.$f['fix'] : '');
                }
            }
        }
        $lines[] = $findings ? "Bulgular:\n".implode("\n", $findings) : 'Dikkat gerektiren bulgu yok.';
        foreach ($report['sections'] as $s) {
            $lines[] = $s['title']."\n".implode("\n", array_map(static fn ($r) => $r['label'].': '.$r['value'], $s['rows']));
        }

        return new SiteMail(
            'Sistem sağlığı · '.SiteManifest::siteName().' · '.($levels[$report['level']] ?? ''),
            $lines,
            facts: ['Adres' => (string) config('app.url'), 'Sürüm' => 'Kodmob '.config('kodmob.version'), 'Zaman' => $report['generated_at']],
            mailLocale: 'tr',
        );
    }
}
