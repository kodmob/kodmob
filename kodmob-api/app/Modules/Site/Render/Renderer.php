<?php

namespace App\Modules\Site\Render;

use App\Core\Content\Languages;
use App\Core\Content\MediaPayload;
use App\Core\Content\SlugHistory;
use App\Core\Content\Types;
use App\Core\Modules;
use App\Core\Support\Clock;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Blog\BlogPosts;
use App\Modules\Content\PageData;
use App\Modules\Content\PageTexts;
use App\Modules\Seo\Seo;
use App\Modules\Seo\Services\Llms;
use App\Modules\Seo\Services\PageMeta;
use App\Modules\Seo\Services\SeoContent;
use App\Modules\Seo\Services\SeoHead;
use App\Modules\Seo\Services\SeoMeta;
use App\Modules\Site\Blocks\BlockLibrary;
use App\Modules\Site\Blocks\Layouts;
use App\Modules\Site\Blocks\ZoneSsr;
use App\Modules\Site\Legal\LegalSsr;
use App\Modules\Site\Pages\PageType;
use Illuminate\Database\QueryException;

/**
 * Sayfa kabuğunu (dist'teki HTML) içerikle tamamlar: veri sayfaya JSON olarak
 * gömülür (`#km-data`; içeriği Alpine çizer), panelden değiştirilen sayfa
 * metinleri yerleştirilir (Content\PageTexts), detay sayfalarına başlık,
 * açıklama, canonical, hreflang, Open Graph ve JSON-LD eklenir. Ayrıca
 * sitemap.xml, blog RSS'i ve llms.txt üretir. Giriş noktası: public/render.php → SitePage.
 *
 * Diller: hreflang, x-default, og:locale:alternate ve site haritası yalnızca yayındaki
 * dilleri gösterir (Router::activeLocales; dil kaydı). Statik kabuklar derlemedeki bütün
 * dillerin bağlantısını taşır; pasif dilinki burada çıkarılır.
 *
 * SEO modülü açıksa (Modules\Seo) başlık/açıklama düzenlemeleri, noindex, canonical,
 * paylaşım görseli, doğrulama etiketleri ve kurum şeması da burada uygulanır (SeoHead).
 */
final class Renderer
{
    private const DATA_TAG = '<script type="application/json" id="km-data">null</script>';

    public function __construct(private readonly Router $router) {}

