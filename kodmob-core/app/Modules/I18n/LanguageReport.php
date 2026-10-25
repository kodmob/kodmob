<?php

namespace App\Modules\I18n;

use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Content\Types;
use App\Core\Support\Clock;
use App\Core\Support\Options;
use App\Core\Support\Paths;
use App\Modules\Blog\BlogPosts;
use App\Modules\Content\PageTexts;
use App\Modules\Seo\Services\LinkChecker;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;

/**
 * Çoklu Dil sayfasının verisi (done-coklu-dil-raporu.md §5): genel bakış (dil başına içerik ve metin
 * durumu), sağlık (sorun listesi, her satırda düzeltme bağlantısı), adresler (derlemedeki rota ×
 * dil tablosu, eski adresler, adres geçmişi) ve ziyaretçi ayarları (404'te sık ziyaret edilenler).
 *
 * Sağlık bağlantıları panel rota adıyla döner ({ route, params, query }); adresi panel kurar.
 */
final class LanguageReport
{
    /** İçerik türü → panel adı (kayıttan, Types). */
    private static function typeLabels(): array
    {
        return array_map(static fn ($content) => $content->labels()[0], Types::editable());
    }

    /** 404 sayfasındaki "sık ziyaret edilenler" (dil → [{ label, url }]). */
    public const POPULAR_OPTION = 'i18n.popular';

    /** @var array<string,array<string,array{published:int,missing:int,stale:int}>>|null tür → dil → sayılar */
    private ?array $content = null;

    public function overview(): array
    {
        $built = LanguageService::builtLocales();
        $content = $this->content();
        $texts = $this->texts();
        $health = $this->health();

        return [
            'enabled' => I18nSettings::enabled(),
            'default' => Locales::default(),
            'languages' => array_map(static fn (array $l) => [
                'code' => $l['code'],
                'name' => $l['name'],
                'native' => $l['native'],
                'prefix' => $l['prefix'],
                'flag' => $l['flag'],
                'active' => $l['active'],
                'default' => $l['default'],
                'built' => $built === null || in_array($l['code'], $built, true),
                'live' => in_array($l['code'], Locales::active(), true),
                'content' => array_map(static fn (array $byLocale) => $byLocale[$l['code']] ?? ['published' => 0, 'missing' => 0, 'stale' => 0], $content),
                'texts' => $texts[$l['code']] ?? ['total' => 0, 'edited' => 0, 'outdated' => 0],
            ], Languages::all()),
            'types' => self::typeLabels(),
            'health' => [
                'errors' => count(array_filter($health, static fn ($i) => $i['level'] === 'error')),
                'warnings' => count(array_filter($health, static fn ($i) => $i['level'] === 'warning')),
                'total' => count($health),
            ],
        ];
    }

