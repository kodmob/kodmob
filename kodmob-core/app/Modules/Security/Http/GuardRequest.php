<?php

namespace App\Modules\Security\Http;

use App\Core\Exceptions\DomainException;
use App\Modules\Security\Firewall;
use App\Modules\Security\SecurityLog;
use App\Modules\Security\SecurityModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Her API isteğinin ilk kapısı: engelli IP → 403; ziyaretçi isteğinin adres ve
 * sorgu dizesinde saldırı kalıbı → 403 + güvenlik kaydı (+ eşik aşılırsa
 * otomatik IP engeli). Yanıta güvenlik başlıkları eklenir (HSTS ayara bağlı).
 *
 * Panel uçları (oturum gerektirenler) kalıp süzgecinden geçmez: arama kutusuna
 * yazılan "../" gibi metinler yönetici işini engellemesin. IP engeli herkese uygulanır.
 */
final class GuardRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        if (($blockId = Firewall::blockedBy($ip)) !== null) {
            Firewall::hit($blockId);
            SecurityLog::record(SecurityLog::IP_DENIED, $request, $request->method().' '.mb_substr($request->getRequestUri(), 0, 200));
            throw new DomainException('km_ip_blocked', 'Bu adresten erişim engellendi.', 403);
        }

        if (! $this->isPanel($request) && SecurityModule::bool(SecurityModule::WAF, true)) {
            $pattern = Firewall::match($request->getRequestUri());
            if ($pattern !== null) {
                SecurityLog::record(SecurityLog::WAF_BLOCKED, $request, Firewall::PATTERN_LABELS[$pattern].' · '.mb_substr($request->getRequestUri(), 0, 300));
                Firewall::autoBlock($ip);
                throw new DomainException('km_request_blocked', 'İstek güvenlik nedeniyle engellendi.', 403);
            }
        }

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        if ($request->isSecure() && SecurityModule::bool(SecurityModule::HSTS, false)) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function isPanel(Request $request): bool
    {
        $route = $request->route();

        return $route && in_array('auth.jwt', $route->gatherMiddleware(), true);
    }
}
