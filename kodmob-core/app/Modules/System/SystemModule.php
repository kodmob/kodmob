<?php

namespace App\Modules\System;

use App\Core\Activity\ActivityCatalog;
use App\Core\Health\Health;
use App\Core\Module;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Settings\SettingDefinition;
use App\Core\Sites\SiteRegistry;
use App\Core\Sites\Sites;
use App\Core\Support\Clock;
use App\Modules\Content\AutoBackup;
use App\Modules\History\HistoryRepository;
use App\Modules\Users\UserSearch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Bütün modüllerin kullandığı sistem altyapısı: ayarlar, olay defteri, işlem
 * kaydının okunur adları (ActivityCatalog), sistem uçları (SystemController) ve
 * Sistem Sağlığı (HealthController; Yedekleme ve Siteler kartları burada).
 */
final class SystemModule extends Module
{
    public function boot(): void
    {
        ActivityCatalog::register();
    }

    /** Kullanıcılar çekirdekte (modül sınıfı yok); aramaya buradan katılır. */
    public function search(): array
    {
        return [new UserSearch];
    }

    /** Sıradaki adımlar: e-posta denemesi (ayar ana projede) ve otomatik yedek. */
    /** Genel bakış: kişinin son işlemleri (İşlem Geçmişi, kendisi; herkes görür). */
    public function widgets(): array
    {
        return [
            ['key' => 'activity', 'label' => 'Son işlemlerim', 'icon' => 'history', 'type' => 'list', 'size' => 'md', 'order' => 70,
                'link' => ['route' => 'history', 'label' => 'Tümü'],
                'data' => static function (array $ctx) {
                    $rows = DB::table('events')->where('user_id', $ctx['user'])->orderByDesc('id')->limit(6)->get()->map(static fn ($r) => (array) $r)->all();

                    return ['empty' => 'Henüz işleminiz yok.', 'items' => array_map(static fn ($e) => [
                        'title' => (string) $e['title'], 'meta' => (string) ($e['subject']['label'] ?? ''), 'time' => $e['at'], 'url' => $e['subject']['url'] ?? null,
                    ], HistoryRepository::present($rows))];
                }],
        ];
    }

    public function steps(): array
    {
        $steps = [];
        if (Sites::isMain()) {
            $steps[] = ['key' => 'mail', 'order' => 50, 'label' => 'E-postayı deneyin', 'hint' => 'Talepler ve bildirimler e-postayla gider: Deneme gönder ile gelip gelmediğine bakın.',
                'done' => DB::table('mail_log')->where('status', 'sent')->exists(), 'route' => 'settings', 'tab' => 'mail', 'can' => Capability::MANAGE_SETTINGS];
        }
        $steps[] = ['key' => 'backup', 'order' => 60, 'label' => 'Otomatik yedeği açın', 'hint' => 'Her gün yedek; mümkünse uzak depoya da (şifreli).',
            'done' => (bool) AutoBackup::settings()['enabled'], 'route' => 'settings', 'tab' => 'backup', 'can' => Capability::MANAGE_BACKUPS];

        return $steps;
    }

    public function settings(): array
    {
        $g = 'Sistem sağlığı';

        return [
            new SettingDefinition(HealthReport::TO, 'string', (string) config('kodmob.health_report_to', ''), 'Rapor alıcısı', $g, 'Sistem Sağlığı › Bildir bu adrese gider (ör. sitenin teknik servisi). Boşsa gönderilmez.'),
            new SettingDefinition(HealthReport::MONTHLY, 'bool', false, 'Ayda bir kendiliğinden gönder', $g, 'Her ayın 1\'inde sağlık özeti rapor alıcısına gider. Yalnızca kartların özeti gider; içerik ya da kişisel veri gitmez.'),
        ];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => HealthReport::monthly())->name('health:monthly')->monthlyOn(1, '09:00');
    }

    public function health(): array
    {
        $sections = [$this->backupCard()];
        if (Sites::isMain() && Modules::enabled('sites') && ($sites = SiteRegistry::list())) {
            $sections[] = $this->sites($sites);
        }

        return $sections;
    }

    private function backupCard(): array
    {
        $s = AutoBackup::settings();
        $items = AutoBackup::list();
        $flags = [];
        if (! $s['enabled']) {
            $flags[] = Health::flag('warn', 'Otomatik yedek kapalı.', 'Ayarlar › Yedekleme\'den günlük yedeği açın; mümkünse uzak depoya da gönderin.');
        } elseif ($s['last_status'] === 'failed') {
            $flags[] = Health::flag('critical', 'Son otomatik yedek alınamadı: '.mb_substr((string) $s['last_error'], 0, 200));
        } elseif ($s['last_at'] && Clock::carbon((string) $s['last_at'])->diffInHours(Clock::carbon(Clock::now()), true) > ($s['frequency'] === 'weekly' ? 336 : 48)) {
            $flags[] = Health::flag('warn', 'Son otomatik yedek '.Clock::local((string) $s['last_at']).' tarihli: zamanında alınmıyor.', 'Cron\'un çalıştığını kontrol edin (Arka plan kartı).');
        }
        if ($s['remote'] && $s['passphrase'] === '') {
            $flags[] = Health::flag('critical', 'Uzak depo açık ama yedek şifresi yok: yedek uzak depoya gönderilmiyor.', 'Ayarlar › Yedekleme\'de yedek şifresi girin (uzak depoya giden yedek şifrelenir).');
        }
        if ($s['enabled'] && ! $s['remote']) {
            $flags[] = Health::flag('info', 'Yedekler yalnızca bu sunucuda: sunucuyla birlikte kaybolabilir.', 'Uzak depoyu (S3 uyumlu) açın.');
        }

        return Health::section('backup', 'Yedekleme', 'download', [
            ['Otomatik yedek', $s['enabled'] ? ($s['frequency'] === 'weekly' ? 'Haftada bir' : 'Her gün').' · son '.$s['keep'].' saklanır' : 'Kapalı'],
            ['Son yedek', $s['last_at'] ? Clock::local((string) $s['last_at']).' · '.($s['last_status'] === 'failed' ? 'hata' : 'tamam') : 'Yok'],
            ['Uzak depo', $s['remote'] ? ($s['passphrase'] !== '' ? 'Açık · şifreli' : 'Açık · şifre yok') : 'Kapalı'],
            ['Saklanan', count($items).' yedek · '.Health::mb(array_sum(array_column($items, 'size')))],
        ], $flags, ['link' => ['route' => 'settings', 'tab' => 'backup', 'label' => 'Yedekleme'], 'order' => 70]);
    }

    /** @param  list<array<string,mixed>>  $sites */
    private function sites(array $sites): array
    {
        $flags = [];
        foreach ($sites as $site) {
            $st = $site['status'];
            if ($st['error'] !== '') {
                $flags[] = Health::flag('critical', $site['name'].': '.$st['error']);
            } elseif ($st['pending'] > 0) {
                $flags[] = Health::flag('warn', $site['name'].': '.$st['pending'].' tablo güncellemesi bekliyor.', 'Siteler › Tabloları güncelle.');
            } elseif ($st['admins'] === 0) {
                $flags[] = Health::flag('warn', $site['name'].': aktif yöneticisi yok.');
            }
        }

        return Health::section('sites', 'Alt siteler', 'layers', [
            ['Alt site', (string) count($sites)],
            ['Hazır', (string) count(array_filter($sites, static fn ($s) => $s['status']['ok']))],
        ], $flags, ['link' => ['route' => 'sites', 'tab' => null, 'label' => 'Siteler'], 'order' => 80]);
    }
}
