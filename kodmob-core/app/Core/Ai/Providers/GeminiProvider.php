<?php

namespace App\Core\Ai\Providers;

use App\Core\Ai\AiHttp;
use App\Core\Ai\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini API (POST /v1beta/models/{model}:generateContent, `x-goog-api-key`).
 *
 * - Yanıtın `parts` dizisi (düşünce imzaları dahil) `native.parts` olarak saklanır; aynı sağlayıcıyla
 *   devam ederken model turu aynen gider (Gemini araç turlarında imzayı ister).
 * - Başka sağlayıcıdan gelen araç turları Gemini'ye işlev çağrısı olarak değil düz metin olarak
 *   aktarılır (imzasız işlev çağrısı reddedilebilir); içerik kaybolmaz.
 * - Araç şemaları Gemini'nin desteklediği alt kümeye indirgenir (`additionalProperties` vb. atılır).
 * - Web araması ve düşünme düzeyi şimdilik kullanılmaz (modelin varsayılanı).
 */
final class GeminiProvider implements AiProvider
{
    public const URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const SCHEMA_KEYS = ['type', 'format', 'description', 'nullable', 'enum', 'items', 'properties', 'required', 'minItems', 'maxItems', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'anyOf'];

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google (Gemini)';
    }

    public function models(): array
    {
        return [
            'gemini-3.8-flash' => 'Gemini 3.8 Flash (önerilen; hızlı)',
            'gemini-4-argon' => 'Gemini 4 Argon (en yetenekli)',
            'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite (en ekonomik)',
        ];
    }

    public function defaultModel(): string
    {
        return 'gemini-3.8-flash';
    }

    public function keyPlaceholder(): string
    {
        return 'AIza…';
    }

    public function supports(string $feature): bool
    {
        return false;
    }

    public function send(array $messages, array $system, array $tools, array $options): array
    {
        $model = (string) $options['model'];
        $body = [
            'contents' => $this->contents($messages),
            'generationConfig' => ['maxOutputTokens' => (int) ($options['max_tokens'] ?? 16000)],
        ];
        $instructions = AiHttp::systemText($system);
        if ($instructions !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $instructions]]];
        }
        if ($tools) {
            $body['tools'] = [['functionDeclarations' => array_map(function (array $t) {
                $declaration = ['name' => $t['name'], 'description' => $t['description']];
                $schema = $this->schema($t['inputSchema'] ?? []);
                if (! empty($schema['properties'])) {
                    $declaration['parameters'] = $schema;
                }

                return $declaration;
            }, $tools)]];
        }

        $json = AiHttp::json('Gemini', fn () => Http::withHeaders(['x-goog-api-key' => (string) $options['api_key']])->acceptJson()->timeout(120)
            ->post(self::URL.'/models/'.rawurlencode($model).':generateContent', $body));

        return $this->parse($json, $model);
    }

    public function listModels(string $apiKey): array
    {
        $json = AiHttp::json('Gemini', fn () => Http::withHeaders(['x-goog-api-key' => $apiKey])->acceptJson()->timeout(20)->get(self::URL.'/models', ['pageSize' => 200]));
        $models = array_filter((array) ($json['models'] ?? []), static fn ($m) => in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true) && str_contains((string) ($m['name'] ?? ''), 'gemini'));

        return array_values(array_map(static fn ($m) => ['id' => substr((string) $m['name'], 7), 'label' => (string) ($m['displayName'] ?? substr((string) $m['name'], 7))], $models));
    }

    /** Ortak biçimdeki geçmiş → Gemini `contents` (aynı rol art arda gelirse birleşir). */
    private function contents(array $messages): array
    {
        // Gemini'nin kendi turlarındaki araç çağrıları: adları ve Gemini'nin verdiği kimlikler.
        $native = [];
        $names = [];
        foreach ($messages as $message) {
            if ($message['role'] !== 'assistant') {
                continue;
            }
            foreach (AiHttp::blocks($message['content']) as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $names[(string) $block['id']] = (string) $block['name'];
                    if (($message['native']['provider'] ?? '') === 'google') {
                        $native[(string) $block['id']] = $block['native_id'] ?? null;
                    }
                }
            }
        }

        $contents = [];
        $push = static function (string $role, array $parts) use (&$contents) {
            if (! $parts) {
                return;
            }
            $last = array_key_last($contents);
            if ($last !== null && $contents[$last]['role'] === $role) {
                array_push($contents[$last]['parts'], ...$parts);
            } else {
                $contents[] = ['role' => $role, 'parts' => $parts];
            }
        };

        foreach ($messages as $message) {
            if ($message['role'] === 'assistant') {
                if (($message['native']['provider'] ?? '') === 'google' && is_array($message['native']['parts'] ?? null)) {
                    $push('model', $message['native']['parts']);

                    continue;
                }
                $parts = [];
                foreach (AiHttp::blocks($message['content']) as $block) {
                    if (($block['type'] ?? '') === 'text' && trim((string) $block['text']) !== '') {
                        $parts[] = ['text' => (string) $block['text']];
                    } elseif (($block['type'] ?? '') === 'tool_use') {
                        $parts[] = ['text' => '[Araç çağrıldı: '.$block['name'].' '.json_encode((object) ($block['input'] ?? []), JSON_UNESCAPED_UNICODE).']'];
                    }
                }
                $push('model', $parts);

                continue;
            }
            $parts = [];
            foreach (AiHttp::blocks($message['content']) as $block) {
                if (($block['type'] ?? '') === 'text' && trim((string) $block['text']) !== '') {
                    $parts[] = ['text' => (string) $block['text']];
                } elseif (($block['type'] ?? '') === 'tool_result') {
                    $id = (string) $block['tool_use_id'];
                    $result = is_string($block['content'] ?? null) ? $block['content'] : json_encode($block['content'] ?? '', JSON_UNESCAPED_UNICODE);
                    if (array_key_exists($id, $native)) {
                        $response = ['name' => $names[$id] ?? 'tool', 'response' => ! empty($block['is_error']) ? ['error' => $result] : ['result' => $result]];
                        if ($native[$id]) {
                            $response['id'] = $native[$id];
                        }
                        $parts[] = ['functionResponse' => $response];
                    } else {
                        $parts[] = ['text' => '[Araç sonucu ('.($names[$id] ?? 'araç').'): '.$result.']'];
                    }
                }
            }
            $push('user', $parts);
        }

        return $contents;
    }

    /** Gemini yanıtı → ortak biçim. */
    private function parse(array $json, string $model): array
    {
        $candidate = $json['candidates'][0] ?? null;
        $parts = (array) ($candidate['content']['parts'] ?? []);
        $content = [];
        $calls = false;
        foreach ($parts as $part) {
            if (! empty($part['thought'])) {
                continue;
            }
            if (isset($part['functionCall'])) {
                $calls = true;
                $call = $part['functionCall'];
                $content[] = [
                    'type' => 'tool_use',
                    'id' => (string) ($call['id'] ?? 'gfc_'.bin2hex(random_bytes(6))),
                    'name' => (string) ($call['name'] ?? ''),
                    'input' => (array) ($call['args'] ?? []),
                    'native_id' => $call['id'] ?? null,
                ];
            } elseif (isset($part['text']) && $part['text'] !== '') {
                $last = array_key_last($content);
                if ($last !== null && $content[$last]['type'] === 'text') {
                    $content[$last]['text'] .= $part['text'];
                } else {
                    $content[] = ['type' => 'text', 'text' => (string) $part['text']];
                }
            }
        }
        // Aday yoksa istem engellenmiştir (promptFeedback.blockReason).
        $blocked = ! empty($json['promptFeedback']['blockReason']);
        $finish = (string) ($candidate['finishReason'] ?? ($blocked ? 'SAFETY' : 'STOP'));
        $stop = match (true) {
            $calls => 'tool_use',
            $finish === 'MAX_TOKENS' => 'max_tokens',
            in_array($finish, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true) => 'refusal',
            default => 'end_turn',
        };
        $usage = (array) ($json['usageMetadata'] ?? []);

        return [
            'content' => $content,
            'stop_reason' => $stop,
            'model' => (string) ($json['modelVersion'] ?? $model),
            'usage' => ['input_tokens' => (int) ($usage['promptTokenCount'] ?? 0), 'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0)],
            'native' => ['provider' => 'google', 'parts' => $parts],
        ];
    }

    /** JSON şeması → Gemini'nin desteklediği alt küme (özyinelemeli). */
    private function schema(array $schema): array
    {
        $out = array_intersect_key($schema, array_flip(self::SCHEMA_KEYS));
        if (isset($out['properties']) && is_array($out['properties'])) {
            $out['properties'] = array_map(fn ($p) => is_array($p) ? $this->schema($p) : $p, $out['properties']);
            if ($out['properties'] === []) {
                unset($out['properties']);
            }
        }
        if (isset($out['items']) && is_array($out['items'])) {
            $out['items'] = $this->schema($out['items']);
        }
        if (isset($out['anyOf']) && is_array($out['anyOf'])) {
            $out['anyOf'] = array_map(fn ($s) => is_array($s) ? $this->schema($s) : $s, $out['anyOf']);
        }

        return $out;
    }
}
