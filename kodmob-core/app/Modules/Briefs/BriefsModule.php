<?php

namespace App\Modules\Briefs;

use App\Core\Database\BackupPart;
use App\Core\Module;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsService;
use App\Modules\Briefs\Service\PurgeBriefs;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Proje brifi ve iletişim talepleri: kayıt, spam koruması, bildirim ve
 * otomatik yanıt e-postaları, panelde "Talepler" ekranı.
 */
final class BriefsModule extends Module
{
    public const NOTIFY_EMAIL = 'brief.notify_email';

    public const RETENTION_MONTHS = 'brief.retention_months';

    public function backup(): array
    {
        return [new BackupPart('briefs', 'Talepler (iletişim ve teklif formları)', ['briefs'], personal: true)];
    }

    public function settings(): array
    {
        return [
            new SettingDefinition(self::NOTIFY_EMAIL, 'string', '', 'Bildirim e-postası', 'Talepler',
                'Yeni talepler bu adrese gelir. Boşsa yönetici hesabının e-postası kullanılır.'),
            new SettingDefinition(self::RETENTION_MONTHS, 'int', 24, 'Saklama süresi', 'Talepler',
                'Talepler bu süre sonunda her gün otomatik ve kalıcı olarak silinir. Gizlilik metinlerinde bu süre yazar (kaydedince sitede hemen güncellenir).', 1, 120, 'ay'),
        ];
    }

    public function legal(string $locale): array
    {
        return ['retention' => ['briefs' => SettingsService::instance()->int(self::RETENTION_MONTHS)]];
    }

    public function search(): array
    {
        return [new BriefSearch];
    }

    public function events(): array
    {
        return [
            'brief.received' => ['Yeni talep', 'success', static fn ($p) => sprintf('%s · %s', $p['name'] ?? '', implode(', ', (array) ($p['services'] ?? [])))],
            'brief.spam_blocked' => ['Spam talep engellendi', 'warning'],
            'brief.purged' => ['Süresi dolan talepler silindi', 'neutral', static fn ($p) => sprintf('%d talep · %d ay', $p['count'] ?? 0, $p['months'] ?? 0)],
            'brief.deleted' => ['Talep kalıcı olarak silindi', 'warning', static fn ($p) => '#'.($p['id'] ?? '')],
        ];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => (new PurgeBriefs)->expired())->name('briefs:purge')->dailyAt('03:10');
    }
}
