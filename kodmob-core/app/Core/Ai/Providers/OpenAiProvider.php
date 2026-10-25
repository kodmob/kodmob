<?php

namespace App\Core\Ai\Providers;

use App\Core\Ai\AiHttp;
use App\Core\Ai\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Responses API (POST /v1/responses). Güncel modeller (GPT-6 Astra, GPT-6.1 Sol) araç çağrısı
 * için bu API'yi ister.
 *
 * - `store: false`: konuşma OpenAI'da saklanmaz; geçmişi her istekte biz göndeririz. Düşünme öğeleri
 *   şifreli gelir (`include: reasoning.encrypted_content`) ve araç turunda aynen geri gönderilmelidir:
 *   yanıtın `output` öğeleri `native.items` olarak saklanır, aynı sağlayıcıyla devam ederken aynen gider.
 * - Başka sağlayıcıdan gelen yanıtlar ortak biçimden çevrilir (metin → assistant mesajı, tool_use →
 *   function_call, tool_result → function_call_output).
 * - Web araması: yerleşik `web_search` aracı.
 */
final class OpenAiProvider implements AiProvider
{
    public const URL = 'https://api.openai.com/v1';

    private const EFFORT = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'high', 'max' => 'high'];

    public function key(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI (GPT)';
    }

    public function models(): array
    {
        return [
            'gpt-6.1-sol' => 'GPT-6.1 Sol (önerilen; dengeli)',
            'gpt-6-astra' => 'GPT-6 Astra (en yetenekli)',
            'gpt-6-luna' => 'GPT-6 Luna (hızlı, ekonomik)',
        ];
    }

    public function defaultModel(): string
    {
        return 'gpt-6.1-sol';
    }

    public function keyPlaceholder(): string
    {
        return 'sk-…';
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, ['web_search', 'effort'], true);
    }

    public function send(array $messages, array $system, array $tools, array $options): array
    {
        $model = (string) $options['model'];
        $body = [
            'model' => $model,
            'input' => $this->input($messages),
            'max_output_tokens' => (int) ($options['max_tokens'] ?? 16000),
            'store' => false,
            'include' => ['reasoning.encrypted_content'],
        ];
        $instructions = AiHttp::systemText($system);
        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }
        $wire = array_map(static fn (array $t) => [
            'type' => 'function', 'name' => $t['name'], 'description' => $t['description'], 'parameters' => AiHttp::schema($t['inputSchema'] ?? ['type' => 'object']),
        ], $tools);
        if (! empty($options['web_search'])) {
            $wire[] = ['type' => 'web_search'];
        }
        if ($wire) {
            $body['tools'] = $wire;
        }
        // Düşünme düzeyi yalnızca akıl yürüten modellerde (gpt-5 ve sonrası, o serisi).
        if (! empty($options['effort']) && preg_match('/^(gpt-([5-9]|\d{2})|o\d)/', $model)) {
            $body['reasoning'] = ['effort' => self::EFFORT[$options['effort']] ?? 'medium'];
        }

        $json = AiHttp::json('OpenAI', fn () => Http::withToken((string) $options['api_key'])->acceptJson()->timeout(120)->post(self::URL.'/responses', $body));

        return $this->parse($json, $model);
    }

    public function listModels(string $apiKey): array
    {
        $json = AiHttp::json('OpenAI', fn () => Http::withToken($apiKey)->acceptJson()->timeout(20)->get(self::URL.'/models'));
        $ids = array_map(static fn ($m) => (string) ($m['id'] ?? ''), (array) ($json['data'] ?? []));
        $ids = array_filter($ids, static fn ($id) => preg_match('/^(gpt-|o\d|chatgpt-)/', $id) && ! preg_match('/(audio|realtime|transcribe|tts|image|embedding|moderation)/', $id));
        rsort($ids);

        return array_values(array_map(static fn ($id) => ['id' => $id, 'label' => $id], $ids));
    }

    /** Ortak biçimdeki geçmiş → Responses API girdi öğeleri. */
    private function input(array $messages): array
    {
        $items = [];
        foreach ($messages as $message) {
            $native = $message['native'] ?? null;
            if ($message['role'] === 'assistant') {
                if (($native['provider'] ?? '') === 'openai' && is_array($native['items'] ?? null)) {
                    array_push($items, ...$native['items']);

                    continue;
                }
                foreach (AiHttp::blocks($message['content']) as $block) {
                    if (($block['type'] ?? '') === 'text' && trim((string) $block['text']) !== '') {
                        $items[] = ['role' => 'assistant', 'content' => (string) $block['text']];
                    } elseif (($block['type'] ?? '') === 'tool_use') {
                        $items[] = ['type' => 'function_call', 'call_id' => (string) $block['id'], 'name' => (string) $block['name'], 'arguments' => json_encode((object) ($block['input'] ?? []), JSON_UNESCAPED_UNICODE)];
                    }
                }

                continue;
            }
            foreach (AiHttp::blocks($message['content']) as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $output = $block['content'] ?? '';
                    $items[] = ['type' => 'function_call_output', 'call_id' => (string) $block['tool_use_id'], 'output' => is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_UNICODE)];
                } elseif (($block['type'] ?? '') === 'text') {
                    $items[] = ['role' => 'user', 'content' => (string) $block['text']];
                }
            }
        }

        return $items;
    }

    /** Responses API yanıtı → ortak biçim. */
    private function parse(array $json, string $model): array
    {
        $content = [];
        $refusal = false;
        $calls = false;
        foreach ((array) ($json['output'] ?? []) as $item) {
            switch ($item['type'] ?? '') {
                case 'message':
                    $text = '';
                    foreach ((array) ($item['content'] ?? []) as $part) {
                        if (($part['type'] ?? '') === 'output_text') {
                            $text .= (string) ($part['text'] ?? '');
                        } elseif (($part['type'] ?? '') === 'refusal') {
                            $refusal = true;
                        }
                    }
                    if ($text !== '') {
                        $content[] = ['type' => 'text', 'text' => $text];
                    }
                    break;
                case 'function_call':
                    $calls = true;
                    $input = json_decode((string) ($item['arguments'] ?? '{}'), true);
                    $content[] = ['type' => 'tool_use', 'id' => (string) ($item['call_id'] ?? $item['id'] ?? ''), 'name' => (string) ($item['name'] ?? ''), 'input' => is_array($input) ? $input : []];
                    break;
                case 'web_search_call':
                    $content[] = ['type' => 'server_tool_use', 'id' => (string) ($item['id'] ?? ''), 'name' => 'web_search', 'input' => ['query' => (string) ($item['action']['query'] ?? '')]];
                    break;
            }
        }
        $incomplete = ($json['status'] ?? '') === 'incomplete' && ($json['incomplete_details']['reason'] ?? '') === 'max_output_tokens';

        return [
            'content' => $content,
            'stop_reason' => $calls ? 'tool_use' : ($refusal ? 'refusal' : ($incomplete ? 'max_tokens' : 'end_turn')),
            'model' => (string) ($json['model'] ?? $model),
            'usage' => ['input_tokens' => (int) ($json['usage']['input_tokens'] ?? 0), 'output_tokens' => (int) ($json['usage']['output_tokens'] ?? 0)],
            'native' => ['provider' => 'openai', 'items' => array_values((array) ($json['output'] ?? []))],
        ];
    }
}
