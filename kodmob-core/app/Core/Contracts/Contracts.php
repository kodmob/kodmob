<?php

namespace App\Core\Contracts;

use App\Core\Activity\ActivityCatalog;
use App\Core\Module;
use App\Core\Modules;
use App\Core\Security\Capability;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Çatının sözleşmesi (docs/done-sozlesme-raporu.md §4.1): modüller ve her birinin uçları, yetkileri, ayarları, olayları,
 * zamanlanmış görevleri; koddan üretilir, elle yazılmaz. `php artisan kodmob:contracts` docs/contracts/'a yazar,
 * ContractsTest depodakiyle karşılaştırır (uç, yetki, ayar ya da olay değişince sözleşme de değişmeli).
 *
 * Çıktı ortamdan bağımsızdır: kapalı modüller de listelenir (sınıfları doğrudan okunur), içerik türlerinin
 * düzenleme yetkileri (projeye göre değişir) `@content` olarak yazılır, araç paketleri ve tarih girmez.
 */
final class Contracts
{
    /** Çekirdek alanların adları (modül sınıfı olanlar ve yalnızca uçları olanlar). */
    private const AREAS = [
        'system' => 'Sistem', 'site' => 'Site', 'media' => 'Medya', 'notifications' => 'Bildirimler', 'live' => 'Canlı şerit',
        'security' => 'Güvenlik', 'seo' => 'SEO', 'i18n' => 'Çoklu dil', 'content' => 'İçerik', 'auth' => 'Oturum',
        'users' => 'Kullanıcılar ve roller', 'history' => 'İşlem geçmişi', 'records' => 'Silinenler', 'setup' => 'Kurulum',
        'project' => 'Proje',
    ];

    /** @return array{modules:array<string,array>,capabilities:array<string,array>} */
    public static function build(): array
    {
        $modules = self::modules();
        $known = [];
        foreach (Capability::catalog() as $cap => $def) {
            $known[$cap] = ['label' => $def['label'], 'group' => $def['group'], 'module' => $def['module'] ?? 'core'];
        }
        foreach ($modules as $key => $module) {
            foreach ($module['instance']?->capabilities() ?? [] as $cap => $def) {
                $known[$cap] = ['label' => (string) $def['label'], 'group' => (string) ($def['group'] ?? 'Proje'), 'module' => $key];
            }
        }
        ksort($known);

        $routes = self::routes($modules, $known);
        $out = [];
        foreach ($modules as $key => $module) {
            $instance = $module['instance'];
            $out[$key] = array_filter([
                'label' => $module['label'],
                'kind' => $module['kind'],
                'requires' => $module['requires'] ?: null,
                'scope' => $key === 'sites' ? 'main' : 'each',
                'routes' => array_values(array_map(static fn ($r) => array_diff_key($r, ['module' => 1]), array_filter($routes, static fn ($r) => $r['module'] === $key))),
                'capabilities' => array_keys(array_filter($known, static fn ($c) => $c['module'] === $key)) ?: null,
                'settings' => $instance ? self::settings($instance) : null,
                'events' => $instance ? self::events($instance) : null,
                'schedule' => $instance ? self::schedule($instance) : null,
            ], static fn ($v) => $v !== null && $v !== []);
        }
        ksort($out);

        return ['modules' => $out, 'capabilities' => $known];
    }

    /** @return array<string,string> docs/contracts/ dosyaları: ad → içerik (modules.json, openapi.json) */
    public static function files(): array
    {
        $contract = self::build();

        return [
            'modules.json' => OpenApi::encode($contract),
            'openapi.json' => OpenApi::encode(OpenApi::build($contract, RequestSchemas::bodies())),
        ];
    }

    /** @return array<string,array{label:string,kind:string,requires:list<string>,class:?string,instance:?Module,namespace:?string}> */
    private static function modules(): array
    {
        $list = [];
        $add = static function (string $key, string $label, string $kind, ?string $class, array $requires = []) use (&$list) {
            $list[$key] = [
                'label' => $label,
                'kind' => $kind,
                'requires' => array_values($requires),
                'class' => $class,
                'instance' => $class ? new $class : null,
                'namespace' => $class ? substr($class, 0, (int) strrpos($class, '\\')) : null,
            ];
        };
        foreach (Modules::CORE as $class) {
            $key = strtolower(preg_replace('/Module$/', '', class_basename($class)) ?? '');
            $add($key, self::AREAS[$key] ?? $key, 'core', $class);
        }
        foreach (Modules::OPTIONAL as $key => $def) {
            $add($key, $def['label'], $def['kind'], $def['class'], (array) ($def['requires'] ?? []));
        }
        foreach ((array) config('project.modules', []) as $class) {
            $add(lcfirst(preg_replace('/Module$/', '', class_basename($class)) ?? ''), class_basename($class), 'project', $class);
        }
        foreach (['content', 'auth', 'users', 'history', 'records', 'setup', 'project'] as $key) {
            if (! isset($list[$key])) {
                $add($key, self::AREAS[$key], 'core', null);
            }
        }

        return $list;
    }

