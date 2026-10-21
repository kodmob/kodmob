<?php

namespace App\Core\Setup;

use App\Core\Exceptions\NotFoundException;
use App\Core\Sites\SiteRegistry;
use App\Core\Sites\Sites;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use App\Modules\Auth\Models\User;
use App\Modules\Content\Backup;
use App\Modules\I18n\I18nConfig;
use App\Modules\Seo\Services\SeoConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SSH'sız paylaşımlı hosting için kurulum ekranı: /api/v1/setup?token=SETUP_TOKEN
 *
 * - Her açılışta sunucu denetlenir ve tablolar oluşturulur/güncellenir
 *   (`migrate --force`; yeni sürüm yüklendikten sonra da aynı adres açılır).
 * - Henüz yönetici yoksa form: ilk yönetici + isteğe bağlı ilk içerik
 *   (pakette gelen storage/app/backups/ilk-icerik.json ve görselleri).
 * - Yönetici varsa yalnızca durum gösterilir; yönetici buradan eklenemez.
 *
 * Yalnızca `.env`'de SETUP_TOKEN (24+ karakter) doluysa açıktır; kurulumdan
 * sonra boşaltılması önerilir.
 */
final class SetupController
{
    public const SEED = 'ilk-icerik.json';

    public function __invoke(Request $request): Response
    {
        $expected = (string) config('kodmob.setup_token');
        $given = (string) ($request->input('token') ?: $request->query('token', ''));
        if (strlen($expected) < 24 || ! hash_equals($expected, $given)) {
            // Yanlış jeton denemeleri sınırlı (veritabanı/önbellek kurulmadan da çalışsın: dosya).
            if (self::tooManyAttempts((string) $request->ip())) {
                return response('Çok fazla deneme. Bir saat sonra yeniden deneyin.', 429)->header('Content-Type', 'text/plain; charset=UTF-8');
            }
            throw NotFoundException::message('İstenen kayıt ya da adres bulunamadı.');
        }
        @set_time_limit(300);

        $checks = $this->checks();
        $migrated = null;
        $error = null;
        $sites = [];
        if (! array_filter($checks, static fn ($c) => ! $c['ok'] && $c['required'])) {
            try {
                Artisan::call('migrate', ['--force' => true]);
                $migrated = trim(Artisan::output());
                // Sürüm güncellemesinde render.php'nin SEO ve dil yapılandırmaları yenilenir.
                SeoConfig::compile();
                I18nConfig::compile();
                // Çok site: ana sitenin kurulum adresi alt sitelerin tablolarını da günceller.
                if (Sites::isMain()) {
                    $sites = SiteRegistry::migrate();
                }
            } catch (\Throwable $e) {
                Log::error('[Kodmob kurulum] migrate: '.$e->getMessage());
                $error = 'Tablolar oluşturulamadı: '.$e->getMessage();
            }
        }

        $hasAdmin = $error === null && $migrated !== null && User::query()->where('role', User::ADMIN)->exists();
        $seed = Backup::dir().'/'.self::SEED;
        $canSeed = is_file($seed) && $migrated !== null && ! DB::table('contents')->exists();
        $result = null;

        if ($request->isMethod('post') && $migrated !== null && ! $hasAdmin) {
            [$result, $error] = $this->install($request, $canSeed ? $seed : null);
            $hasAdmin = $result !== null;
        }

        return response()->view('setup', [
            'token' => $given,
            'checks' => $checks,
            'migrated' => $migrated,
            'sites' => $sites,
            'error' => $error,
            'hasAdmin' => $hasAdmin,
            'canSeed' => $canSeed && ! $hasAdmin,
            'result' => $result,
            'old' => $request->only(['username', 'email', 'display_name']),
            'siteUrl' => rtrim((string) config('app.url'), '/'),
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    /** @return array{0:?array,1:?string} [sonuç, hata] */
    private function install(Request $request, ?string $seed): array
    {
        $username = trim((string) $request->input('username'));
        $email = mb_strtolower(trim((string) $request->input('email')));
        $name = trim((string) $request->input('display_name')) ?: $username;
        $password = (string) $request->input('password');

        if (! preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            return [null, 'Kullanıcı adı 3–60 karakter olmalı; harf, rakam, nokta, tire ve alt çizgi kullanılabilir.'];
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [null, 'Geçerli bir e-posta adresi yazın.'];
        }
        if (mb_strlen($password) < 10) {
            return [null, 'Şifre en az 10 karakter olmalı.'];
        }
        if ($password !== (string) $request->input('password_confirmation')) {
            return [null, 'Şifreler aynı değil.'];
        }

        $user = User::query()->create([
            'username' => $username,
            'email' => $email,
            'display_name' => mb_substr($name, 0, 120),
            'password' => $password,
            'role' => User::ADMIN,
            'status' => User::ACTIVE,
            'password_changed_at' => Clock::now(),
        ]);
        $result = ['admin' => $user->username, 'import' => null];

        if ($seed && $request->boolean('seed')) {
            Auth::setUser($user);
            try {
                $result['import'] = (new Backup)->import((array) json_decode((string) file_get_contents($seed), true));
            } catch (\Throwable $e) {
                Log::error('[Kodmob kurulum] ilk içerik: '.$e->getMessage(), ['exception' => $e]);

                return [$result, 'Yönetici oluşturuldu ama ilk içerik yüklenemedi: '.$e->getMessage().' Panelden Ayarlar > Yedekleme ile yeniden deneyebilirsiniz.'];
            }
        }

        return [$result, null];
    }

    /** Yanlış jeton: IP (özeti) başına saatte en çok 20 deneme; dosyada tutulur. */
    private static function tooManyAttempts(string $ip): bool
    {
        $file = storage_path('framework/setup-attempts.json');
        $hour = date('YmdH');
        $key = substr(hash('sha256', $ip.config('app.key')), 0, 16);
        $data = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
        $data = array_filter($data, static fn ($v) => ($v['h'] ?? '') === $hour);
        $count = (int) ($data[$key]['n'] ?? 0) + 1;
        $data[$key] = ['h' => $hour, 'n' => $count];
        @file_put_contents($file, json_encode($data), LOCK_EX);

        return $count > 20;
    }

    /** @return list<array{label:string,ok:bool,detail:string,required:bool}> */
    private function checks(): array
    {
        $checks = [];
        $add = static function (string $label, bool $ok, string $detail, bool $required = true) use (&$checks) {
            $checks[] = compact('label', 'ok', 'detail', 'required');
        };

        $add('PHP sürümü', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION.' (en az 8.2; 8.4 önerilir)');
        foreach (['pdo_mysql' => 'MySQL bağlantısı', 'mbstring' => 'Türkçe metin', 'openssl' => 'Şifreleme', 'fileinfo' => 'Dosya türü', 'gd' => 'Görsel işleme', 'curl' => 'Jarvis ve indirmeler'] as $ext => $why) {
            $add('PHP eklentisi: '.$ext, extension_loaded($ext), $why);
        }
        $add('WebP desteği', function_exists('imagewebp'), 'Görsellerin küçük boyutları WebP üretilir (yoksa asıl biçimde).', false);
        $add('APP_KEY', strlen((string) config('app.key')) > 20, '.env dosyasında dolu olmalı');
        foreach (['storage/framework' => storage_path('framework'), 'storage/logs' => storage_path('logs'), 'storage/app' => storage_path('app'), 'bootstrap/cache' => base_path('bootstrap/cache')] as $label => $dir) {
            $add('Yazılabilir: kodmob-core/'.$label, is_dir($dir) && is_writable($dir), $dir);
        }
        $media = Paths::mediaRoot();
        if (! is_dir($media)) {
            @mkdir($media, 0755, true);
        }
        $add('Yazılabilir: görsel klasörü', is_dir($media) && is_writable($media), $media);
        $add('Ön yüz (km-manifest.json)', is_file(Paths::manifest()), Paths::manifest().' — site dosyaları yüklenmeli', false);

        try {
            DB::connection()->getPdo();
            $add('Veritabanı bağlantısı', true, (string) config('database.connections.'.config('database.default').'.database'));
        } catch (\Throwable $e) {
            $add('Veritabanı bağlantısı', false, '.env içindeki DB_* değerlerini kontrol edin: '.mb_substr($e->getMessage(), 0, 160));
        }

        return $checks;
    }
}
