<?php

namespace App\Core\Ai;

use App\Core\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/** HTTP ile konuşan sağlayıcılar (OpenAI, Gemini) için ortak çağrı ve hata çevirisi. */
final class AiHttp
{
    /** @param  Closure(): Response  $call */
    public static function json(string $label, Closure $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new DomainException('km_ai_unreachable', $label.' API\'sine ulaşılamadı (bağlantı ya da zaman aşımı). Yeniden deneyin.', 503);
        }
        if ($response->successful()) {
            return (array) $response->json();
        }
        $message = (string) ($response->json('error.message') ?? $response->json('error') ?? '');
        $message = is_string($message) ? mb_substr($message, 0, 300) : '';
        throw match (true) {
            $response->status() === 401 => new DomainException('km_ai_auth', $label.' API anahtarı geçersiz ya da iptal edilmiş. Ayarlar > Yapay zekâ\'dan yeni anahtar girin.', 401),
            $response->status() === 403 => new DomainException('km_ai_forbidden', $label.': bu anahtarın seçili modele ya da bu işleme erişimi yok.'.($message !== '' ? ' ('.$message.')' : ''), 403),
            $response->status() === 404 => new DomainException('km_ai_bad_request', $label.': model bulunamadı. Ayarlar > Yapay zekâ\'dan başka bir model seçin.'.($message !== '' ? ' ('.$message.')' : ''), 422),
            $response->status() === 429 => new DomainException('km_ai_rate_limited', $label.' hız ya da kota sınırına takıldı; biraz sonra yeniden deneyin.', 429),
            $response->status() === 400 || $response->status() === 422 => self::badRequest($label, $message),
            default => self::serverError($label, $response->status(), $message),
        };
    }

    private static function badRequest(string $label, string $message): DomainException
    {
        Log::warning("[Kodmob AI] {$label} 400: {$message}");

        return new DomainException('km_ai_bad_request', $label.' isteği reddetti: '.$message, 422);
    }

    private static function serverError(string $label, int $status, string $message): DomainException
    {
        Log::warning("[Kodmob AI] {$label} {$status}: {$message}");

        return new DomainException('km_ai_error', $label.' API hata döndürdü; biraz sonra yeniden deneyin.', 502);
    }

    /** JSON şemasında boş `properties` dizisi nesne olmalı (`{}`); iç içe şemalar dahil. */
    public static function schema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if ($key === 'properties' && is_array($value)) {
                $schema[$key] = $value === [] ? (object) [] : array_map(static fn ($p) => is_array($p) ? self::schema($p) : $p, $value);
            } elseif ($key === 'items' && is_array($value)) {
                $schema[$key] = self::schema($value);
            }
        }

        return $schema;
    }

    /** Sistem bloklarının düz metni (önbellek işaretleri olmayan sağlayıcılar için). */
    public static function systemText(array $system): string
    {
        return trim(implode("\n\n", array_map(static fn ($b) => (string) ($b['text'] ?? ''), $system)));
    }

    /** @return list<array> içerik blokları (metin içerik tek metin bloğuna çevrilir) */
    public static function blocks(string|array $content): array
    {
        return is_string($content) ? [['type' => 'text', 'text' => $content]] : array_values(array_filter($content, 'is_array'));
    }
}
