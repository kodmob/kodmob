<?php

namespace App\Core\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Core\Ai\AiHttp;
use App\Core\Ai\AiProvider;
use App\Core\Exceptions\DomainException;
use App\Core\Support\Text;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Claude Messages API (resmî Anthropic PHP SDK). Ortak biçim Claude'un kendi blok biçimidir; geçmiş
 * olduğu gibi gider (düşünme blokları dahil — bu modellerde geçmiş yalnızca eklenir).
 *
 * - Opus 5.5 / Sonnet 5.5 / Fable 5.1: düşünme uyarlamalı, derinlik `output_config.effort`; reddedilen
 *   istek için sunucu taraflı yedek model (`fallbacks: "default"`, beta).
 * - Haiku 4.5: effort ve yedek model yok.
 *
 * Başka sağlayıcıdan gelen yanıtlar (sohbet ortasında sağlayıcı değiştiyse) yalnızca metin ve araç
 * çağrısı bloklarıyla gönderilir.
 */
final class AnthropicProvider implements AiProvider
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private const NEW_GENERATION = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-1'];

    public function key(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Claude (Anthropic)';
    }

    public function models(): array
    {
        return [
            'claude-opus-5-5' => 'Claude Opus 5.5 (önerilen)',
            'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (hızlı, ekonomik)',
            'claude-haiku-4-5' => 'Claude Haiku 4.5 (en hızlı)',
            'claude-fable-5-1' => 'Claude Fable 5.1 (en yetenekli, pahalı)',
        ];
    }

    public function defaultModel(): string
    {
        return 'claude-opus-5-5';
    }

    public function keyPlaceholder(): string
    {
        return 'sk-ant-…';
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, ['web_search', 'effort'], true);
    }

    public function send(array $messages, array $system, array $tools, array $options): array
    {
        $model = (string) $options['model'];
        $modern = in_array($model, self::NEW_GENERATION, true);
        $args = [
            'maxTokens' => (int) ($options['max_tokens'] ?? 16000),
            'messages' => array_map(fn (array $m) => ['role' => $m['role'], 'content' => $this->content($m)], $messages),
            'model' => $model,
            'system' => $system,
        ];
        $tools = array_map(static fn (array $t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => AiHttp::schema($t['inputSchema'] ?? ['type' => 'object'])], $tools);
        if (! empty($options['web_search'])) {
            $tools[] = ['type' => $modern ? 'web_search_20260209' : 'web_search_20250305', 'name' => 'web_search', 'maxUses' => 5];
        }
        if ($tools) {
            $args['tools'] = $tools;
        }
        if ($modern) {
            $args['outputConfig'] = ['effort' => (string) ($options['effort'] ?? 'medium')];
            $args['fallbacks'] = 'default';
            $args['betas'] = [self::FALLBACK_BETA];
        }

        $client = $this->client((string) $options['api_key']);
        try {
            $message = $modern ? $client->beta->messages->create(...$args) : $client->messages->create(...$args);
        } catch (AuthenticationException) {
            throw new DomainException('km_ai_auth', 'Claude API anahtarı geçersiz ya da iptal edilmiş. Ayarlar > Yapay zekâ\'dan yeni anahtar girin.', 401);
        } catch (PermissionDeniedException) {
            throw new DomainException('km_ai_forbidden', 'Bu API anahtarının seçili modele erişimi yok. Başka bir model seçin.', 403);
        } catch (RateLimitException) {
            throw new DomainException('km_ai_rate_limited', 'Claude hız sınırına takıldı; biraz sonra "Yeniden dene"ye basın.', 429);
        } catch (BadRequestException $e) {
            Log::warning('[Kodmob AI] Claude 400: '.$e->getMessage());
            throw new DomainException('km_ai_bad_request', 'Claude isteği reddetti: '.mb_substr($e->getMessage(), 0, 300), 422);
        } catch (APITimeoutException|APIConnectionException) {
            throw new DomainException('km_ai_unreachable', 'Claude API\'ye ulaşılamadı (bağlantı ya da zaman aşımı). Yeniden deneyin.', 503);
        } catch (APIStatusException $e) {
            Log::warning('[Kodmob AI] Claude '.$e->getMessage());
            throw new DomainException('km_ai_error', 'Claude API hata döndürdü; biraz sonra yeniden deneyin.', 502);
        }

        $wire = json_decode((string) Text::json($message), true) ?: [];

        return [
            'content' => (array) ($wire['content'] ?? []),
            'stop_reason' => (string) ($wire['stop_reason'] ?? 'end_turn'),
            'model' => (string) ($wire['model'] ?? $model),
            'usage' => ['input_tokens' => (int) ($wire['usage']['input_tokens'] ?? 0), 'output_tokens' => (int) ($wire['usage']['output_tokens'] ?? 0)],
            // Ortak biçim Claude'un kendisi; ayrıca saklanacak bir şey yok.
            'native' => ['provider' => 'anthropic', 'stop_details' => $wire['stop_details'] ?? null],
        ];
    }

    public function listModels(string $apiKey): array
    {
        $json = AiHttp::json('Claude', fn () => Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(20)->get('https://api.anthropic.com/v1/models', ['limit' => 100]));

        return array_values(array_map(static fn ($m) => ['id' => (string) $m['id'], 'label' => (string) ($m['display_name'] ?? $m['id'])], array_filter((array) ($json['data'] ?? []), static fn ($m) => isset($m['id']))));
    }

    /** Claude'un ya da eski (sağlayıcı bilgisi olmayan) yanıtı aynen; başka sağlayıcınınki metin + araç çağrısı. */
    private function content(array $message): string|array
    {
        $provider = $message['native']['provider'] ?? null;
        if ($message['role'] !== 'assistant' || $provider === null || $provider === 'anthropic' || is_string($message['content'])) {
            return $message['content'];
        }
        $blocks = [];
        foreach (AiHttp::blocks($message['content']) as $block) {
            if (($block['type'] ?? '') === 'text' && trim((string) $block['text']) !== '') {
                $blocks[] = ['type' => 'text', 'text' => (string) $block['text']];
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $blocks[] = ['type' => 'tool_use', 'id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => (object) ($block['input'] ?? [])];
            }
        }

        return $blocks ?: [['type' => 'text', 'text' => '…']];
    }

    private function client(string $key): Client
    {
        if (! class_exists(Client::class)) {
            throw new DomainException('km_ai_sdk_missing', 'Anthropic SDK yüklü değil (kodmob-core/vendor eksik). Paketi yeniden yükleyin.', 500);
        }
        // `ai.anthropic_options` (kapsayıcıda tanımlıysa): zaman aşımı, yeniden deneme ya da (testte) sahte taşıyıcı.
        $options = ['timeout' => 90.0, 'maxRetries' => 2];
        if (app()->bound('ai.anthropic_options')) {
            $options = (app('ai.anthropic_options'))($options);
        }

        return new Client(apiKey: $key, requestOptions: $options);
    }
}
