<?php

namespace App\Core\Search;

use App\Core\Content\Types;
use App\Modules\Auth\Models\User;

/**
 * Panel araması (GET /search): kullanıcının yetkili olduğu sağlayıcıları sırayla sorar.
 * Önce içerik türleri (Types::editable(), Searchable olanlar), sonra modüllerin verdikleri
 * (Core\Module::search()).
 *
 * Yanıt: `{ groups: [{ key, label, icon, items: [SearchHit] }] }`. Sayfalar, sekmeler ve
 * ayarlar panelde aranır (kodmob-frontend/src/panel/search-index.js); burası yalnızca kayıtlar içindir.
 */
final class SearchRegistry
{
    public const MIN_LENGTH = 2;

    /** @var array<string,SearchProvider> */
    private static array $providers = [];

    public static function add(SearchProvider ...$providers): void
    {
        foreach ($providers as $provider) {
            self::$providers[$provider->key()] = $provider;
        }
    }

    /** Uygulama açılışında (AppServiceProvider): yalnızca açık modüllerin sağlayıcıları kalsın. */
    public static function reset(): void
    {
        self::$providers = [];
    }

    /** @return array<string,SearchProvider> */
    public static function providers(): array
    {
        $content = [];
        foreach (Types::editable() as $name => $type) {
            if ($type instanceof Searchable) {
                $content[$name] = new ContentSearch($type, 'layers');
            }
        }

        return $content + self::$providers;
    }

    public static function query(User $user, string $term, int $limit = 5): array
    {
        $term = trim(mb_substr($term, 0, 100));
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return ['groups' => []];
        }
        $groups = [];
        foreach (self::providers() as $provider) {
            $caps = array_filter(explode('|', $provider->capability()));
            if ($caps && ! array_filter($caps, static fn ($cap) => $user->can($cap))) {
                continue;
            }
            $items = $provider->search($term, $limit);
            if ($items) {
                $groups[] = [
                    'key' => $provider->key(),
                    'label' => $provider->label(),
                    'icon' => $provider->icon(),
                    'items' => array_map(static fn (SearchHit $hit) => $hit->toArray(), $items),
                ];
            }
        }

        return ['groups' => $groups];
    }
}
