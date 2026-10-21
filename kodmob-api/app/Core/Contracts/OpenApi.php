<?php

namespace App\Core\Contracts;

/**
 * Sözleşmeden OpenAPI 3.1 belgesi (docs/contracts/openapi.json). Uçlar, modülü (etiket), yetkileri
 * (`x-kodmob-capabilities`), oturum gereği ve hız sınırı. İstek gövdesi şeması bilinen uçlarda (RequestSchemas);
 * diğerlerinde serbest nesne. Herkese açık yayımlanmaz: depoda ve panelde yetkiliye (km_manage_settings).
 */
final class OpenApi
{
    /** @param array{modules:array<string,array>} $contract */
    public static function build(array $contract, array $bodies = []): array
    {
        $paths = [];
        $tags = [];
        foreach ($contract['modules'] as $key => $module) {
            if (empty($module['routes'])) {
                continue;
            }
            $tags[] = ['name' => $key, 'description' => $module['label']];
            foreach ($module['routes'] as $route) {
                $method = strtolower($route['method']);
                $operation = [
                    'operationId' => $route['name'] ?? $method.str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], $route['path']),
                    'tags' => [$key],
                ];
                if (isset($route['summary'])) {
                    $operation['summary'] = $route['summary'];
                }
                $params = [];
                if (preg_match_all('/\{([a-zA-Z_]+)\??\}/', $route['path'], $m)) {
                    foreach ($m[1] as $name) {
                        $params[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
                    }
                }
                if ($params) {
                    $operation['parameters'] = $params;
                }
                if (in_array($method, ['post', 'put', 'patch'], true)) {
                    $schema = $bodies[$route['method'].' '.$route['path']] ?? ['type' => 'object'];
                    $operation['requestBody'] = ['content' => ['application/json' => ['schema' => $schema]]];
                }
                $operation['security'] = $route['auth'] ? [['bearer' => []]] : [];
                if (! empty($route['caps'])) {
                    $operation['x-kodmob-capabilities'] = $route['caps'];
                }
                if (isset($route['throttle'])) {
                    $operation['x-kodmob-throttle'] = $route['throttle'];
                }
                $responses = ['200' => ['description' => 'Başarılı']];
                if ($route['auth']) {
                    $responses['401'] = ['$ref' => '#/components/responses/Error'];
                    $responses['403'] = ['$ref' => '#/components/responses/Error'];
                }
                if ($method !== 'get') {
                    $responses['422'] = ['$ref' => '#/components/responses/Error'];
                }
                if (isset($route['throttle'])) {
                    $responses['429'] = ['$ref' => '#/components/responses/Error'];
                }
                $operation['responses'] = $responses;
                $paths[$route['path']][$method] = $operation;
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Kodmob API',
                'version' => 'v1',
                'description' => 'Koddan üretilir (php artisan kodmob:contracts). Panel uçları Bearer oturum ve x-kodmob-capabilities yetkilerinden birini ister.',
            ],
            'servers' => [['url' => '/api']],
            'tags' => $tags,
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'required' => ['code', 'message'],
                        'properties' => [
                            'code' => ['type' => 'string', 'examples' => ['km_validation_failed']],
                            'message' => ['type' => 'string'],
                            'details' => ['type' => 'object', 'properties' => ['fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]]],
                        ],
                    ],
                ],
                'responses' => [
                    'Error' => ['description' => 'Hata (Türkçe mesaj ve kod)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                ],
            ],
        ];
    }

    /** Depodaki biçim: girintili, Türkçe karakterler ve eğik çizgiler kaçışsız, sonda satır sonu. */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