    /**
     * Kayıt bulunamazsa adres geçmişine bakılır (Core\Content\SlugHistory): adresi değişen kayıt
     * 301 ile yeni adresine, silinen kaydın adresi 410 (404 sayfasıyla) döner.
     *
     * @return array{0:int,1:string,2?:array<string,string>} durum kodu, HTML, başlıklar
     */
    public function page(array $match, bool $preview = false): array
    {
        $route = $match['route'];
        // Sayfa düzenleyicinin bölgeleri (Site\Blocks): blokların veri ihtiyacı sayfanınkine eklenir (Son yazılar → posts).
        $layouts = $this->layouts((string) $route['name'], $preview);
        $needs = array_values(array_unique([...(array) ($route['needs'] ?? []), ...ZoneSsr::needs($layouts, $preview)]));
        // Projede kapalı aracın sayfası (rotanın `module`'ü; Araç Pazarı): bulunamadı.
        $data = self::moduleOff($route) ? ['notFound' => true] : PageData::build($route['name'], $match['locale'], $match['slug'], $needs);
        $status = $match['status'];
        $scoped = [];
        if (($route['dynamic'] ?? '') === 'page') {
            [$data, $layouts, $scoped] = $this->blockPage($data, $layouts, $match, $needs, $preview);
        }

        if (! empty($data['notFound'])) {
            $resolved = ! empty($route['dynamic'])
                ? SlugHistory::resolve((string) $route['dynamic'], $match['locale'], $match['slug'], fn (string $locale, string $slug) => $this->router->url($route['name'], $locale, $slug))
                : null;
            if (isset($resolved['location'])) {
                return [301, '', ['Location' => $resolved['location']]];
            }
            $match = $this->router->notFound($match['locale']);
            if ($match['kind'] !== 'page') {
                return [404, 'Not found'];
            }
            $route = $match['route'];
            $layouts = $this->layouts((string) $route['name'], $preview);
            $data = PageData::build('notFound', $match['locale']);
            $status = isset($resolved['gone']) ? 410 : 404;
            if ($status === 410) {
                // 404 sayfası "içerik kaldırıldı" metniyle.
                $data['gone'] = true;
            }
            // Kayıt başka dillerde var: 404 sayfasında "şu dillerde mevcut" (dil seçici de bunlara gider).
            if (! empty($resolved['available'])) {
                $data['available'] = array_map(
                    static fn (string $code, string $href) => ['code' => $code, 'label' => (string) (Languages::find($code)['native'] ?? $code), 'url' => $href],
                    array_keys($resolved['available']),
                    $resolved['available']
                );
            }
        }

        $html = $this->router->shell($match['shell'], $match['locale']);
        // Bölgeler önce: blokların liste şablonları (Son yazılar) aşağıda ListSsr'dan geçer.
        $html = ZoneSsr::apply($html, $layouts, $match['locale'], $this->router, $preview, $scoped);
        // Yasal belgeler (Site\Legal): metin, içindekiler, yan menü; açık modüllere ve Proje Ayarları › Yasal'a göre.
        $html = LegalSsr::apply($html, $route, $match['locale'], $this->router);
        // Kayıt sayfası: başlık ve metin sunucuda HTML'e (JS çalıştırmayan botlar için).
        if (! empty($route['pattern']) && ! empty($data['entry'])) {
            $html = EntrySsr::apply($html, $data['entry']);
        }
        // Liste öğeleri (projeler, blog, ana sayfa kartları) de sunucuda; Alpine açılınca yerine geçer.
        $html = ListSsr::apply($html, $data, fn (string $name, string $slug) => $this->router->url($name, $match['locale'], $slug));
        $html = str_replace(self::DATA_TAG, '<script type="application/json" id="km-data">'.self::json($data).'</script>', $html);
        $site = rtrim($this->router->manifest()['siteUrl'], '/');
        if (! empty($route['pattern']) && ! empty($data['entry'])) {
            [$title, $head, $seo] = $this->entryHead($route, $match['locale'], $data['entry']);
            $html = preg_replace('#<title>.*?</title>#s', '<title>'.self::e($title).'</title>', $html, 1) ?? $html;
            $html = str_replace('<!--km:head-->', $head, $html);
            $html = SeoHead::entryPage($html, $match['locale'], $site, $seo['noindex'], str_contains($head, 'og:image'));
        } elseif (empty($route['pattern'])) {
            $html = SeoHead::staticPage($html, $route, $match['locale'], $site);
        }
        $html = $this->localizeAlternates($html);
        $html = PageTexts::apply($html, $match['locale'], (array) ($this->router->manifest()['texts'] ?? []));

        return [$status, $html];
    }

