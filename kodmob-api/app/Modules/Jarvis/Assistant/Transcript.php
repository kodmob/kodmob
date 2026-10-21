<?php

namespace App\Modules\Jarvis\Assistant;

use App\Core\Support\Text;
use App\Modules\Jarvis\Skills\SkillRegistry;

/**
 * Saklanan API mesajlarını panelin çizdiği konuşmaya çevirir:
 *
 *     [{ role: 'user', text }, { role: 'assistant', parts: [
 *         { type: 'text', text },
 *         { type: 'tool', id, name, title, input, result, is_error },
 *         { type: 'search', query, results: [{ title, url }] },
 *         { type: 'notice', tone, text },
 *     ] }]
 *
 * Art arda gelen asistan turları (araç → yanıt) tek balonda birleşir; araç
 * sonuçları (kullanıcı rolündeki tool_result) ilgili araç parçasına bağlanır.
 * Düşünme blokları gösterilmez.
 */
final class Transcript
{
    public static function build(array $messages): array
    {
        $items = [];
        $tools = []; // tool_use_id → [item index, part index]
        $searches = [];
        foreach ($messages as $message) {
            $content = $message['content'];
            if ($message['role'] === 'user') {
                if (is_string($content)) {
                    $items[] = ['id' => $message['id'], 'role' => 'user', 'text' => $content, 'at' => $message['created_at']];

                    continue;
                }
                $texts = [];
                foreach ((array) $content as $block) {
                    if (($block['type'] ?? '') === 'tool_result' && isset($tools[$block['tool_use_id'] ?? ''])) {
                        [$i, $p] = $tools[$block['tool_use_id']];
                        $raw = is_string($block['content'] ?? null) ? $block['content'] : Text::json($block['content'] ?? '');
                        $decoded = json_decode((string) $raw, true);
                        $items[$i]['parts'][$p]['result'] = $decoded ?? $raw;
                        $items[$i]['parts'][$p]['is_error'] = ! empty($block['is_error']);
                    } elseif (($block['type'] ?? '') === 'text') {
                        $texts[] = (string) $block['text'];
                    }
                }
                if ($texts) {
                    $items[] = ['id' => $message['id'], 'role' => 'user', 'text' => implode("\n\n", $texts), 'at' => $message['created_at']];
                }

                continue;
            }

            // Asistan: bir önceki öğe de asistansa aynı balona ekle.
            $last = count($items) - 1;
            if ($last < 0 || $items[$last]['role'] !== 'assistant') {
                $items[] = ['id' => $message['id'], 'role' => 'assistant', 'parts' => [], 'at' => $message['created_at'], 'model' => $message['meta']['model'] ?? null];
                $last = count($items) - 1;
            }
            foreach ((array) $content as $block) {
                $type = $block['type'] ?? '';
                if ($type === 'text' && trim((string) $block['text']) !== '') {
                    $items[$last]['parts'][] = ['type' => 'text', 'text' => (string) $block['text']];
                } elseif ($type === 'tool_use') {
                    $name = (string) $block['name'];
                    $items[$last]['parts'][] = [
                        'type' => 'tool',
                        'id' => (string) $block['id'],
                        'name' => $name,
                        'title' => SkillRegistry::has($name) ? SkillRegistry::get($name)->title() : $name,
                        'input' => $block['input'] ?? new \stdClass,
                        'result' => null,
                        'is_error' => false,
                    ];
                    $tools[(string) $block['id']] = [$last, count($items[$last]['parts']) - 1];
                } elseif ($type === 'server_tool_use' && ($block['name'] ?? '') === 'web_search') {
                    $items[$last]['parts'][] = ['type' => 'search', 'query' => (string) ($block['input']['query'] ?? ''), 'results' => []];
                    $searches[(string) $block['id']] = [$last, count($items[$last]['parts']) - 1];
                } elseif ($type === 'web_search_tool_result' && isset($searches[$block['tool_use_id'] ?? ''])) {
                    [$i, $p] = $searches[$block['tool_use_id']];
                    $results = [];
                    // Başarıda liste, hatada tek nesne döner.
                    foreach (is_array($block['content'] ?? null) && array_is_list($block['content']) ? $block['content'] : [] as $hit) {
                        if (($hit['type'] ?? '') === 'web_search_result') {
                            $results[] = ['title' => (string) ($hit['title'] ?? ''), 'url' => (string) ($hit['url'] ?? '')];
                        }
                    }
                    $items[$i]['parts'][$p]['results'] = array_slice($results, 0, 8);
                }
            }
            $stop = $message['meta']['stop_reason'] ?? null;
            if ($stop === 'refusal') {
                $items[$last]['parts'][] = ['type' => 'notice', 'tone' => 'warning', 'text' => 'Jarvis bu isteği güvenlik kuralları nedeniyle yanıtlayamadı. İsteği farklı ifade edin.'];
            } elseif ($stop === 'max_tokens') {
                $items[$last]['parts'][] = ['type' => 'notice', 'tone' => 'warning', 'text' => 'Yanıt uzunluk sınırında kesildi. "Devam et" yazarak sürdürebilirsiniz.'];
            }
        }

        return $items;
    }
}
