<?php

namespace App\Modules\Security;

use App\Core\Health\Health;
use App\Core\Module;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsService;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Güvenlik (WordPress'teki kodmob-security + jwt-kodmob ayarlarının karşılığı):
 * oturum süreleri, giriş kilidi, IP engelleri, saldırı kalıbı süzgeci (WAF),
 * güvenlik başlıkları ve güvenlik olay kaydı. Ayarları panelde Güvenlik >
 * Ayarlar sekmesindedir (`km_manage_security`).
 */
final class SecurityModule extends Module
{
    public const GROUP = 'Güvenlik';

    public const ACCESS_TTL = 'security.access_ttl_minutes';

    public const REFRESH_TTL = 'security.refresh_ttl_days';

    public const LOGIN_ATTEMPTS = 'security.login_max_attempts';

    public const LOCKOUT_MINUTES = 'security.login_lockout_minutes';

    public const IP_LOGIN_LIMIT = 'security.ip_login_limit';

    public const PASSWORD_MIN = 'security.password_min_length';

    public const WAF = 'security.waf';

    public const AUTO_BLOCK = 'security.auto_block_threshold';

    public const AUTO_BLOCK_HOURS = 'security.auto_block_hours';

    public const HSTS = 'security.hsts';

    public const RETENTION_DAYS = 'security.log_retention_days';

    /** Sistem Sağlığı: denetimin uyarıları bulgu olur, son 24 saatin sayıları satır. */
    public function health(): array
    {
        $audit = SecurityAudit::run();
        $stats = SecurityAudit::stats();
        $by = array_count_values(array_column($audit, 'status'));
        $flags = [];
        foreach ($audit as $check) {
            if ($check['status'] !== 'ok') {
                $flags[] = Health::flag($check['status'] === 'danger' ? 'critical' : 'warn', $check['label'].': '.$check['detail'], $check['fix']);
            }
        }

        return [Health::section('security', 'Güvenlik', 'shield-check', [
            ['Denetim', ($by['ok'] ?? 0).' tamam · '.($by['warning'] ?? 0).' uyarı · '.($by['danger'] ?? 0).' kritik'],
            ['Açık oturum', $stats['sessions'].' cihaz · '.$stats['users'].' aktif kullanıcı'],
            ['Hatalı giriş (24 sa)', (string) $stats['failed_logins']],
            ['Engellenen istek (24 sa)', (string) $stats['blocked_requests']],
            ['IP engeli · kilitli giriş', $stats['ip_blocks'].' · '.$stats['lockouts']],
        ], $flags, ['link' => ['route' => 'security', 'tab' => null, 'label' => 'Güvenlik'], 'order' => 20])];
    }

    public function settings(): array
    {
        $g = self::GROUP;

        return [
            new SettingDefinition(self::ACCESS_TTL, 'int', max(5, (int) config('jwt.access_ttl_minutes', 60)), 'Erişim jetonu süresi', $g,
                'Panel bu süre dolunca jetonu sessizce yeniler. Kısa süre, çalınan jetonun ömrünü kısaltır.', 5, 1440, 'dk'),
            new SettingDefinition(self::REFRESH_TTL, 'int', max(1, (int) config('jwt.refresh_ttl_days', 30)), 'Oturum süresi', $g,
                'Bu süre boyunca hiç açılmayan cihazın oturumu kapanır. Kullanıldıkça uzar.', 1, 365, 'gün'),
            new SettingDefinition(self::LOGIN_ATTEMPTS, 'int', max(3, (int) config('jwt.login_max_attempts', 5)), 'Hatalı giriş hakkı', $g,
                'Aynı kullanıcı adı ve IP ile bu kadar hatalı denemeden sonra giriş kilitlenir.', 3, 20, 'deneme'),
            new SettingDefinition(self::LOCKOUT_MINUTES, 'int', max(1, (int) config('jwt.login_lockout_minutes', 15)), 'Kilit süresi', $g,
                'Kilitlenen giriş bu süre sonunda kendiliğinden açılır; Güvenlik > Kilitli girişler\'den hemen açılabilir.', 1, 1440, 'dk'),
            new SettingDefinition(self::IP_LOGIN_LIMIT, 'int', 30, 'IP başına hatalı giriş sınırı', $g,
                'Bir IP bir saatte farklı kullanıcı adlarıyla bu kadar hatalı deneme yaparsa o IP\'nin girişi bir saat kapanır. 0: kapalı.', 0, 1000, 'deneme'),
            new SettingDefinition(self::PASSWORD_MIN, 'int', 10, 'En kısa şifre', $g,
                'Yeni kullanıcıda ve şifre değişiminde geçerlidir; var olan şifreler etkilenmez.', 8, 64, 'karakter'),
            new SettingDefinition(self::WAF, 'bool', true, 'Saldırı kalıbı süzgeci (WAF)', $g,
                'Ziyaretçi isteklerinde SQL enjeksiyonu, XSS ve dosya yolu saldırısı kalıplarını engeller. Panel içerik kayıtları süzülmez.'),
            new SettingDefinition(self::AUTO_BLOCK, 'int', 20, 'Otomatik IP engeli eşiği', $g,
                'Bir IP bir saatte bu kadar saldırı kalıbı ya da hatalı giriş üretirse geçici olarak engellenir. 0: kapalı.', 0, 1000, 'olay'),
            new SettingDefinition(self::AUTO_BLOCK_HOURS, 'int', 24, 'Otomatik engel süresi', $g,
                'Otomatik engellenen IP bu süre sonunda açılır.', 1, 720, 'saat'),
            new SettingDefinition(self::HSTS, 'bool', false, 'HSTS (yalnızca HTTPS)', $g,
                'Tarayıcıya siteyi bir yıl boyunca yalnızca HTTPS ile açmasını söyler. SSL kalıcı olarak çalışmıyorsa açmayın.'),
            new SettingDefinition(self::RETENTION_DAYS, 'int', 90, 'Güvenlik kaydı saklama süresi', $g,
                'Güvenlik olayları (hatalı giriş, engellenen istek) bu süreden sonra her gün silinir. İşlem Geçmişi etkilenmez.', 7, 730, 'gün'),
        ];
    }

    public function legal(string $locale): array
    {
        return ['retention' => ['security' => SettingsService::instance()->int(self::RETENTION_DAYS)]];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => SecurityLog::prune(SettingsService::instance()->int(self::RETENTION_DAYS)))
            ->name('security:prune')->dailyAt('03:30');
    }

    /** Ayar değeri; kurulumdan önce (tablo yokken) varsayılan. */
    public static function int(string $key, int $fallback): int
    {
        try {
            return SettingsService::instance()->int($key);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public static function bool(string $key, bool $fallback): bool
    {
        try {
            return SettingsService::instance()->bool($key);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