    /** @return list<array<string,mixed>> */
    private static function routes(array $modules, array $known): array
    {
        $titles = ActivityCatalog::types();
        foreach ($modules as $module) {
            foreach ($module['instance']?->events() ?? [] as $type => $def) {
                $titles[$type] ??= $def;
            }
        }
        $out = [];
        /** @var Route $route */
        foreach (Router::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            if ($action !== 'Closure' && ! str_starts_with($action, 'App\\')) {
                continue; // araç paketleri sözleşmeye girmez
            }
            $middleware = $route->gatherMiddleware();
            $caps = [];
            $module = null;
            $throttle = null;
            foreach ($middleware as $m) {
                if (! is_string($m)) {
                    continue;
                }
                if (str_starts_with($m, 'cap:')) {
                    foreach (explode('|', substr($m, 4)) as $cap) {
                        $caps[] = isset($known[$cap]) ? $cap : '@content';
                    }
                } elseif (str_starts_with($m, 'module:')) {
                    $module = substr($m, 7);
                } elseif (str_starts_with($m, 'throttle:')) {
                    $throttle = str_replace(',', '/', substr($m, 9));
                }
            }
            $caps = array_values(array_unique(array_diff($caps, [Capability::ACCESS_PANEL])));
            sort($caps);
            $name = $route->getName();
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = array_filter([
                    'method' => $method,
                    'path' => '/'.$route->uri(),
                    'name' => $name,
                    'summary' => $name !== null && isset($titles[$name]) ? $titles[$name][0] : null,
                    'module' => $module ?? self::moduleOf($action, $modules),
                    'auth' => in_array('auth.jwt', $middleware, true),
                    'caps' => $caps ?: null,
                    'throttle' => $throttle,
                ], static fn ($v) => $v !== null);
            }
        }
        usort($out, static fn ($a, $b) => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return $out;
    }

    /** Denetleyicinin modülü: en uzun ad alanı eşleşmesi; modül sınıfı olmayan alan ad alanının adıyla. */
    private static function moduleOf(string $action, array $modules): string
    {
        if ($action === 'Closure') {
            return 'system';
        }
        $best = null;
        foreach ($modules as $key => $module) {
            $ns = $module['namespace'];
            if ($ns && str_starts_with($action, $ns.'\\') && ($best === null || strlen($ns) > strlen($modules[$best]['namespace']))) {
                $best = $key;
            }
        }
        if ($best !== null) {
            return $best;
        }
        if (preg_match('/^App\\\\Modules\\\\([A-Za-z0-9]+)\\\\/', $action, $m)) {
            return strtolower($m[1]);
        }

        return str_starts_with($action, 'App\\Project\\') ? 'project' : (str_starts_with($action, 'App\\Core\\Setup\\') ? 'setup' : 'system');
    }

    /** @return list<array<string,mixed>> */
    private static function settings(Module $module): array
    {
        return array_map(static fn ($d) => array_filter([
            'key' => $d->key,
            'type' => $d->type,
            'default' => $d->format === 'secret' ? null : $d->default,
            'label' => $d->label,
            'group' => $d->group,
            'scope' => $d->scope ?: null,
            'format' => $d->format ?: null,
            'min' => $d->min,
            'max' => $d->max,
            'options' => $d->options ? array_map('strval', array_keys($d->options)) : null,
        ], static fn ($v) => $v !== null), $module->settings());
    }

    /** @return array<string,string> olay türü → başlık */
    private static function events(Module $module): array
    {
        $out = array_map(static fn ($def) => (string) $def[0], $module->events());
        ksort($out);

        return $out;
    }

    /** @return array<string,string> görev adı → cron ifadesi */
    private static function schedule(Module $module): array
    {
        $schedule = new Schedule;
        $module->schedule($schedule);
        $out = [];
        foreach ($schedule->events() as $event) {
            $out[(string) $event->description] = $event->expression;
        }
        ksort($out);

        return $out;
    }
}
