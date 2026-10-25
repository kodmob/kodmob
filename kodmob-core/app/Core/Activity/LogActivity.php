<?php

namespace App\Core\Activity;

use App\Core\Content\Types;
use App\Core\Events\Event;
use App\Core\Events\EventBus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paneldeki her başarılı yazma isteğini İşlem Geçmişi'ne yazar (genel işlem kaydı).
 *
 * - Olay türü: `Activity::type()` ile verilen, yoksa rotanın adı (`users.update`).
 * - Kayıt: `Activity::subject()`, yoksa rotadaki `{type}` ve `{id}`.
 * - Ayrıntı: `Activity::with()` + değiştirilen alanların adları (değerleri değil;
 *   şifre, anahtar gibi alanlar adıyla bile yazılmaz).
 * - Okuma istekleri (GET) yazılmaz; `Activity::force()` ya da rotada
 *   `->defaults('activity', true)` ile yazılır (ör. yedek indirme).
 * - `->defaults('activity', false)`: yazma isteği de yazılmaz (iş tetikleme, önizleme, okundu işareti).
 * - Hata dönen istek (4xx/5xx) yazılmaz: olmayan işlem geçmişe girmez.
 *
 * Kayıt yazılamazsa istek bozulmaz; uygulama günlüğüne düşer.
 */
final class LogActivity
{
    private const SECRET = '/pass|token|secret|key|otp|code/i';

    public function __construct(private readonly EventBus $events) {}

    public function handle(Request $request, Closure $next): Response
    {
        Activity::reset();
        $response = $next($request);

        try {
            $this->record($request, $response);
        } catch (\Throwable $e) {
            Log::warning('[Kodmob] işlem kaydı yazılamadı: '.$e->getMessage());
        } finally {
            Activity::reset();
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $state = Activity::state();
        $route = $request->route();
        $read = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($state['skip'] || $response->getStatusCode() >= 400 || ! $route || ($route->defaults['activity'] ?? null) === false) {
            return;
        }
        if ($read && ! $state['force'] && empty($route->defaults['activity'])) {
            return;
        }
        $type = $state['type'] ?? (string) $route->getName();
        if (! preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $type)) {
            return;
        }

        $params = $route->parameters();
        $subject = $state['subject'] ?? [
            // Rotadaki içerik türü (projects) → kayıt türü (project).
            'type' => (Types::editable()[$params['type'] ?? ''] ?? null)?->recordKind() ?? explode('.', $type)[0],
            'id' => (int) ($params['id'] ?? 0),
            'label' => '',
        ];

        $payload = $state['payload'];
        if (! $read && ! isset($payload['fields'])) {
            $fields = array_values(array_filter(array_keys($this->input($request)), static fn ($k) => is_string($k) && ! preg_match(self::SECRET, $k)));
            if ($fields) {
                $payload['fields'] = array_slice($fields, 0, 30);
            }
        }

        $this->events->publish(new Event(
            $type,
            (string) $subject['type'],
            (int) $subject['id'],
            $payload,
            [],
            $request->user() ? (int) $request->user()->id : null,
            null,
            null,
            (string) ($subject['label'] ?? ''),
            (string) $request->ip(),
        ));
    }

    private function input(Request $request): array
    {
        $json = $request->isJson() ? $request->json()->all() : null;

        return is_array($json) ? $json : $request->post();
    }
}
