<?php

namespace App\Core\Security;

use App\Core\Content\Types;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * Roller ve yetkileri (`roles` tablosu).
 *
 * - Hazır roller (`DEFAULTS`, system = 1) kodda tanımlıdır; silinemez. Yetkileri
 *   panelden değiştirilebilir: değişiklik `caps` sütununa yazılır, "Varsayılana
 *   Dön" siler (null = koddaki tanım, yeni yetkiler kendiliğinden gelir).
 * - Yönetici (`admin`) kilitlidir: daima bütün yetkiler. Menüsü düzenlenebilir.
 * - Panelden yeni rol eklenir (ad, açıklama, yetkiler); kullanıcısı yoksa silinir.
 * - `menu`: rolün yan menüsü (gruplar ve sayfa anahtarları: overview, projects, briefs, domains…;
 *   kodmob-frontend/src/panel/meta.js PAGES); null = varsayılan menü. Adres değil anahtar saklanır: panel
 *   adresleri değişse de menü bozulmaz. Eski kayıtlardaki `#/…` adresleri okunurken çevrilir.
 *   Menüde olmak sayfayı açmaz: öğe yine yetkiye göre süzülür.
 *
 * Kontrol her uçta sunucudadır (`cap:` ara katmanı); panel yalnızca arayüzü süzer.
 */
final class Roles
{
    public const ADMIN = 'admin';

    private const MAX_GROUPS = 12;

    private const MAX_ITEMS = 40;

    /** Panel sayfa anahtarı: overview, projects, domains, history… */
    private const PAGE_KEY = '/^[a-z][a-z0-9_-]{0,39}$/';

    /** @var array<string,array>|null */
    private static ?array $cache = null;

    /** @return array<string,array{label:string,description:string,caps:list<string>|string}> */
    public static function defaults(): array
    {
        // Bütün içerik türlerinin düzenleme yetkileri (kayıttan; yeni tür hazır Editör rolüne de gelir).
        $content = array_values(array_map(static fn ($type) => $type->capability(), Types::editable()));

        return [
            self::ADMIN => [
                'label' => 'Yönetici',
                'description' => 'Bütün sayfalar ve işlemler. Değiştirilemez.',
                'caps' => '*',
            ],
            'editor' => [
                'label' => 'Editör',
                'description' => 'Projeler, hizmetler, referanslar, blog, görseller, sayfa metinleri, SEO ve diller; talepleri görür.',
                'caps' => array_merge([Capability::ACCESS_PANEL, Capability::VIEW_OVERVIEW], $content, [
                    Capability::PUBLISH_CONTENT, Capability::DELETE_CONTENT, Capability::MANAGE_MEDIA,
                    Capability::MANAGE_TEXTS, Capability::EDIT_LAYOUT, Capability::MANAGE_SEO, Capability::MANAGE_LANGUAGES, Capability::VIEW_BRIEFS, Capability::USE_JARVIS,
                ]),
            ],
            'author' => [
                'label' => 'Yazar',
                'description' => 'Blog yazısı yazar ve görsel yükler; yayınlamayı Editör ya da Yönetici yapar.',
                'caps' => [Capability::ACCESS_PANEL, Capability::MANAGE_POSTS, Capability::MANAGE_MEDIA],
            ],
            'sales' => [
                'label' => 'Satış',
                'description' => 'Talepleri yönetir, teklif hesaplayıcı fiyatlarını düzenler, domain arar.',
                'caps' => [
                    Capability::ACCESS_PANEL, Capability::VIEW_OVERVIEW, Capability::VIEW_BRIEFS, Capability::MANAGE_BRIEFS,
                    Capability::MANAGE_ESTIMATE, Capability::USE_JARVIS, Capability::USE_DOMAINS,
                ],
            ],
        ];
    }

    /**
     * Bütün roller, yetkileri çözülmüş.
     *
     * @return array<string,array{slug:string,label:string,description:string,caps:list<string>,default_caps:list<string>,customized:bool,system:bool,locked:bool,menu:?array}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $defaults = self::defaults();
        $rows = [];
        foreach (DB::table('roles')->get() as $row) {
            $rows[$row->slug] = $row;
        }
        $out = [];
        // Hazır roller önce, kendi sıralarıyla; sonra eklenenler ada göre.
        foreach ($defaults as $slug => $def) {
            $out[$slug] = self::resolve($slug, $def, $rows[$slug] ?? null);
        }
        $custom = array_filter($rows, static fn ($r) => ! isset($defaults[$r->slug]));
        uasort($custom, static fn ($a, $b) => strcmp($a->label, $b->label));
        foreach ($custom as $slug => $row) {
            $out[$slug] = self::resolve($slug, null, $row);
        }

        return self::$cache = $out;
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** @return list<string> */
    public static function capsOf(string $slug): array
    {
        return self::get($slug)['caps'] ?? [];
    }

