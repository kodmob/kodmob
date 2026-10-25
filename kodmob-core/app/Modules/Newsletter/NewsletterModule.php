<?php

namespace App\Modules\Newsletter;

use App\Core\Database\BackupPart;
use App\Core\Events\EventRecorded;
use App\Core\Health\Health;
use App\Core\Jobs\Background;
use App\Core\Module;
use App\Core\Settings\SettingDefinition;
use App\Core\Support\PanelUrl;
use App\Modules\Notifications\NotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;

/**
 * Bülten (isteğe bağlı araç `newsletter`; docs/done-bulten-raporu.md): sitede Bülten bloğu ve sayfası (çift onaylı
 * abonelik, abonelikten çıkma), panelde aboneler ve bültenler (yaz, dene, gönder; saatlik sınırla parça parça).
 */
final class NewsletterModule extends Module
{
    public const MANAGE = 'km_manage_newsletter';

    public const HOURLY_LIMIT = 'newsletter.hourly_limit';

    public function capabilities(): array
    {
        return [
            self::MANAGE => ['label' => 'Bülten: aboneleri görme ve bülten gönderme', 'group' => 'Bülten'],
        ];
    }

    /** Aboneler onay kayıtlarıyla (tarih, IP özeti): onayın kanıtı yedekte kalsın. */
    public function backup(): array
    {
        return [new BackupPart('newsletter', 'Bülten aboneleri ve bültenler', ['newsletter_subscribers', 'newsletter_campaigns'], personal: true)];
    }

    /** Genel bakış: aboneler ve son bülten. */
    public function widgets(): array
    {
        return [
            ['key' => 'newsletter', 'label' => 'Bülten', 'icon' => 'send', 'type' => 'stats', 'size' => 'sm', 'order' => 40, 'can' => self::MANAGE,
                'link' => ['route' => 'newsletter', 'label' => 'Bülten'],
                'data' => static function () {
                    $count = static fn (string $status) => DB::table('newsletter_subscribers')->where('status', $status)->count();
                    $week = now()->subDays(7)->format('Y-m-d H:i:s');

                    return ['items' => [
                        ['label' => 'Onaylı abone', 'value' => $count(Subscribers::ACTIVE), 'route' => 'newsletter'],
                        ['label' => 'Son 7 günde', 'value' => DB::table('newsletter_subscribers')->where('status', Subscribers::ACTIVE)->where('created_at', '>=', $week)->count(), 'sub' => 'yeni abone'],
                    ]];
                }],
        ];
    }

    public function settings(): array
    {
        return [
            new SettingDefinition(self::HOURLY_LIMIT, 'int', 100, 'Saatlik gönderim sınırı', 'Bülten',
                'Bir saatte en çok bu kadar bülten e-postası gider; fazlası sonraki saate kalır. Barındırma firmanızın e-posta sınırının altında tutun (onay e-postaları sayılmaz).', 1, 10000, 'e-posta', scope: 'project'),
        ];
    }

