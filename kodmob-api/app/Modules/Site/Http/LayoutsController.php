<?php

namespace App\Modules\Site\Http;

use App\Core\Activity\Activity;
use App\Core\Content\Locales;
use App\Core\Content\MediaPayload;
use App\Core\Content\Types;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Support\Paths;
use App\Modules\Blog\BlogPosts;
use App\Modules\Media\MediaLibrary;
use App\Modules\Site\Blocks\BlockAssist;
use App\Modules\Site\Blocks\BlockData;
use App\Modules\Site\Blocks\BlockLibrary;
use App\Modules\Site\Blocks\BlockRenderer;
use App\Modules\Site\Blocks\Layouts;
use App\Modules\Site\Blocks\PreviewToken;
use App\Modules\Site\Blocks\ZoneDefaults;
use App\Modules\Site\Pages\PageType;
use App\Modules\Site\Render\Router;
use Illuminate\Http\Request;

/**
 * Sayfa düzenleyici (panel › Sayfa düzenleyici; docs/done-onyuz-raporu.md §4) ve Keşfet'in blokları.
 *
 *   GET    /admin/layouts                         kitaplık, sayfalar, bölgeler (yayındaki, taslak, varsayılan)
 *   PUT    /admin/layouts/{zone}                  { blocks } taslak (otomatik kayıt; işlem geçmişine yazılmaz)
 *   DELETE /admin/layouts/{zone}/draft            taslağı at
 *   POST   /admin/layouts/publish                 { zones? } taslakları yayınla (sayfa önbelleği temizlenir)
 *   POST   /admin/layouts/render                  { block, locale } tek bloğun önizleme HTML'i
 *   GET    /admin/layouts/{zone}/revisions        yayın geçmişi; POST …/revisions/{id} taslağa alır
 *   POST   /admin/layouts/preview                 önizleme anahtarı (çerçevedeki sayfa için; 2 saat)
 *   POST   /admin/layouts/assist | translate | suggest   Jarvis: blok metni yaz, çevir, sayfaya bölüm öner
 *   GET    /admin/blocks                          Keşfet: bloklar, kategorileri, kitaplıkta mı
 *   PUT    /admin/blocks/{type}                   { added } kitaplığa ekle / çıkar
 */
final class LayoutsController extends Controller
{
    public function index(): array
    {
        $data = BlockLibrary::data();
        $router = Router::load(Paths::siteRoot());
        $records = $this->blockPages($router);
        $zones = BlockLibrary::zones();
        // Blok sayfalarının bölgeleri (`page.main@12`): şablondaki bölgenin tanımıyla, varsayılanı boş.
        foreach ($records as $page) {
            $zones[$page['zone']] = ['label' => 'Sayfa içeriği', 'routes' => [PageType::ROUTE], 'blocks' => []];
        }
        $state = Layouts::state(array_keys($zones));
        $out = [];
        $images = [];
        foreach ($zones as $key => $zone) {
            // İlk bloklar panelde değiştirilmiş sayfa metinleriyle (ZoneDefaults).
            $defaults = str_contains($key, '@') ? [] : ZoneDefaults::blocks($key);
            foreach ([$defaults, $state[$key]['published'] ?? [], $state[$key]['draft'] ?? []] as $blocks) {
                array_push($images, ...Layouts::images((array) $blocks));
            }
            $out[$key] = [
                'label' => (string) ($zone['label'] ?? $key),
                'routes' => array_values((array) ($zone['routes'] ?? [])),
                'defaults' => BlockData::objects($defaults),
                'published' => $state[$key]['published'] === null ? null : BlockData::objects($state[$key]['published']),
                'draft' => $state[$key]['draft'] === null ? null : BlockData::objects($state[$key]['draft']),
            ] + array_intersect_key($state[$key], array_flip(['draftAt', 'draftBy', 'publishedAt', 'publishedBy']));
        }

        return [
            'built' => $router !== null && is_file(BlockLibrary::file()),
            'blocks' => (object) $this->blocks(),
            'categories' => (object) $data['categories'],
            'icons' => $data['icons'],
            'pages' => [
                ...array_map(fn (array $page) => $page + ['samples' => (object) ($router ? $this->samples($page, $router) : [])], BlockLibrary::pages()),
                ...array_map(static fn (array $page) => array_diff_key($page, ['zone' => 1]), $records),
            ],
            'zones' => (object) $out,
            'locales' => Locales::all(),
            'defaultLocale' => Locales::default(),
            // Jarvis düğmeleri (anahtar yoksa gizli).
            'ai' => BlockAssist::available(),
            // Bloklardaki görsellerin küçük resmi (düzenleyicinin alanında): kimlik → adres.
            'media' => (object) $this->thumbs($images),
        ];
    }