    /** @return array<string,string> slug => ad */
    public static function labels(): array
    {
        return array_map(static fn ($r) => $r['label'], self::all());
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    // ------------------------------------------------------------ değiştirme

    /** @param list<string>|null $caps null = varsayılana dön (yalnız hazır rol) */
    public static function setCaps(string $slug, ?array $caps): array
    {
        $role = self::mustGet($slug);
        if ($role['locked']) {
            throw new ConflictException('km_role_locked', 'Yönetici rolü bütün yetkilere sahiptir; değiştirilemez.');
        }
        if ($caps === null && ! $role['system']) {
            throw ValidationException::field('caps', 'Eklenen rolün varsayılanı yok; yetkileri seçin.');
        }
        self::persist($slug, self::row($role, ['caps' => $caps === null ? null : Text::json(self::cleanCaps($caps))]));
        self::forget();

        return self::get($slug);
    }

    /** @param list<array>|null $groups null = varsayılan menü */
    public static function setMenu(string $slug, ?array $groups): array
    {
        $role = self::mustGet($slug);
        self::persist($slug, self::row($role, ['menu' => $groups === null ? null : Text::json(self::cleanMenu($groups))]));
        self::forget();

        return self::get($slug);
    }

    /** Yeni rol: ad, açıklama ve yetkiler (ya da bir rolün yetkileri kopyalanarak). */
    public static function create(array $input): array
    {
        $label = mb_substr(Text::field($input['label'] ?? ''), 0, 60);
        if (mb_strlen($label) < 2) {
            throw ValidationException::field('label', 'Rol adı en az 2 karakter olmalı.');
        }
        if (in_array(mb_strtolower($label), array_map('mb_strtolower', self::labels()), true)) {
            throw ValidationException::field('label', 'Bu adda bir rol var.');
        }
        $base = Text::slug($label) ?: 'rol';
        $slug = mb_substr($base, 0, 36);
        for ($n = 2; self::get($slug) !== null; $n++) {
            $slug = mb_substr($base, 0, 33).'-'.$n;
        }
        $caps = isset($input['copy_from']) && ($from = self::get((string) $input['copy_from'])) ? $from['caps'] : (array) ($input['caps'] ?? [Capability::ACCESS_PANEL]);
        $now = Clock::now();
        DB::table('roles')->insert([
            'slug' => $slug,
            'label' => $label,
            'description' => mb_substr(Text::field($input['description'] ?? ''), 0, 255),
            'caps' => Text::json(self::cleanCaps($caps)),
            'menu' => null,
            'system' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::forget();

        return self::get($slug);
    }

    /** Ad ve açıklama (hazır rollerde de değiştirilebilir). */
    public static function rename(string $slug, array $input): array
    {
        $role = self::mustGet($slug);
        $label = mb_substr(Text::field($input['label'] ?? $role['label']), 0, 60);
        if (mb_strlen($label) < 2) {
            throw ValidationException::field('label', 'Rol adı en az 2 karakter olmalı.');
        }
        foreach (self::all() as $other) {
            if ($other['slug'] !== $slug && mb_strtolower($other['label']) === mb_strtolower($label)) {
                throw ValidationException::field('label', 'Bu adda bir rol var.');
            }
        }
        self::persist($slug, self::row($role, [
            'label' => $label,
            'description' => mb_substr(Text::field($input['description'] ?? $role['description']), 0, 255),
        ]));
        self::forget();

        return self::get($slug);
    }

    public static function delete(string $slug): void
    {
        $role = self::mustGet($slug);
        if ($role['system']) {
            throw new ConflictException('km_role_system', 'Hazır roller silinemez; yetkilerini değiştirebilirsiniz.');
        }
        $users = DB::table('users')->where('role', $slug)->count();
        if ($users > 0) {
            throw new ConflictException('km_role_in_use', sprintf('Bu rolde %d kullanıcı var. Önce kullanıcılara başka rol verin.', $users));
        }
        DB::table('roles')->where('slug', $slug)->delete();
        self::forget();
    }

    // ------------------------------------------------------------ yardımcılar

    private static function resolve(string $slug, ?array $def, ?object $row): array
    {
        $all = Capability::keys();
        $locked = $slug === self::ADMIN;
        // Hazır rolün yetkileri de yetki listesiyle kesişir: kapalı modülün yetkisi kimsede yok (Core\Modules).
        $defaultCaps = $def === null ? [] : ($def['caps'] === '*' ? $all : array_values(array_intersect($all, array_unique($def['caps']))));
        $stored = $row && $row->caps !== null ? (json_decode((string) $row->caps, true) ?: []) : null;
        $caps = $locked ? $all : ($stored !== null ? array_values(array_intersect($all, $stored)) : $defaultCaps);
        $menu = $row && $row->menu ? json_decode((string) $row->menu, true) : null;

        return [
            'slug' => $slug,
            'label' => $row?->label ?: ($def['label'] ?? $slug),
            'description' => $row?->description ?: ($def['description'] ?? ''),
            'caps' => $caps,
            'default_caps' => $def === null ? $caps : $defaultCaps,
            'customized' => ! $locked && $def !== null && $stored !== null,
            'system' => $def !== null,
            'locked' => $locked,
            // Eski kayıtların adresleri (`#/projects`) sayfa anahtarına çevrilir.
            'menu' => is_array($menu) && $menu ? array_map(static fn ($g) => ['items' => array_values(array_filter(array_map(static fn ($i) => self::pageKey((string) $i), (array) ($g['items'] ?? []))))] + (array) $g, $menu) : null,
        ];
    }

    private static function mustGet(string $slug): array
    {
        return self::get($slug) ?? throw NotFoundException::message('Rol bulunamadı.');
    }

    private static function row(array $role, array $changes): array
    {
        return $changes + [
            'label' => $role['label'],
            'description' => $role['description'],
            'system' => $role['system'],
            'updated_at' => Clock::now(),
        ];
    }

    /** Hazır rolün satırı ilk değişiklikte oluşur. */
    private static function persist(string $slug, array $values): void
    {
        if (DB::table('roles')->where('slug', $slug)->exists()) {
            DB::table('roles')->where('slug', $slug)->update($values);
        } else {
            DB::table('roles')->insert($values + ['slug' => $slug, 'created_at' => Clock::now()]);
        }
    }

    /** Bilinen yetkiler; panele giriş daima var, rol düzenleme yalnız Yönetici'de. @return list<string> */
    private static function cleanCaps(array $caps): array
    {
        $caps = array_values(array_intersect(Capability::keys(), array_map('strval', $caps)));
        if (! in_array(Capability::ACCESS_PANEL, $caps, true)) {
            array_unshift($caps, Capability::ACCESS_PANEL);
        }

        return array_values(array_diff($caps, [Capability::MANAGE_ROLES]));
    }

    /** @return list<array{key:string,label:string,items:list<string>}> */
    private static function cleanMenu(array $groups): array
    {
        if (count($groups) > self::MAX_GROUPS) {
            throw ValidationException::field('groups', sprintf('Menüde en fazla %d grup olabilir.', self::MAX_GROUPS));
        }
        $out = [];
        $seen = [];
        $keys = [];
        foreach (array_values($groups) as $i => $group) {
            // Başlıksız grup olabilir (varsayılan menüde Genel bakış ve Proje Ayarları): panelde açılıp kapanmaz.
            $label = mb_substr(Text::field($group['label'] ?? ''), 0, 40);
            $key = strtolower((string) ($group['key'] ?? ''));
            if (! preg_match('/^[a-z0-9\-]{1,40}$/', $key) || isset($keys[$key])) {
                $key = 'g'.($i + 1).'-'.substr(md5($label.$i), 0, 6);
            }
            $keys[$key] = true;
            $items = [];
            foreach ((array) ($group['items'] ?? []) as $item) {
                $page = self::pageKey((string) $item);
                if ($page === null) {
                    throw ValidationException::field('groups', sprintf('"%s" geçerli bir panel sayfası değil.', mb_substr((string) $item, 0, 60)));
                }
                if (! isset($seen[$page])) {
                    $seen[$page] = true;
                    $items[] = $page;
                }
            }
            if (count($items) > self::MAX_ITEMS) {
                throw ValidationException::field('groups', sprintf('%s grubunda en fazla %d sayfa olabilir.', $label, self::MAX_ITEMS));
            }
            if ($items) {
                $out[] = ['key' => $key, 'label' => $label, 'items' => $items];
            }
        }
        if (! $out) {
            throw ValidationException::field('groups', 'Menüde en az bir sayfa olmalı.');
        }

        return $out;
    }

    /**
     * Menü öğesi → sayfa anahtarı. Eski kayıtlar adres saklıyordu (`#/projects`,
     * `#/jarvis/domains`, `#/`); onlar da çevrilir. Geçersizse null.
     */
    public static function pageKey(string $item): ?string
    {
        $item = trim($item);
        if (str_starts_with($item, '#')) {
            $path = trim((string) preg_replace('#^\#/?#', '', $item), '/');
            $item = match ($path) {
                '' => 'overview',
                'jarvis/domains' => 'domains',
                default => str_contains($path, '/') ? '' : $path,
            };
        }

        return preg_match(self::PAGE_KEY, $item) ? $item : null;
    }
}
