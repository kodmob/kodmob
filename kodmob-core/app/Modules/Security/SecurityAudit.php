<?php

namespace App\Modules\Security;

use App\Core\Security\Roles;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Auth\Services\Sessions;
use Illuminate\Support\Facades\DB;

/**
 * Güvenlik > Denetim: kurulumun bilinen zayıflıklarını tek tek kontrol eder.
 * Her madde `ok`, `warning` ya da `danger` döner ve ne yapılacağını söyler.
 * (kodmob-security eklentisindeki "Denetim" sayfasının karşılığı.)
 */
final class SecurityAudit
{
    /** Güvenlik özetinin sayıları (son 24 saat): Güvenlik > Genel bakış ve Sistem Sağlığı. */
    public static function stats(): array
    {
        $since = now()->subDay()->format('Y-m-d H:i:s');
        $count = static fn (array $types) => (int) DB::table('security_events')->whereIn('type', $types)->where('created_at', '>=', $since)->count();

        return [
            'sessions' => Sessions::count(),
            'users' => User::query()->where('status', User::ACTIVE)->count(),
            'failed_logins' => $count([SecurityLog::LOGIN_FAILED]),
            'blocked_requests' => $count([SecurityLog::WAF_BLOCKED, SecurityLog::IP_DENIED]),
            'ip_blocks' => DB::table('ip_blocks')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Clock::now()))->count(),
            'lockouts' => count((new LoginThrottle)->lockouts()),
        ];
    }

    /** Güvenlik puanı (0–100): denetimin maddeleri; tamam tam puan, uyarı yarım, kritik sıfır. */
    public static function score(): int
    {
        $checks = self::run();
        if (! $checks) {
            return 100;
        }
        $points = array_sum(array_map(static fn ($c) => ['ok' => 2, 'warning' => 1][$c['status']] ?? 0, $checks));

        return (int) round($points / (count($checks) * 2) * 100);
    }

    /** @return list<array{key:string,label:string,status:string,detail:string,fix:string}> */
    public static function run(): array
    {
        $checks = [];
        $add = static function (string $key, string $label, string $status, string $detail, string $fix = '') use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'detail', 'fix');
        };
        $production = app()->environment('production');

        $add('debug', 'Hata ayrıntıları kapalı', config('app.debug') ? ($production ? 'danger' : 'warning') : 'ok',
            config('app.debug') ? 'APP_DEBUG açık: hata sayfaları kod ve yapılandırma ayrıntısı gösterir.' : 'Hatalar ziyaretçiye ayrıntısız gösterilir.',
            config('app.debug') ? '.env dosyasında APP_DEBUG=false yapın.' : '');

        $add('env', 'Canlı ortam', $production ? 'ok' : 'warning',
            'APP_ENV='.app()->environment().'.', $production ? '' : 'Canlı sunucuda .env dosyasında APP_ENV=production olmalı.');

        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('https', 'HTTPS', $https ? 'ok' : ($production ? 'danger' : 'warning'),
            'APP_URL '.((string) config('app.url') ?: 'boş').'.', $https ? '' : 'SSL sertifikasını açın ve APP_URL\'yi https:// ile yazın.');

        $breached = SecurityModule::bool(SecurityModule::BREACHED_PASSWORDS, true);
        $add('breached', 'Sızmış şifre denetimi', $breached ? 'ok' : 'warning',
            $breached ? 'Veri sızıntılarında görülen şifreler kabul edilmez.' : 'Kapalı: sızmış bir şifre seçilebilir.', $breached ? '' : 'Güvenlik › Ayarlar\'dan "Sızmış şifreleri reddet"i açın.');
        $required = (string) (SettingsService::instance()->get(SecurityModule::REQUIRE_2FA) ?: 'off');
        $add('require_2fa', 'Zorunlu iki adımlı doğrulama', $required === 'off' ? 'warning' : 'ok',
            ['off' => 'Kapalı: kimse zorunlu değil.', 'admins' => 'Yöneticiler için zorunlu.', 'all' => 'Herkes için zorunlu.'][$required] ?? 'Kapalı.',
            $required === 'off' ? 'Güvenlik › Ayarlar\'dan en azından yöneticiler için açın.' : '');

        $hsts = SecurityModule::bool(SecurityModule::HSTS, false);
        $add('hsts', 'HSTS', $hsts ? 'ok' : 'warning',
            $hsts ? 'Tarayıcılar siteyi yalnızca HTTPS ile açar.' : 'Kapalı: ilk ziyaret HTTP ile yapılabilir.',
            $hsts || ! $https ? '' : 'SSL kalıcı çalışıyorsa Ayarlar sekmesinden HSTS\'yi açın.');

        $waf = SecurityModule::bool(SecurityModule::WAF, true);
        $add('waf', 'Saldırı kalıbı süzgeci', $waf ? 'ok' : 'warning',
            $waf ? 'SQL enjeksiyonu, XSS ve dosya yolu kalıpları engelleniyor.' : 'Kapalı.', $waf ? '' : 'Ayarlar sekmesinden açın.');

        $separate = (string) config('jwt.secret') !== '';
        $add('jwt_secret', 'Ayrı oturum imza anahtarı', $separate ? 'ok' : 'warning',
            $separate ? 'JWT_SECRET tanımlı.' : 'Oturum anahtarı APP_KEY\'den türetiliyor; APP_KEY değişirse herkesin oturumu düşer.',
            $separate ? '' : '.env dosyasına 64 karakterlik rastgele JWT_SECRET ekleyin.');

        $setup = (string) config('kodmob.setup_token') !== '';
        $add('setup_token', 'Kurulum ekranı kapalı', $setup ? 'warning' : 'ok',
            $setup ? 'SETUP_TOKEN dolu: kurulum ekranı jetonla açılabilir.' : 'Kurulum ekranı kapalı.',
            $setup ? 'Kurulum bittiyse .env dosyasındaki SETUP_TOKEN satırını silin (güncellemede yeniden ekleyebilirsiniz).' : '');

        $proxies = (string) config('kodmob.trusted_proxies');
        $add('proxies', 'Ziyaretçi IP\'si', $proxies === '*' ? 'warning' : 'ok',
            $proxies === '' ? 'Vekil sunucu başlıklarına güvenilmiyor.' : 'TRUSTED_PROXIES='.$proxies.'.',
            $proxies === '*' ? 'Cloudflare kullanmıyorsanız TRUSTED_PROXIES\'i silin; aksi halde IP uydurulup giriş kilidi atlatılabilir.' : '');

        $core = str_replace('\\', '/', base_path());
        $root = str_replace('\\', '/', rtrim(Paths::siteRoot(), '/\\'));
        $inside = $root !== '' && str_starts_with($core.'/', $root.'/');
        $add('core', 'Uygulama dosyaları web kökünün dışında', $inside ? 'warning' : 'ok',
            $inside ? 'Çekirdek public_html içinde; yalnızca .htaccess koruyor.' : 'Çekirdek (.env, kod) web kökünün dışında.',
            $inside ? 'kodmob-core klasörünü public_html\'in yanına taşıyın (OKUBENI.txt).' : '');

        $admins = User::query()->where('role', Roles::ADMIN)->where('status', User::ACTIVE)->count();
        $add('admins', 'Yönetici sayısı', $admins === 0 ? 'danger' : ($admins > 3 ? 'warning' : 'ok'),
            "{$admins} aktif Yönetici.", $admins > 3 ? 'Yönetici rolünü yalnızca gerekli kişilere verin; diğerlerine Editör ya da özel rol verin.' : '');

        $without = User::query()->where('role', Roles::ADMIN)->where('status', User::ACTIVE)->whereNull('two_factor_confirmed_at')->pluck('display_name')->all();
        $add('two_factor', 'Yöneticilerde iki adımlı doğrulama', $without ? 'warning' : 'ok',
            $without ? 'Kapalı: '.implode(', ', $without).'.' : 'Bütün yöneticilerde açık.',
            $without ? 'Profilim > İki adımlı doğrulama: şifre çalınsa bile telefondaki kod olmadan girilemez.' : '');

        $stale = User::query()->where('status', User::ACTIVE)
            ->where(fn ($q) => $q->whereNull('password_changed_at')->orWhere('password_changed_at', '<', now()->subYear()->format('Y-m-d H:i:s')))
            ->pluck('display_name')->all();
        $add('passwords', 'Şifre yaşı', $stale ? 'warning' : 'ok',
            $stale ? count($stale).' kullanıcının şifresi bir yıldan eski: '.implode(', ', array_slice($stale, 0, 5)) : 'Bütün şifreler son bir yıl içinde değişmiş.',
            $stale ? 'Bu kullanıcılardan Profilim > Şifre bölümünden şifrelerini yenilemelerini isteyin.' : '');

        $since = now()->subDay()->format('Y-m-d H:i:s');
        $failed = (int) DB::table('security_events')->where('type', SecurityLog::LOGIN_FAILED)->where('created_at', '>=', $since)->count();
        $add('failed_logins', 'Son 24 saatte hatalı giriş', $failed > 50 ? 'danger' : ($failed > 10 ? 'warning' : 'ok'),
            "{$failed} hatalı deneme.", $failed > 10 ? 'Güvenlik Kayıtları\'nda IP\'lere bakın; tekrarlayan IP\'yi engelleyin.' : '');

        $last = self::lastBackup();
        $add('backup', 'Yedek', $last && strtotime($last) > strtotime('-30 days') ? 'ok' : 'warning',
            $last ? 'Son yedek: '.$last.'.' : 'Panelden alınmış yedek yok.',
            'Ayarlar > Yedek bölümünden düzenli yedek indirin ve bilgisayarınızda saklayın.');

        $add('php', 'PHP sürümü', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'danger', 'PHP '.PHP_VERSION.'.',
            version_compare(PHP_VERSION, '8.3.0', '<') ? 'Hostinger panelinden PHP 8.3 ya da üstünü seçin.' : '');

        return $checks;
    }

    private static function lastBackup(): ?string
    {
        $row = DB::table('events')->where('event_type', 'backup.exported')->orderByDesc('created_at')->value('created_at');

        return $row ? Clock::local((string) $row) : null;
    }
}
