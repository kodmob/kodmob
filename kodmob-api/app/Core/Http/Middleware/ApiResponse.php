<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bütün API yanıtları: JSON Türkçe karakterleri kaçırmadan yazılır, yanıtlar
 * önbelleğe alınmaz (panel verisi kullanıcıya özeldir), tarayıcı türü tahmin etmez.
 */
final class ApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! $response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