    /** @param list<int> $ids @return array<int,string> */
    private function thumbs(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        MediaLibrary::prime($ids);
        $out = [];
        foreach ($ids as $id) {
            if ($image = MediaPayload::admin($id)) {
                $out[$id] = (string) ($image['thumb'] ?: $image['src']);
            }
        }

        return $out;
    }

    public function save(Request $request, string $zone): array
    {
        $blocks = Layouts::saveDraft($zone, $this->input($request)['blocks'] ?? null, $request->user()?->id);

        return ['zone' => $zone, 'draft' => BlockData::objects($blocks)] + array_intersect_key(Layouts::state([$zone])[$zone], array_flip(['draftAt', 'draftBy']));
    }

    public function discard(string $zone): array
    {
        Layouts::discard($zone);
        Activity::with(['zone' => $zone, 'label' => $this->zoneLabel($zone)]);

        return ['zone' => $zone] + Layouts::state([$zone])[$zone];
    }

    /**
     * Taslakları yayınlar. `pages`: taslaktaki blok sayfaları da yayına alınır (içerik yayınlama ve sayfa yetkisi
     * gerekir; yoksa bölgeler yine yayınlanır, sayfa taslakta kalır).
     */
    public function publish(Request $request): array
    {
        $in = $this->input($request);
        $zones = $in['zones'] ?? null;
        $zones = is_array($zones) ? array_values(array_filter($zones, 'is_string')) : null;
        $type = new PageType;
        $pages = [];
        if (is_array($in['pages'] ?? null) && $request->user()->can(Capability::PUBLISH_CONTENT) && $request->user()->can($type->capability())) {
            foreach (array_unique(array_map('intval', $in['pages'])) as $id) {
                if ($id > 0 && $type->find($id) && $type->publish($id)) {
                    $pages[] = $id;
                }
            }
        }
        $published = Layouts::publish($zones, $request->user()?->id);
        Activity::with(['count' => count($published), 'zones' => array_map(fn ($z) => $this->zoneLabel($z), $published), 'pages' => array_map(fn ($id) => $this->zoneLabel(PageType::zone($id)), $pages)]);

        return ['published' => $published, 'zones' => (object) Layouts::state($published), 'pages' => $pages];
    }

    /**
     * Önizleme HTML'i: `block` (tek blok; alan değişince yalnızca o yenilenir) ya da `blocks` (bölgenin tamamı;
     * geri al, yapıştır, eski hal) → `html` / `items: [{ id, html }]`.
     */
    public function render(Request $request): array
    {
        $in = $this->input($request);
        $locale = Locales::normalize((string) ($in['locale'] ?? ''));
        $router = Router::load(Paths::siteRoot());
        if (! $router) {
            throw ValidationException::field('block', 'Site derlemesi bulunamadı.');
        }
        $renderer = new BlockRenderer($router);
        if (array_key_exists('blocks', $in)) {
            $blocks = BlockData::cleanZone($in['blocks'], Locales::all());

            return ['items' => array_map(static fn (array $b) => ['id' => $b['id'], 'html' => $renderer->render($b, $locale, true)], $blocks)];
        }
        if (! is_array($in['block'] ?? null)) {
            throw ValidationException::field('block', 'Blok verisi eksik.');
        }
        $block = BlockData::clean($in['block'], Locales::all());

        return ['html' => $renderer->render($block, $locale, true), 'block' => BlockData::objects([$block])[0]];
    }

    /** Jarvis: bloğun bir dildeki metinlerini isteğe göre yazar (kaydetmez) → { texts }. */
    public function assist(Request $request): array
    {
        BlockAssist::ensureAvailable();
        $in = $this->input($request);
        $locale = Locales::normalize((string) ($in['locale'] ?? ''));

        return ['texts' => (object) (new BlockAssist)->write((string) ($in['type'] ?? ''), $locale, (array) ($in['values'] ?? []), (string) ($in['instruction'] ?? ''))];
    }

