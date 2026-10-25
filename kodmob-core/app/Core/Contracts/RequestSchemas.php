<?php

namespace App\Core\Contracts;

use App\Core\Http\Schema;

/**
 * İstek şeması bildirimsel tanımlanan uçlar (Core\Http\Schema): "YÖNTEM /yol" → şemayı veren statik yöntem. Şemaya
 * geçen uç buraya eklenir; OpenAPI istek gövdesini buradan alır (docs/done-sozlesme-raporu.md §4.2: dokunulan uç şemaya
 * geçer, elle doğrulama bir anda kaldırılmaz).
 */
final class RequestSchemas
{
    private const ROUTES = [
        'POST /v1/users' => 'App\Modules\Users\UserService::createSchema',
        'PUT /v1/users/{id}' => 'App\Modules\Users\UserService::schema',
        'POST /v1/seo/redirects' => 'App\Modules\Seo\Services\Redirects::schema',
        'PUT /v1/seo/redirects/{id}' => 'App\Modules\Seo\Services\Redirects::schema',
        'POST /v1/push/subscribe' => 'App\Core\Push\PushSubscriptions::schema',
    ];

    /** @return array<string,array> "YÖNTEM /yol" → JSON Schema */
    public static function bodies(): array
    {
        $out = [];
        foreach (self::ROUTES as $route => $provider) {
            /** @var Schema $schema */
            $schema = call_user_func($provider);
            $out[$route] = $schema->json();
        }

        return $out;
    }
}
