<?php

namespace App\Core\Http;

use App\Core\Exceptions\DomainException;
use App\Core\Support\ErrorJournal;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException as LaravelValidation;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Bütün hataları tek biçime çevirir: `{ code, message, details }` (tv-core'daki
 * ResourceController ile aynı). Kodlar ön yüzün hata haritasındaki `km_*`
 * anahtarlarıdır. Beklenmeyen hatalar günlüğe yazılır, istemciye genel metin ve
 * hata kodu gider (`details.ref`; ayrıntısı ErrorJournal'da); ayrıntı yalnızca
 * APP_DEBUG açıkken `details.debug` altındadır.
 */
final class ErrorRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->dontReport([DomainException::class]);
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (Throwable $e) => self::render($e));
    }

    public static function render(Throwable $e): JsonResponse
    {
        [$status, $code, $message, $details] = match (true) {
            $e instanceof DomainException => [$e->status(), $e->errorCode(), $e->getMessage(), $e->details()],
            $e instanceof LaravelValidation => [422, 'km_validation_failed', 'Formdaki hatalı alanları düzeltin.', ['fields' => array_map(fn (array $m) => $m[0], $e->errors())]],
            $e instanceof AuthenticationException => [401, 'km_unauthenticated', 'Oturumunuz kapanmış. Lütfen yeniden giriş yapın.', []],
            $e instanceof ThrottleRequestsException => [429, 'km_rate_limited', 'Çok fazla istek gönderildi. Biraz bekleyip tekrar deneyin.', ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60)]],
            $e instanceof PostTooLargeException => [413, 'km_upload_too_large', 'Gönderilen dosya sunucunun izin verdiği boyuttan büyük.', []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'km_not_found', 'İstenen kayıt ya da adres bulunamadı.', []],
            $e instanceof MethodNotAllowedHttpException => [405, 'km_method_not_allowed', 'Bu adres bu istek türünü desteklemiyor.', []],
            $e instanceof QueryException => [500, 'km_database_error', 'Kayıt sırasında bir veritabanı hatası oluştu. İşlem geri alındı, tekrar deneyin.', []],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http_'.$e->getStatusCode(), $e->getMessage() ?: 'İstek işlenemedi.', []],
            default => [500, 'km_internal_error', 'Beklenmeyen bir hata oluştu. İşlem kaydedilmedi, tekrar deneyin.', []],
        };

        // Beklenmeyen hata: kod alır, mesajda yazar; ayrıntısı Sistem Sağlığı › Hata kodları'nda (ErrorJournal).
        if (in_array($code, ['km_database_error', 'km_internal_error'], true)) {
            $request = request();
            $details['ref'] = ErrorJournal::record($e, $request->user()?->getAuthIdentifier(), $request->method().' '.$request->path());
            $message .= ' Hata kodu: '.$details['ref'];
        }

        if ($status >= 500 && config('app.debug')) {
            $details['debug'] = ['exception' => $e::class, 'message' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()];
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse(['code' => $code, 'message' => $message, 'details' => (object) $details], $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
