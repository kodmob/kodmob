<?php

namespace App\Modules\Newsletter\Http;

use App\Core\Http\Controller;
use App\Modules\Newsletter\Subscribers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sitedeki bülten (`module:newsletter`, herkese açık; ArchitectureTest PUBLIC_ROUTES).
 *
 *   POST /v1/newsletter              { email, name?, locale, consent, source, website, started_at }  onay e-postası
 *   POST /v1/newsletter/confirm      { token }   onay bağlantısı (sitenin bülten sayfası POST eder)
 *   POST /v1/newsletter/unsubscribe  { token } ya da ?token=  abonelikten çık; e-posta istemcisinin tek tık
 *                                    düğmesi (List-Unsubscribe-Post, RFC 8058) aynı adrese gelir
 */
final class NewsletterController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        return $this->created((new Subscribers)->subscribe($this->input($request), (string) $request->ip()));
    }

    public function confirm(Request $request): array
    {
        return (new Subscribers)->confirm($this->token($request));
    }

    public function unsubscribe(Request $request): array
    {
        return (new Subscribers)->unsubscribe($this->token($request));
    }

    private function token(Request $request): string
    {
        $token = $request->query('token');

        return (string) (is_string($token) && $token !== '' ? $token : ($this->input($request)['token'] ?? ''));
    }
}