    /**
     * Sağlık: sorunlar önem sırasıyla.
     *
     * @return list<array{level:string,key:string,title:string,detail:string,count:int,link:?array}>
     */
    public function health(): array
    {
        $items = [];
        $labels = Locales::labels();
        $default = Locales::default();
        $live = Locales::active();

        foreach (LinkChecker::last()['items'] as $link) {
            if ($link['problem'] !== 'redirect') {
                $items['links'] ??= ['level' => 'error', 'key' => 'links', 'title' => 'İçerikte kırık bağlantı', 'detail' => '', 'count' => 0, 'link' => ['route' => 'seo', 'params' => ['tab' => '404']]];
                $items['links']['count']++;
                $items['links']['detail'] = sprintf('"%s" içinde %s', $link['title'], $link['url']).($items['links']['count'] > 1 ? ' ve diğerleri' : '');
            }
        }

        foreach ($this->content() as $type => $byLocale) {
            foreach ($byLocale as $code => $n) {
                if (! in_array($code, $live, true) || $code === $default) {
                    continue;
                }
                // Liste tablolarının "Çeviri" süzgeci (adreste: ?translation=missing|stale).
                $list = ['route' => 'list', 'params' => ['type' => $type], 'query' => ['translation' => 'missing']];
                if ($n['missing'] > 0) {
                    $items["missing.$type.$code"] = ['level' => 'warning', 'key' => "missing.$type.$code", 'title' => sprintf('%s: %s çevirisi eksik', self::typeLabels()[$type], $labels[$code] ?? $code),
                        'detail' => $type === 'posts' ? 'Bu dilde karşılığı olmayan yayındaki yazılar.' : 'Bu dilde eklenmemiş ya da taslak olan yayındaki kayıtlar.', 'count' => $n['missing'], 'link' => $list];
                }
                if ($n['stale'] > 0) {
                    $items["stale.$type.$code"] = ['level' => 'warning', 'key' => "stale.$type.$code", 'title' => sprintf('%s: %s çevirisi güncellenmeli', self::typeLabels()[$type], $labels[$code] ?? $code),
                        'detail' => 'Kaynak metin bu çeviriden sonra değişti.', 'count' => $n['stale'], 'link' => ['route' => 'list', 'params' => ['type' => $type], 'query' => ['translation' => 'stale']]];
                }
            }
        }

        foreach ($this->texts() as $code => $n) {
            if ($n['outdated'] > 0 && in_array($code, $live, true)) {
                $items["texts.$code"] = ['level' => 'warning', 'key' => "texts.$code", 'title' => sprintf('Sayfa metinleri: %s güncellenmeli', $labels[$code] ?? $code),
                    'detail' => sprintf('%s metni değiştirildi, %s karşılığı sözlükteki halinde.', $labels[$default] ?? $default, $labels[$code] ?? $code), 'count' => $n['outdated'],
                    'link' => ['route' => 'texts', 'params' => [], 'query' => ['dil' => $code, 'filtre' => 'guncellenmeli']]];
            }
        }

        $orphans = $this->orphanPosts();
        if ($orphans > 0) {
            $items['orphans'] = ['level' => 'info', 'key' => 'orphans', 'title' => sprintf('Blog: %s kaynağı olmayan çeviri', $labels[$default] ?? $default), 'detail' => 'Grubunda varsayılan dilde yazı yok; kaynak olarak ilk yazılan kullanılıyor.', 'count' => $orphans, 'link' => ['route' => 'list', 'params' => ['type' => 'posts']]];
        }

        $built = LanguageService::builtLocales();
        foreach (Languages::all() as $l) {
            $isBuilt = $built === null || in_array($l['code'], $built, true);
            if (! $isBuilt) {
                $items['unbuilt.'.$l['code']] = ['level' => 'info', 'key' => 'unbuilt.'.$l['code'], 'title' => sprintf('%s sitenin derlemesinde yok', $l['name']), 'detail' => 'Sayfaları olmadığı için yayına alınamaz; dil derlemeye eklenince (build) açılabilir.', 'count' => 1, 'link' => ['route' => 'languages', 'params' => ['tab' => 'list']]];
            } elseif (! $l['active']) {
                $items['inactive.'.$l['code']] = ['level' => 'info', 'key' => 'inactive.'.$l['code'], 'title' => sprintf('%s yayında değil', $l['name']), 'detail' => 'Derlemede var; adresleri varsayılan dile yönleniyor.', 'count' => 1, 'link' => ['route' => 'languages', 'params' => ['tab' => 'list']]];
            }
        }
        if (! I18nSettings::enabled()) {
            $items['single'] = ['level' => 'info', 'key' => 'single', 'title' => 'Tek dilli mod açık', 'detail' => 'Yalnızca varsayılan dil yayında; diğer dillerin adresleri varsayılan dile yönleniyor.', 'count' => 1, 'link' => ['route' => 'languages', 'params' => []]];
        }

        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        $items = array_values($items);
        usort($items, static fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $items;
    }

    /** Derlemedeki adres yapısı (salt okunur) ve adres geçmişi. */
    public function urls(): array
    {
        $router = Router::load(Paths::siteRoot());
        $manifest = $router?->manifest() ?? [];
        $routes = [];
        foreach ((array) ($manifest['routes'] ?? []) as $route) {
            if (in_array($route['name'], ['panel', 'ui', 'uiPanel'], true)) {
                continue;
            }
            $routes[$route['name']] ??= ['name' => $route['name'], 'paths' => []];
            $routes[$route['name']]['paths'][$route['locale']] = $route['path'] ?? $route['pattern'] ?? '';
        }
        $history = DB::table('slug_history')->orderByDesc('created_at')->limit(100)->get()->map(fn ($row) => [
            'type' => $row->type,
            'locale' => $row->locale,
            'from' => $router?->url($row->type, $row->locale, $row->slug),
            'record_id' => (int) $row->record_id,
            'created_at' => Clock::atom($row->created_at),
        ])->all();

        return [
            'default' => $manifest['default'] ?? Locales::default(),
            'locales' => array_map(static fn ($l) => ['prefix' => $l['prefix'] ?? '', 'label' => $l['label'] ?? ''], (array) ($manifest['locales'] ?? [])),
            'routes' => array_values($routes),
            'legacy' => [
                'prefixes' => (array) ($manifest['legacy']['prefixes'] ?? []),
                'paths' => array_values((array) ($manifest['legacy']['paths'] ?? [])),
            ],
            'history' => $history,
            'history_total' => DB::table('slug_history')->count(),
            'built' => $manifest['built'] ?? null,
        ];
    }

    /** @return array<string,list<array{label:string,url:string}>> */
    public static function popular(): array
    {
        $value = Options::get(self::POPULAR_OPTION, []);

        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $input dil → [{ label, url }] (en fazla 6; adres site içi, `/` ile başlar) */
    public static function savePopular(array $input): array
    {
        $clean = [];
        foreach (Locales::all() as $code) {
            $links = [];
            foreach (array_slice((array) ($input[$code] ?? []), 0, 6) as $link) {
                $label = mb_substr(trim(strip_tags((string) ($link['label'] ?? ''))), 0, 60);
                $url = trim((string) ($link['url'] ?? ''));
                if ($label !== '' && preg_match('#^/[^\s<>"]*$#', $url)) {
                    $links[] = ['label' => $label, 'url' => $url];
                }
            }
            if ($links) {
                $clean[$code] = $links;
            }
        }
        Options::set(self::POPULAR_OPTION, $clean);

        return $clean;
    }

    // ------------------------------------------------------------ iç

    /** @return array<string,array<string,array{published:int,missing:int,stale:int}>> */
    private function content(): array
    {
        if ($this->content !== null) {
            return $this->content;
        }
        $codes = Locales::all();
        $blank = static fn () => array_fill_keys($codes, ['published' => 0, 'missing' => 0, 'stale' => 0]);
        $out = [];
        foreach (Types::all() as $name => $type) {
            $out[$name] = $blank();
            foreach ($type->records(true) as $record) {
                $states = array_column($type->languageStates($record->data), null, 'code');
                foreach ($codes as $code) {
                    if ($type->visibleIn($record->data, $code)) {
                        $out[$name][$code]['published']++;
                        $out[$name][$code]['stale'] += ! empty($states[$code]['stale']) ? 1 : 0;
                    } else {
                        $out[$name][$code]['missing']++;
                    }
                }
            }
        }
        $out['posts'] = $blank();
        $groups = [];
        foreach (DB::table('posts')->whereNull('deleted_at')->where('status', 'publish')->where('published_at', '<=', Clock::now())->orderBy('id')->get() as $row) {
            $groups[(string) $row->translation_group][Locales::normalize((string) $row->locale)] ??= (array) $row;
        }
        foreach ($groups as $group) {
            foreach ($codes as $code) {
                if (isset($group[$code])) {
                    $out['posts'][$code]['published']++;
                    $out['posts'][$code]['stale'] += BlogPosts::staleIn($group[$code], $group) ? 1 : 0;
                } else {
                    $out['posts'][$code]['missing']++;
                }
            }
        }

        return $this->content = $out;
    }

    /**
     * Sayfa metinleri, dil başına: toplam, değiştirilen ve "güncellenmeli" (varsayılan dildeki
     * metin değiştirilmiş ama bu dildeki karşılığı sözlükteki halinde).
     *
     * @return array<string,array{total:int,edited:int,outdated:int}>
     */
    private function texts(): array
    {
        $definitions = PageTexts::definitions();
        $overrides = PageTexts::overrides();
        $default = Locales::default();
        $out = [];
        foreach (Locales::all() as $code) {
            $out[$code] = ['total' => count($definitions), 'edited' => count((array) ($overrides[$code] ?? [])), 'outdated' => 0];
            if ($code === $default) {
                continue;
            }
            foreach (array_keys((array) ($overrides[$default] ?? [])) as $key) {
                if (isset($definitions[$key]) && ! isset($overrides[$code][$key])) {
                    $out[$code]['outdated']++;
                }
            }
        }

        return $out;
    }

    /** Grubunda varsayılan dilde yazı olmayan, başka yazıyla gruplanmış yazılar. */
    private function orphanPosts(): int
    {
        $default = Locales::default();
        $count = 0;
        foreach (DB::table('posts')->whereNull('deleted_at')->get(['translation_group', 'locale'])->groupBy('translation_group') as $rows) {
            $locales = $rows->pluck('locale')->map(static fn ($l) => Locales::normalize((string) $l))->all();
            if (count($locales) > 1 && ! in_array($default, $locales, true)) {
                $count += count($locales);
            }
        }

        return $count;
    }
}