    /** @return array{0:string,1:string,2:array} sayfa başlığı, <head> etiketleri, çözülmüş SEO */
    private function entryHead(array $route, string $locale, array $entry): array
    {
        $m = $this->router->manifest();
        $site = rtrim($m['siteUrl'], '/');
        $s = $m['strings'][$locale];
        $kind = (string) $route['dynamic'];
        $isPost = $kind === 'post';

        // Varsayılan: yazı başlık + özet, kayıt türün kancasından (proje "Başlık · Kısa tanım" + özet);
        // SEO modülü düzenlemeleri.
        $seo = SeoContent::resolve($kind, $entry, $locale);
        $name = $seo['title'];
        $title = $name.$s['titleSuffix'];
        $description = $seo['description'];
        $canonical = $site.$this->router->url($route['name'], $locale, $entry['slug']);
        if ($seo['canonical'] !== '') {
            $canonical = str_starts_with($seo['canonical'], '/') ? $site.$seo['canonical'] : $seo['canonical'];
        }
        $image = $seo['image'] !== '' ? (str_starts_with($seo['image'], 'http') ? $seo['image'] : $site.$seo['image'])
            : (! empty($entry['cover']['src']) ? $site.$entry['cover']['src'] : '');

        $tags = [
            '<meta name="description" content="'.self::e($description).'">',
            '<link rel="canonical" href="'.self::e($canonical).'">',
        ];
        // Kaydın yayındaki dillerdeki karşılıkları; x-default varsayılan dildeki (yoksa ilki).
        $alternates = [];
        foreach ($entry['slugs'] as $code => $slug) {
            if ($this->router->isActive((string) $code) && ($href = $this->router->url($route['name'], (string) $code, (string) $slug)) !== null) {
                $alternates[(string) $code] = $site.$href;
            }
        }
        foreach ($alternates as $code => $href) {
            $tags[] = '<link rel="alternate" hreflang="'.self::e($this->router->hreflang((string) $code)).'" href="'.self::e($href).'">';
        }
        if (count($alternates) > 1) {
            $tags[] = '<link rel="alternate" hreflang="x-default" href="'.self::e($alternates[$this->router->defaultLocale()] ?? reset($alternates)).'">';
        }
        $tags[] = '<meta property="og:type" content="'.($isPost ? 'article' : 'website').'">';
        $tags[] = '<meta property="og:site_name" content="'.self::e($s['siteName']).'">';
        $tags[] = '<meta property="og:title" content="'.self::e($name).'">';
        $tags[] = '<meta property="og:description" content="'.self::e($description).'">';
        $tags[] = '<meta property="og:url" content="'.self::e($canonical).'">';
        $tags[] = '<meta property="og:locale" content="'.self::e($this->router->og($locale)).'">';
        foreach (array_keys($alternates) as $code) {
            if ($code !== $locale && $this->router->og((string) $code) !== '') {
                $tags[] = '<meta property="og:locale:alternate" content="'.self::e($this->router->og((string) $code)).'">';
            }
        }
        if ($image !== '') {
            $tags[] = '<meta property="og:image" content="'.self::e($image).'">';
            $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        }
        if ($isPost) {
            $tags[] = '<meta property="article:published_time" content="'.self::e($entry['date']).'">';
        }

        // Kırıntı yolunun ortası: kaydın listesi (rotanın `fallback`'i; adı derlemenin metinlerinden).
        $list = $isPost ? 'blog' : (string) ($route['fallback'] ?? 'home');
        $listUrl = $site.$this->router->url($list, $locale);
        $page = ['url' => $canonical, 'description' => $description, 'image' => $image, 'locale' => $locale, 'organization' => $site.'/#organization'];
        $graph = [
            $isPost ? [
                '@type' => 'BlogPosting',
                'headline' => $entry['title'],
                'description' => $description,
                'url' => $canonical,
                'inLanguage' => $locale,
                'datePublished' => $entry['date'],
                'dateModified' => $entry['modified'],
                'image' => $image ?: null,
                'author' => ['@type' => 'Person', 'name' => $entry['author']],
                'publisher' => ['@id' => $site.'/#organization'],
                'mainEntityOfPage' => $canonical,
            ] : (Types::page($kind)?->structuredData($entry, $page) ?? ['@type' => 'CreativeWork', 'name' => $entry['title'] ?? '', 'url' => $canonical]),
            [
                '@type' => 'BreadcrumbList',
                // Listesi ana sayfa olan kayıt (blok sayfası) iki basamak.
                'itemListElement' => $list === 'home' ? [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => $s['home'], 'item' => $site.$this->router->url('home', $locale)],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $entry['title'], 'item' => $canonical],
                ] : [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => $s['home'], 'item' => $site.$this->router->url('home', $locale)],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $s['lists'][$list] ?? $s[$list] ?? '', 'item' => $listUrl],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $entry['title'], 'item' => $canonical],
                ],
            ],
        ];
        $graph[0] = array_filter($graph[0], static fn ($v) => $v !== null && $v !== '');
        $tags[] = '<script type="application/ld+json">'.self::json(['@context' => 'https://schema.org', '@graph' => $graph]).'</script>';

        return [$title, implode("\n  ", $tags), $seo];
    }

    /**
     * Blok sayfası (Site\Pages\PageType): içeriği sayfaya özel bölgede (`page.main@<kimlik>`). Önizlemede taslak sayfa
     * da açılır. Blokların veri ihtiyacı (Son yazılar → posts) sayfanınkinde yoksa veri yeniden kurulur.
     *
     * @return array{0:array,1:array<string,?list<array>>,2:array<string,string>} veri, bölgeler, bölge → kayıt bölgesi
     */
    private function blockPage(array $data, array $layouts, array $match, array $needs, bool $preview): array
    {
        if (! empty($data['notFound']) && $preview && ($entry = (new PageType)->previewBySlug($match['slug'], $match['locale']))) {
            $data['entry'] = $entry;
            unset($data['notFound']);
        }
        $id = (int) ($data['entry']['id'] ?? 0);
        if ($id <= 0 || ! array_key_exists(PageType::ZONE, $layouts)) {
            return [$data, $layouts, []];
        }
        $key = PageType::zone($id);
        try {
            $layouts[PageType::ZONE] = Layouts::forRender([$key], $preview)[$key];
        } catch (QueryException) {
            $layouts[PageType::ZONE] = null;
        }
        $more = array_diff(ZoneSsr::needs([PageType::ZONE => $layouts[PageType::ZONE] ?? []], $preview), $needs);
        if ($more) {
            $entry = $data['entry'];
            $data = PageData::build((string) $match['route']['name'], $match['locale'], $match['slug'], [...$needs, ...$more]);
            $data['entry'] = $entry;
            unset($data['notFound']);
        }

        return [$data, $layouts, [PageType::ZONE => $key]];
    }

    /**
     * Rotanın bölgelerindeki bloklar (Site\Blocks\Layouts). Tablo henüz yoksa (sürüm yüklendi, kurulum adresi
     * çalışmadı) bölgeler kabuktaki varsayılanla kalır; sayfa yine üretilir.
     *
     * @return array<string,?list<array>>
     */
    private function layouts(string $route, bool $preview): array
    {
        $zones = BlockLibrary::zonesFor($route);
        try {
            return Layouts::forRender($zones, $preview);
        } catch (QueryException) {
            return array_fill_keys($zones, null);
        }
    }

    /** Rota projede kapalı bir modüle mi ait (`module`: estimate…; Core\Modules, Araç Pazarı). */
    public static function moduleOff(array $route): bool
    {
        return ! empty($route['module']) && ! Modules::enabled((string) $route['module']);
    }

    public function sitemap(): string
    {
        $m = $this->router->manifest();
        $site = rtrim($m['siteUrl'], '/');
        $rows = [];
        // SEO modülü (site haritası) açıksa: son değişiklik ve görseller; noindex sayfalar haritaya girmez.
        $extras = Seo::on('sitemap');
        $images = $extras && (bool) Seo::setting('seo.sitemap_images');
        $noindex = Seo::on('meta') ? SeoMeta::noindexSet() : [];
        $pageMeta = Seo::on('meta') ? PageMeta::all() : [];
        $router = $this->router;
        $default = $router->defaultLocale();
        // Yalnızca yayındaki diller; birden çok dil varsa x-default varsayılan dildeki karşılık.
        $add = static function (array $alternates, ?string $lastmod = null, array $pictures = []) use (&$rows, $site, $extras, $images, $router, $default) {
            $alternates = array_filter($alternates, static fn ($code) => $router->isActive((string) $code), ARRAY_FILTER_USE_KEY);
            $xDefault = count($alternates) > 1 ? ($alternates[$default] ?? reset($alternates)) : null;
            foreach ($alternates as $href) {
                $links = '';
                foreach ($alternates as $code => $alt) {
                    $links .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"".self::x($router->hreflang((string) $code)).'" href="'.self::x($site.$alt).'"/>';
                }
                if ($xDefault !== null) {
                    $links .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"".self::x($site.$xDefault).'"/>';
                }
                if ($extras && $lastmod) {
                    $links .= "\n    <lastmod>".self::x($lastmod).'</lastmod>';
                }
                if ($images) {
                    foreach (array_slice($pictures, 0, 20) as $picture) {
                        $links .= "\n    <image:image><image:loc>".self::x(str_starts_with($picture, 'http') ? $picture : $site.$picture).'</image:loc></image:image>';
                    }
                }
                $rows[] = '  <url>'."\n    <loc>".self::x($site.$href).'</loc>'.$links."\n  </url>";
            }
        };

        $static = [];
        foreach ($m['routes'] as $route) {
            if (! empty($route['path']) && ! empty($route['sitemap']) && empty($pageMeta[$route['name']][$route['locale']]['noindex']) && ! self::moduleOff($route)) {
                $static[$route['name']][$route['locale']] = $route['path'];
            }
        }
        foreach ($static as $alternates) {
            $add($alternates);
        }
        // Kendi sayfası olan türlerin kayıtları (ör. projeler); görseller türün görsel alanlarından.
        $pageRoutes = SiteManifest::pageRoutes();
        foreach (Types::paged() as $kind => $type) {
            foreach ($type->records(true) as $record) {
                if (isset($noindex[$kind.':'.$record->id])) {
                    continue;
                }
                $alternates = [];
                foreach ($type->slugs($record->data) as $code => $slug) {
                    if ($url = $this->router->url($pageRoutes[$kind], $code, $slug)) {
                        $alternates[$code] = $url;
                    }
                }
                $pictures = [];
                if ($images) {
                    foreach ($type->imagesOf($record->data) as $id) {
                        if ($id > 0 && ($src = MediaPayload::image($id, 'large')['src'] ?? '')) {
                            $pictures[] = $src;
                        }
                    }
                }
                $add($alternates, Clock::atom($record->updatedAt), $pictures);
            }
        }
        foreach ((new BlogPosts)->sitemapGroups() as $group) {
            $alternates = [];
            $lastmod = null;
            $pictures = [];
            foreach ($group as $code => $post) {
                if (isset($noindex['post:'.$post['id']])) {
                    continue;
                }
                if ($url = $this->router->url('post', $code, $post['slug'])) {
                    $alternates[$code] = $url;
                    $lastmod = max((string) $lastmod, (string) Clock::atom($post['updated_at']));
                    if ($images && $post['cover_id'] && ($src = MediaPayload::image((int) $post['cover_id'], 'large')['src'] ?? '')) {
                        $pictures[$src] = $src;
                    }
                }
            }
            if ($alternates) {
                $add($alternates, $lastmod, array_values($pictures));
            }
        }

        $ns = $images ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '';

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\"{$ns}>\n".implode("\n", $rows)."\n</urlset>\n";
    }

    /** /llms.txt ve /llms-full.txt (SEO modülü llms açıkken). */
    public function llms(bool $full): string
    {
        return Llms::build($full);
    }

    public function rss(string $locale): string
    {
        $m = $this->router->manifest();
        $site = rtrim($m['siteUrl'], '/');
        $s = $m['strings'][$locale];
        $blog = $site.$this->router->url('blog', $locale);
        $items = [];
        foreach ((new BlogPosts)->publicList($locale) as $post) {
            $url = self::x($site.$this->router->url('post', $locale, $post['slug']));
            $items[] = "    <item>\n      <title>".self::x($post['title'])."</title>\n      <link>{$url}</link>\n      <guid isPermaLink=\"true\">{$url}</guid>\n      <pubDate>".gmdate(DATE_RSS, (int) strtotime($post['date']))."</pubDate>\n      <description>".self::x($post['excerpt'])."</description>\n    </item>";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n  <channel>\n"
            .'    <title>'.self::x($s['blogTitle'])."</title>\n"
            .'    <link>'.self::x($blog)."</link>\n"
            .'    <atom:link href="'.self::x($blog.'feed.xml')."\" rel=\"self\" type=\"application/rss+xml\"/>\n"
            .'    <description>'.self::x($s['blogDescription'])."</description>\n"
            ."    <language>{$locale}</language>\n"
            .implode("\n", $items)."\n  </channel>\n</rss>\n";
    }

    /**
     * Statik kabuktaki dil etiketleri dil kaydına uydurulur (derleme bütün dilleri, dil koduyla yazar):
     * pasif dilin hreflang ve og:locale:alternate etiketi çıkar; tek dil kalırsa x-default da çıkar;
     * panelde hreflang ya da Open Graph kodu değiştirilmişse (ör. `en` → `en-GB`) o yazılır.
     */
    private function localizeAlternates(string $html): string
    {
        foreach ((array) ($this->router->manifest()['locales'] ?? []) as $code => $info) {
            $code = (string) $code;
            $q = preg_quote($code, '#');
            $builtOg = (string) ($info['og'] ?? '');
            if (! $this->router->isActive($code)) {
                $html = preg_replace('#\s*<link rel="alternate" hreflang="'.$q.'" href="[^"]*">#', '', $html) ?? $html;
                if ($builtOg !== '') {
                    $html = preg_replace('#\s*<meta property="og:locale:alternate" content="'.preg_quote($builtOg, '#').'">#', '', $html) ?? $html;
                }

                continue;
            }
            if (($hreflang = $this->router->hreflang($code)) !== $code) {
                $html = preg_replace('#(<link rel="alternate" hreflang=")'.$q.'(" href=)#', '${1}'.self::e($hreflang).'${2}', $html) ?? $html;
            }
            if ($builtOg !== '' && ($og = $this->router->og($code)) !== $builtOg) {
                $html = preg_replace('#(<meta property="og:locale(?::alternate)?" content=")'.preg_quote($builtOg, '#').'(">)#', '${1}'.self::e($og).'${2}', $html) ?? $html;
            }
        }
        if (preg_match_all('#<link rel="alternate" hreflang="(?!x-default)[^"]*"#', $html) <= 1) {
            $html = preg_replace('#\s*<link rel="alternate" hreflang="x-default" href="[^"]*">#', '', $html) ?? $html;
        }

        return $html;
    }

    /** `<script>` içine güvenli JSON: `<` kaçırılır (</script>, <!-- etkisiz). */
    private static function json(mixed $value): string
    {
        return str_replace('<', '<', Text::json($value));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