    public function events(): array
    {
        $subject = static fn ($p) => (string) ($p['subject'] ?? '');

        return [
            'newsletter.confirmed' => ['Yeni bülten abonesi', 'success', static fn ($p) => (string) ($p['email'] ?? '')],
            'newsletter.unsubscribed' => ['Bülten aboneliği sonlandı', 'neutral', static fn ($p) => (string) ($p['email'] ?? '')],
            'newsletter.campaign_sent' => ['Bülten gönderildi', 'success', static fn ($p) => sprintf('%s · %d kişi', $p['subject'] ?? '', $p['sent'] ?? 0).(! empty($p['failed']) ? sprintf(' · %d başarısız', $p['failed']) : '')],
            'newsletter.campaign_stopped' => ['Bülten gönderimi durdu', 'danger', static fn ($p) => trim(($p['subject'] ?? '').' · '.($p['error'] ?? ''), ' ·')],
            // Panel işlemleri (rota adları; İşlem Geçmişi)
            'newsletter.subscriber_added' => ['Bülten abonesi eklendi (onay e-postası gitti)', 'success', static fn ($p) => (string) ($p['email'] ?? '')],
            'newsletter.subscriber_deleted' => ['Bülten abonesi silindi', 'danger', static fn ($p) => (string) ($p['email'] ?? '')],
            'newsletter.campaign_created' => ['Bülten taslağı eklendi', 'success', $subject],
            'newsletter.campaign_saved' => ['Bülten güncellendi', 'primary', $subject],
            'newsletter.campaign_deleted' => ['Bülten silindi', 'danger', $subject],
            'newsletter.campaign_started' => ['Bülten gönderimi başladı', 'primary', static fn ($p) => sprintf('%s · %d kişi', $p['subject'] ?? '', $p['total'] ?? 0)],
            'newsletter.campaign_paused' => ['Bülten duraklatıldı', 'warning', $subject],
            'newsletter.campaign_tested' => ['Bülten denemesi gönderildi', 'neutral', static fn ($p) => trim(($p['subject'] ?? '').' → '.($p['email'] ?? ''), ' →')],
        ];
    }

    public function boot(): void
    {
        Events::listen(EventRecorded::class, static function (EventRecorded $recorded) {
            $event = $recorded->event;
            if (! in_array($event->type, ['newsletter.campaign_sent', 'newsletter.campaign_stopped'], true)) {
                return;
            }
            $sent = $event->type === 'newsletter.campaign_sent';
            NotificationService::notifyCapable(
                self::MANAGE,
                $event->type,
                ($sent ? 'Bülten gönderildi: ' : 'Bülten gönderimi durdu: ').($event->payload['subject'] ?? ''),
                $sent ? sprintf('%d kişiye gitti.', $event->payload['sent'] ?? 0) : 'E-posta ayarlarını denetleyip bültende "Devam et"e basın. '.($event->payload['error'] ?? ''),
                PanelUrl::to('newsletter', ['tab' => 'campaigns']),
                $sent ? 'success' : 'danger',
                ['campaign_id' => $event->subjectId]
            );
        });
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => (new Campaigns)->tick())->name('newsletter:send')->everyMinute()->withoutOverlapping(5);
        $schedule->call(static fn () => (new Subscribers)->purgePending())->name('newsletter:purge')->dailyAt('03:20');
    }

    public function health(): array
    {
        $count = static fn (string $status) => DB::table('newsletter_subscribers')->where('status', $status)->count();
        $sending = DB::table('newsletter_campaigns')->where('status', Campaigns::SENDING)->count();
        $stopped = DB::table('newsletter_campaigns')->where('status', Campaigns::PAUSED)->whereNotNull('error')->count();
        $flags = [];
        if ($sending && ! Background::cronAlive()) {
            $flags[] = Health::flag('warn', 'Bülten gönderiliyor ama cron çalışmıyor: gönderim yalnızca paneldeki Bülten sayfası açıkken ilerler.', 'Hosting › Cron Jobs: her dakika php …/kodmob-core/artisan schedule:run');
        }
        if ($stopped) {
            $flags[] = Health::flag('warn', 'E-posta hatası yüzünden duran bülten var.', 'Sistem Ayarları › E-posta: ayarı deneyin, sonra Bülten › Bültenler\'de "Devam et".');
        }

        return [Health::section('newsletter', 'Bülten', 'mail', [
            ['Onaylı abone', (string) $count(Subscribers::ACTIVE)],
            ['Onay bekleyen', (string) $count(Subscribers::PENDING)],
            ['Gönderilmekte olan bülten', (string) $sending],
            ['Saatlik sınır', SendQuota::limit().' e-posta'],
        ], $flags, ['link' => ['route' => 'newsletter', 'label' => 'Bülten']])];
    }
}
