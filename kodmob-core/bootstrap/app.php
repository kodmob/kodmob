<?php

use App\Core\Activity\LogActivity;
use App\Core\Http\ErrorRenderer;
use App\Core\Http\Middleware\ApiResponse;
use App\Core\Http\Middleware\RequireModule;
use App\Core\Http\Middleware\ThrottleRequests;
use App\Core\Sites\SiteContext;
use App\Modules\Auth\Http\Middleware\AuthenticateJwt;
use App\Modules\Auth\Http\Middleware\RequireCapability;
use App\Modules\Security\Http\GuardRequest;
use App\Modules\Security\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

/*
 * API öneki boştur: canlıda Laravel `public_html/api/` altında çalışır ve
 * `/api` kısmını Apache (SCRIPT_NAME) zaten düşer; rota `/v1/...` olarak
 * görülür. Ön yüz `/api/v1/...` adresini kullanır (kodmob-frontend/.env*: VITE_BASE_URL=/api).
 *
 * Çok site: site, Laravel açılmadan alan adından ya da KODMOB_SITE'tan seçilir (giriş dosyaları
 * bunu daha önce yapmış olabilir; komut satırı ve testler burada).
 */
SiteContext::boot(dirname(__DIR__));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Girdi olduğu gibi gelir (WordPress'teki gibi): boş metin "sözlükteki hale dön"
        // demektir (sayfa metinleri), şifre kırpılmaz. Temizlik alanlarda yapılır (Content\Fields, Text).
        $middleware->remove([ConvertEmptyStringsToNull::class, TrimStrings::class]);
        // Her API isteği: JSON yanıt biçimi, sonra IP engeli + saldırı kalıbı süzgeci + güvenlik başlıkları.
        $middleware->api(prepend: [ApiResponse::class, GuardRequest::class]);
        $middleware->alias([
            'auth.jwt' => AuthenticateJwt::class,
            'cap' => RequireCapability::class,
            'module' => RequireModule::class,
            // Zorunlu iki adımlı doğrulama (Güvenlik › Ayarlar): açılana kadar yalnızca Profilim.
            'two_factor' => RequireTwoFactor::class,
            'activity' => LogActivity::class,
            // İstek sınırı yol başına (Laravel'inki kullanıcı başına tek sayaç).
            'throttle' => ThrottleRequests::class,
        ]);
        // Vekil sunucu ayarı (TRUSTED_PROXIES) AppServiceProvider'da config'ten okunur.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ErrorRenderer::register($exceptions);
    })->create();