    /** Jarvis: bloğun metinlerini diğer dillere çevirir (kaydetmez) → { i18n: { dil: metinler } }. */
    public function translate(Request $request): array
    {
        BlockAssist::ensureAvailable();
        $in = $this->input($request);

        return ['i18n' => (object) (new BlockAssist)->translate((string) ($in['type'] ?? ''), (string) ($in['from'] ?? ''), array_values((array) ($in['to'] ?? [])), (array) ($in['values'] ?? []))];
    }

    /** Jarvis: sayfaya bölüm önerisi (kitaplıktaki bloklardan) → { blocks: [{ type, values }] }. */
    public function suggest(Request $request): array
    {
        BlockAssist::ensureAvailable();
        $in = $this->input($request);
        // Kayıt sayfası blokları (`scope: entry`) içeriği kayıttan alır: öneriye girmez.
        $library = array_values(array_filter(array_keys(BlockLibrary::blocks()), static fn ($t) => BlockLibrary::inLibrary($t) && (BlockLibrary::block($t)['scope'] ?? '') !== 'entry'));

        return ['blocks' => (new BlockAssist)->suggest(mb_substr((string) ($in['page'] ?? ''), 0, 80), Locales::normalize((string) ($in['locale'] ?? '')), (string) ($in['brief'] ?? ''), $library)];
    }

    public function revisions(string $zone): array
    {
        return ['items' => Layouts::revisions($zone)];
    }

    public function restore(Request $request, string $zone, int $id): array
    {
        $blocks = Layouts::restore($zone, $id, $request->user()?->id);
        Activity::with(['zone' => $zone, 'label' => $this->zoneLabel($zone)]);

        return ['zone' => $zone, 'draft' => BlockData::objects($blocks)];
    }

    public function preview(Request $request): array
    {
        return PreviewToken::issue(storage_path(), (int) $request->user()->id) + ['param' => PreviewToken::PARAM];
    }

    /** Keşfet › Bloklar: kategorileriyle, kitaplıkta mı. */
    public function catalog(): array
    {
        return ['items' => array_values($this->blocks()), 'categories' => (object) BlockLibrary::data()['categories']];
    }

    public function toggle(Request $request, string $type): array
    {
        $block = BlockLibrary::block($type);
        if ($block === null) {
            throw ValidationException::field('type', 'Bu blok bu projede yok.');
        }
        if (! ($block['library'] ?? true)) {
            BlockLibrary::setAdded($type, filter_var($this->input($request)['added'] ?? false, FILTER_VALIDATE_BOOLEAN));
        }
        $view = $this->blocks()[$type];
        Activity::with(['block' => $view['label'], 'added' => $view['inLibrary']]);

        return $view;
    }

    /** Panelin gördüğü blok tanımları (şablon ve derleme ayrıntısı olmadan). @return array<string,array> */
    private function blocks(): array
    {
        $out = [];
        foreach (BlockLibrary::blocks() as $type => $block) {
            $out[$type] = [
                'type' => $type,
                'label' => (string) $block['label'],
                'description' => (string) ($block['description'] ?? ''),
                'category' => (string) ($block['category'] ?? 'section'),
                'icon' => (string) ($block['icon'] ?? 'layers'),
                'preview' => (string) ($block['preview'] ?? ''),
                'source' => (string) ($block['source'] ?? 'framework'),
                'needs' => array_values((array) ($block['needs'] ?? [])),
                // `entry`: içeriği sayfadaki kayıttan (yalnızca kayıt sayfası şablonunda eklenir; tur programı…).
                'scope' => (string) ($block['scope'] ?? ''),
                'fields' => array_values((array) ($block['fields'] ?? [])),
                'defaults' => ['data' => (object) ($block['defaults']['data'] ?? []), 'i18n' => (object) array_map(static fn ($v) => (object) $v, (array) ($block['defaults']['i18n'] ?? []))],
                // Varsayılan kitaplıktaki (`library: true`) blok hep eklenebilir; diğerleri Keşfet'ten eklenir.
                'core' => (bool) ($block['library'] ?? true),
                'inLibrary' => BlockLibrary::inLibrary($type),
            ];
        }

        return $out;
    }

    /**
     * Kayıt sayfası şablonunun (`dynamic`) önizlemesi için örnek kayıt: dil → adres (yayındaki son kayıt).
     *
     * @return array<string,string>
     */
    private function samples(array $page, Router $router): array
    {
        $kind = (string) ($page['dynamic'] ?? '');
        if ($kind === '') {
            return [];
        }
        $out = [];
        foreach (Locales::all() as $locale) {
            $slug = '';
            if ($kind === 'post') {
                $slug = Modules::enabled('blog') ? (string) ((new BlogPosts)->publicList($locale)[0]['slug'] ?? '') : '';
            } elseif ($type = Types::page($kind)) {
                foreach ($type->records(true) as $record) {
                    if (($slug = (string) ($type->slugs($record->data)[$locale] ?? '')) !== '') {
                        break;
                    }
                }
            }
            if ($slug !== '' && ($url = $router->url((string) $page['name'], $locale, $slug)) !== null) {
                $out[$locale] = $url;
            }
        }

        return $out;
    }

    /**
     * Blok sayfaları (Site\Pages\PageType): düzenleyicinin sayfa listesinde kayıt başına bir sayfa (`page@12`).
     * Şablonun `page.main` bölgesi kaydın bölgesiyle (`page.main@12`) değişir. Adres taslaktaki dilde de verilir
     * (önizleme açar).
     *
     * @return list<array{name:string,label:string,dynamic:null,record:array,paths:object,zones:list<string>,zone:string}>
     */
    private function blockPages(?Router $router): array
    {
        $route = null;
        foreach (BlockLibrary::pages() as $page) {
            if (($page['name'] ?? '') === PageType::ROUTE) {
                $route = $page;
            }
        }
        if ($route === null || $router === null) {
            return [];
        }
        $type = new PageType;
        $out = [];
        foreach ($type->records(false) as $record) {
            $zone = PageType::zone($record->id);
            $i18n = (array) ($record->data['i18n'] ?? []);
            $paths = [];
            foreach (Locales::all() as $locale) {
                $slug = (string) ($i18n[$locale]['slug'] ?? '');
                if ($slug !== '' && ($url = $router->url(PageType::ROUTE, $locale, $slug)) !== null) {
                    $paths[$locale] = $url;
                }
            }
            $title = (string) ($i18n[Locales::default()]['title'] ?? '') ?: (string) (array_values(array_filter(array_column($i18n, 'title')))[0] ?? '');
            $out[] = [
                'name' => 'page@'.$record->id,
                'label' => $title !== '' ? $title : '(Başlıksız)',
                'dynamic' => null,
                'record' => [
                    'id' => $record->id,
                    'status' => $record->status,
                    'locales' => (object) array_map(static fn (array $l) => $l['status'], $type->languages($record->data)),
                ],
                'paths' => (object) $paths,
                'zones' => array_values(array_map(static fn ($z) => $z === PageType::ZONE ? $zone : (string) $z, (array) ($route['zones'] ?? []))),
                'zone' => $zone,
            ];
        }

        return $out;
    }

    /** İşlem geçmişi için bölgenin adı: sayfanın üstü / altı sayfanın adıyla ("İletişim · üstte"). */
    private function zoneLabel(string $zone): string
    {
        [$route, $part] = explode('.', $zone, 2) + [1 => ''];
        if (in_array($part, ['top', 'bottom'], true) && $route !== 'site') {
            foreach (BlockLibrary::pages() as $page) {
                if (($page['name'] ?? '') === $route) {
                    return $page['label'].' · '.($part === 'top' ? 'üstte' : 'altta');
                }
            }
        }

        // Blok sayfası: sayfanın başlığı.
        if (preg_match('/@(\d+)$/', $zone, $m) && ($record = (new PageType)->find((int) $m[1]))) {
            return (string) ($record->data['i18n'][Locales::default()]['title'] ?? '') ?: 'Blok sayfası';
        }

        return (string) (BlockLibrary::zone($zone)['label'] ?? $zone);
    }
}
