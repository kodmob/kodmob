<?php

namespace App\Modules\Site\Render;

use App\Core\Sites\SiteContext;

/**
 * Adres → sayfa eşlemesi. Rota listesi ön yüz derlemesinin yazdığı
 * `km-manifest.json`'dadır (site kökü). Laravel gerektirmez: public/render.php
 * önbellek denetimi için bu dosyayı Laravel'i açmadan yükler.
 *
 * Dil yapısı derlemeden gelir (karar A): manifest `default` (öneksiz dil) ve
 * `locales[dil].prefix`. Hangi dilin yayında olduğu dil kaydındadır
 * (Modules\I18n\I18nConfig → storage/framework/i18n/config.json); dosya yoksa derlemedeki
 * bütün diller yayında sayılır. Hiçbir yerde dil kodu varsayılmaz (`tr|en|de`, iki harf…).
 *
 * Eşleşme sırası: site haritası, llms.txt, RSS → sabit adres → kayıt kalıbı → eksik `/`
 * → kalkan önek (`legacy.prefixes`, 301) → eski adres (`legacy.paths`, 301) → 404. Yayında olmayan dilin sayfası 302 ile varsayılan dile gider.
 *
 * Sonuç:
 *   ['kind' => 'page', 'route' => [...], 'locale' => 'tr', 'slug' => '…', 'shell' => '/…/index.html', 'status' => 200]
 *   ['kind' => 'redirect', 'location' => '/projeler/x/', 'code' => 301]
 *   ['kind' => 'sitemap'] | ['kind' => 'rss', 'locale' => 'tr'] | ['kind' => 'llms', 'full' => bool] | ['kind' => 'none']
 */
final class Router
{
    /** @var list<string> */
    private array $active;

    /** @var array<string,array{hreflang:string,og:string}> dil kaydında (panel > Çoklu Dil) yazılan değerler */
    private array $languageMeta = [];

    private function __construct(private readonly array $manifest, private readonly string $root, ?array $i18n)
    {
        $built = array_keys((array) ($manifest['locales'] ?? []));
        $active = null;
        if (is_array($i18n['languages'] ?? null)) {
            $active = array_values(array_intersect($built, array_column(array_filter($i18n['languages'], static fn ($l) => ! empty($l['active'])), 'code')));
        }
        // Varsayılan dil her zaman yayında; kayıt yoksa derlemedeki bütün diller.
        $this->active = $active === null ? $built : array_values(array_unique([$this->defaultLocale(), ...$active]));
        foreach ((array) ($i18n['languages'] ?? []) as $language) {
            if (is_array($language) && isset($language['code'])) {
                $this->languageMeta[(string) $language['code']] = ['hreflang' => trim((string) ($language['hreflang'] ?? '')), 'og' => trim((string) ($language['og'] ?? ''))];
            }
        }
    }

    /**
     * @param  string|null  $i18nFile  dil kaydının derlenmiş kopyası; null ise Laravel içindeyken storage'dan okunur
     */
    public static function load(string $root, ?string $i18nFile = null): ?self
    {
        $file = $root.'/km-manifest.json';
        $manifest = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($manifest) || empty($manifest['routes'])) {
            return null;
        }
        // Çok site: alt sitenin adresleri kendi alan adıyla (ortak derlemenin manifest'i ana sitenin adresini taşır).
        if (class_exists(SiteContext::class) && ($url = SiteContext::url()) !== '') {
            $manifest['builtSiteUrl'] = (string) ($manifest['siteUrl'] ?? '');
            $manifest['siteUrl'] = $url;
        }
        $manifest = self::profiled($manifest);
        $i18nFile ??= function_exists('storage_path') && function_exists('app') && app()->bound('path.storage') ? storage_path('framework/i18n/config.json') : null;
        $i18n = $i18nFile !== null && is_file($i18nFile) ? json_decode((string) file_get_contents($i18nFile), true) : null;

        return new self($manifest, $root, is_array($i18n) ? $i18n : null);
    }

    /** @var (\Closure(array):array)|null site kimliği (Site\SiteProfile; Laravel içinde AppServiceProvider bağlar) */
    private static ?\Closure $profile = null;

    public static function useProfile(?\Closure $profile): void
    {
        self::$profile = $profile;
    }

    /** Manifest'e site kimliği ayarları (site adı, logo); Laravel'siz render.php'de bağlı değildir. */
    public static function profiled(array $manifest): array
    {
        return self::$profile ? (self::$profile)($manifest) : $manifest;
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function manifestFile(): string
    {
        return $this->root.'/km-manifest.json';
    }

    /**
     * Sayfa kabuğu. Derlemeye gömülü değerler sitenin bugünkü haliyle (içerik eklenmeden önce, yalnız kabukta):
     * ortak derlemeyi kullanan alt sitede adresler (kanonik, hreflang, og:url), panelde değişmişse site adı
     * (başlık eki, og:site_name, alt bilgi, yapısal veri) ve logo yazıları.
     */
    public function shell(string $file, ?string $locale = null): string
    {
        $html = (string) file_get_contents($file);
        $pairs = [];
        $built = rtrim((string) ($this->manifest['builtSiteUrl'] ?? ''), '/');
        $site = rtrim((string) ($this->manifest['siteUrl'] ?? ''), '/');
        if ($built !== '' && $built !== $site) {
            $pairs += [$built.'/' => $site.'/', $built.'"' => $site.'"', str_replace('/', '\/', $built) => str_replace('/', '\/', $site)];
        }
        $from = $locale !== null ? (array) ($this->manifest['builtStrings'][$locale] ?? []) : [];
        $to = $locale !== null ? (array) ($this->manifest['strings'][$locale] ?? []) : [];
        $esc = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $logo = static fn (array $s) => '<span class="site-logo-text">'.$esc((string) ($s['brand'] ?? '')).'</span><span class="site-logo-sub">'.$esc((string) ($s['brandSub'] ?? '')).'</span>';
        if ($from && ($to['logoImage'] ?? '') !== '') {
            // Logo görseli yazının yerine (üst bar ve alt bilgi).
            $pairs[$logo($from)] = '<img class="site-logo-img" src="'.$esc((string) $to['logoImage']).'" alt="'.$esc((string) ($to['siteName'] ?? $from['siteName'] ?? '')).'">';
        } else {
            foreach (['brand' => 'site-logo-text', 'brandSub' => 'site-logo-sub'] as $key => $class) {
                if (($from[$key] ?? '') !== '' && ($to[$key] ?? '') !== $from[$key]) {
                    $pairs['<span class="'.$class.'">'.$esc($from[$key]).'</span>'] = '<span class="'.$class.'">'.$esc((string) $to[$key]).'</span>';
                }
            }
        }
        // Panelde verilen renkler (Site\SiteProfile::css): sürümlü adres, ayar değişince değişir.
        if (! empty($this->manifest['themeVersion'])) {
            $pairs['</head>'] = '<link rel="stylesheet" href="/api/v1/theme.css?v='.$esc((string) $this->manifest['themeVersion']).'"></head>';
        }
        $jsonPairs = $pairs;
        $name = (string) ($from['siteName'] ?? '');
        $new = (string) ($to['siteName'] ?? '');
        if ($name !== '' && $new !== '' && $name !== $new) {
            // HTML'de (öznitelik ve metin) kaçışlı; yapısal veride JSON (\u kaçışlı ya da kaçışsız).
            $json = static fn (string $s, int $flags = 0) => substr((string) json_encode($s, $flags | JSON_UNESCAPED_SLASHES), 1, -1);
            $pairs[$esc($name)] = $esc($new);
            $jsonPairs[$json($name)] = $json($new);
            $jsonPairs[$json($name, JSON_UNESCAPED_UNICODE)] = $json($new, JSON_UNESCAPED_UNICODE);
        }
        if (! $pairs && ! $jsonPairs) {
            return $html;
        }
        // Yapısal veri blokları ayrı (aynı ad iki biçimde farklı yazılır); tek geçiş, değişen yeniden değişmez.
        $parts = preg_split('#(<script type="application/ld\+json">.*?</script>)#s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        foreach ($parts as $i => $part) {
            $parts[$i] = strtr($part, $i % 2 ? $jsonPairs : $pairs);
        }

        return implode('', $parts);
    }

    /** Derlemenin öneksiz (varsayılan) dili. */
    public function defaultLocale(): string
    {
        $default = (string) ($this->manifest['default'] ?? '');
        if ($default !== '' && isset($this->manifest['locales'][$default])) {
            return $default;
        }
        foreach ((array) ($this->manifest['locales'] ?? []) as $code => $info) {
            if (($info['prefix'] ?? null) === '') {
                return (string) $code;
            }
        }

        return (string) array_key_first((array) ($this->manifest['locales'] ?? [])) ?: 'tr';
    }

    /** @return list<string> sitede yayında olan diller (derlemede olan ve kayıtta açık) */
    public function activeLocales(): array
    {
        return $this->active;
    }

    public function isActive(string $locale): bool
    {
        return in_array($locale, $this->active, true);
    }

    /** hreflang değeri: dil kaydındaki (ör. `en-GB`), yoksa dil kodu. */
    public function hreflang(string $locale): string
    {
        return ($this->languageMeta[$locale]['hreflang'] ?? '') ?: $locale;
    }

    /** Open Graph dil kodu: dil kaydındaki, yoksa derlemedeki (`tr_TR`). */
    public function og(string $locale): string
    {
        return ($this->languageMeta[$locale]['og'] ?? '') ?: (string) ($this->manifest['locales'][$locale]['og'] ?? '');
    }

    public function prefixOf(string $locale): string
    {
        return (string) ($this->manifest['locales'][$locale]['prefix'] ?? $locale);
    }

    /** Dilin ana sayfası: `/`, `/en/`. */
    public function homeOf(string $locale): string
    {
        return $this->url('home', $locale) ?? ($this->prefixOf($locale) === '' ? '/' : '/'.$this->prefixOf($locale).'/');
    }

    public function match(string $path, bool $legacy = true): array
    {
        if ($path === '/sitemap.xml') {
            return ['kind' => 'sitemap'];
        }
        if ($path === '/llms.txt' || $path === '/llms-full.txt') {
            return ['kind' => 'llms', 'full' => $path === '/llms-full.txt'];
        }
        $path = preg_replace('#/index\.html$#', '/', $path) ?? $path;

        $found = $this->find($path);
        if ($found === null && ! str_ends_with($path, '/') && ! str_contains(basename($path), '.') && $this->find($path.'/') !== null) {
            return $this->redirect($path.'/', 301);
        }
        // 404'ten önce (done-coklu-dil-raporu.md §4.7): eski adres, yazım farkı, yanlış dildeki bölüm adı.
        if ($found === null && $legacy) {
            $found = $this->legacy($path) ?? $this->normalized($path) ?? $this->otherSection($path);
        }
        // Blok sayfaları en sonda (kökteki serbest adresler).
        if ($found === null && $path !== '/') {
            $found = $this->findLast($path);
        }
        // Kök adres: varsayılan dil öneksizse kendi sayfası; önekliyse varsayılan dile 302 (dil yönlendirmesi yalnızca burada).
        if ($found === null && $path === '/' && $this->homeOf($this->defaultLocale()) !== '/') {
            return $this->redirect($this->homeOf($this->defaultLocale()), 302);
        }
        if ($found === null) {
            return $this->notFound($this->localeOf($path));
        }
        // Yayında olmayan dil: varsayılan dilde karşılığı (kayıt sayfasında rotanın yedeği), geçici.
        $locale = $found['locale'] ?? null;
        if ($locale !== null && ! $this->isActive($locale)) {
            $default = $this->defaultLocale();
            $name = ($found['kind'] === 'page' && ! empty($found['route']['pattern'])) ? (string) ($found['route']['fallback'] ?? 'home') : (string) ($found['route']['name'] ?? 'home');

            return $this->redirect($this->url($name, $default) ?? $this->homeOf($default), 302);
        }

        // İç anahtarlar (dil, rota) yönlendirme sonucundan çıkarılır.
        return $found['kind'] === 'redirect' ? $this->redirect($found['location'], (int) $found['code']) : $found;
    }

    public function notFound(string $locale): array
    {
        $locale = $this->isActive($locale) ? $locale : $this->defaultLocale();
        foreach ($this->manifest['routes'] as $route) {
            if ($route['name'] === 'notFound' && $route['locale'] === $locale) {
                return $this->page($route, '', 404);
            }
        }

        return ['kind' => 'none'];
    }

    /** Rotanın verilen dildeki adresi (`:slug` doldurulur). */
    /** Rotanın tanımı (dilin satırı); yoksa null. */
    public function route(string $name, string $locale): ?array
    {
        foreach ($this->manifest['routes'] as $route) {
            if ($route['name'] === $name && $route['locale'] === $locale) {
                return $route;
            }
        }

        return null;
    }

    public function url(string $name, string $locale, string $slug = ''): ?string
    {
        foreach ($this->manifest['routes'] as $route) {
            if ($route['name'] === $name && $route['locale'] === $locale) {
                return isset($route['pattern']) ? str_replace(':slug', rawurlencode($slug), $route['pattern']) : $route['path'];
            }
        }

        return null;
    }

    /** Adresin dili: önekinden; önek yoksa varsayılan dil. */
    public function localeOf(string $path): string
    {
        $first = explode('/', ltrim($path, '/'))[0] ?? '';
        foreach ((array) ($this->manifest['locales'] ?? []) as $code => $info) {
            if (($info['prefix'] ?? '') !== '' && $info['prefix'] === $first) {
                return (string) $code;
            }
        }

        return $this->defaultLocale();
    }

    // ------------------------------------------------------------ iç

    /** Sabit adres, RSS ya da kayıt kalıbı; yoksa null. */
    private function find(string $path): ?array
    {
        foreach ($this->manifest['routes'] as $route) {
            if (isset($route['path']) && $route['path'] === $path) {
                return $this->page($route, '', (int) ($route['status'] ?? 200));
            }
        }
        // Blog RSS: her dilde blog adresi + feed.xml (/blog/feed.xml, /en/blog/feed.xml).
        if (str_ends_with($path, '/feed.xml')) {
            foreach ($this->manifest['routes'] as $route) {
                if ($route['name'] === 'blog' && isset($route['path']) && $path === $route['path'].'feed.xml') {
                    return ['kind' => 'rss', 'locale' => $route['locale']];
                }
            }
        }
        foreach ($this->manifest['routes'] as $route) {
            if (! isset($route['pattern']) || ! empty($route['last'])) {
                continue;
            }
            if (preg_match(self::regex($route['pattern']), $path, $m)) {
                return $m[2] === '' ? $this->redirect($path.'/', 301) + ['locale' => $route['locale'], 'route' => $route] : $this->page($route, $m[1], 200);
            }
        }

        return null;
    }

    /**
     * En son denenen kalıplar (`last`; blok sayfaları `/:slug/`): sitenin adresleri, eski adres yönlendirmeleri ve
     * yanlış dildeki bölüm adı önce eşlenir; kökteki her tek parçalı adres onlardan sonra bir sayfa adresi sayılır.
     */
    private function findLast(string $path): ?array
    {
        // Dosya adresi (favicon.ico, eski .php) sayfa sayılmaz: adreste nokta olmaz.
        if (str_contains(basename($path), '.')) {
            return null;
        }
        foreach ($this->manifest['routes'] as $route) {
            if (! empty($route['last']) && isset($route['pattern']) && preg_match(self::regex($route['pattern']), $path, $m)) {
                return $m[2] === '' ? $this->redirect($path.'/', 301) + ['locale' => $route['locale'], 'route' => $route] : $this->page($route, $m[1], 200);
            }
        }

        return null;
    }

    /**
     * Eski adres. Önce kalkan önek (/tr/projeler/x/ → /projeler/x/; rota listesinde olmayan
     * /tr/blog/feed.xml dahil): yalnızca öneksiz hali gerçekten bir sayfaya çıkıyorsa. Sonra
     * kayıtlı eski yollar (bölüm adı değişti): kalıpta kayıt adı noktasızdır (slug'larda nokta
     * olmaz), böylece /eski/blog/feed.xml bir yazı adı sanılmaz.
     */
    private function legacy(string $path): ?array
    {
        $legacy = (array) ($this->manifest['legacy'] ?? []);

        $segments = explode('/', ltrim($path, '/'), 2);
        foreach ((array) ($legacy['prefixes'] ?? []) as $locale => $prefixes) {
            if (! isset($this->manifest['locales'][$locale]) || ! in_array($segments[0], (array) $prefixes, true)) {
                continue;
            }
            $prefix = $this->prefixOf((string) $locale);
            $target = ($prefix === '' ? '' : '/'.$prefix).'/'.($segments[1] ?? '');
            $resolved = $this->match($target, false);
            // Blok sayfası kalıbı (`last`) her tek parçalı adresi tutar; kalkan önekin eski adresi o olamaz.
            if (in_array($resolved['kind'], ['page', 'rss', 'redirect'], true) && ($resolved['status'] ?? 200) !== 404 && empty($resolved['route']['last'])) {
                return $this->redirect($resolved['kind'] === 'redirect' ? $resolved['location'] : $target, 301) + ['locale' => (string) $locale, 'route' => ['name' => 'home']];
            }
        }

        foreach ((array) ($legacy['paths'] ?? []) as $old) {
            $from = (string) ($old['from'] ?? '');
            $slug = '';
            if (str_contains($from, ':slug')) {
                if (! preg_match(self::regex($from, '[^/.]+'), $path, $m)) {
                    continue;
                }
                $slug = rawurldecode($m[1]);
            } elseif ($from !== $path && rtrim($from, '/') !== rtrim($path, '/')) {
                continue;
            }
            $target = $this->url((string) $old['name'], (string) $old['locale'], $slug);
            if ($target !== null && $target !== $path) {
                return $this->redirect($target, 301) + ['locale' => (string) $old['locale'], 'route' => ['name' => (string) $old['name'], 'fallback' => 'home']];
            }
        }

        return null;
    }

    /**
     * Yazım farkı: büyük harf ya da çift `/`. Küçük harfli, tek `/`lu adres bir sayfaya çıkıyorsa
     * oraya 301 (o adres de yönleniyorsa doğrudan son hedefe, aynı kodla).
     */
    private function normalized(string $path): ?array
    {
        $clean = preg_replace('#/{2,}#', '/', mb_strtolower($path)) ?? $path;
        if ($clean === $path) {
            return null;
        }
        $resolved = $this->match($clean);
        if (! in_array($resolved['kind'], ['page', 'rss', 'redirect'], true) || ($resolved['status'] ?? 200) === 404) {
            return null;
        }

        return $resolved['kind'] === 'redirect'
            ? $this->redirect($resolved['location'], (int) $resolved['code'] === 302 ? 302 : 301)
            : $this->redirect($clean, 301);
    }

    /**
     * Yanlış dildeki bölüm adı: /en/projeler/x/ → /en/projects/x/, /projects/x/ → /projeler/x/.
     * Adresin dili önekten; önekten sonrası başka bir dilde bir sayfaysa aynı sayfanın adresin
     * dilindeki karşılığına 301. Kaydın o dilde olup olmadığına Renderer bakar.
     */
    private function otherSection(string $path): ?array
    {
        $locale = $this->localeOf($path);
        $prefix = $this->prefixOf($locale);
        if ($prefix !== '' && ! str_starts_with($path, '/'.$prefix.'/')) {
            return null;
        }
        $rest = $prefix === '' ? $path : substr($path, strlen($prefix) + 1);
        if (! str_ends_with($rest, '/') && ! str_contains(basename($rest), '.')) {
            $rest .= '/';
        }
        foreach (array_keys((array) ($this->manifest['locales'] ?? [])) as $other) {
            $other = (string) $other;
            if ($other === $locale) {
                continue;
            }
            $otherPrefix = $this->prefixOf($other);
            $hit = $this->find(($otherPrefix === '' ? '' : '/'.$otherPrefix).$rest);
            if ($hit === null || $hit['kind'] !== 'page' || $hit['status'] === 404 || $hit['locale'] !== $other) {
                continue;
            }
            $target = $this->url((string) $hit['route']['name'], $locale, $hit['slug']);
            if ($target !== null && $target !== $path) {
                return $this->redirect($target, 301) + ['locale' => $locale, 'route' => $hit['route']];
            }
        }

        return null;
    }

    private static function regex(string $pattern, string $slug = '[^/]+'): string
    {
        return '#^'.str_replace('\:slug', '('.$slug.')', preg_quote(rtrim($pattern, '/'), '#')).'(/?)$#';
    }

    private function redirect(string $location, int $code): array
    {
        return ['kind' => 'redirect', 'location' => $location, 'code' => $code];
    }

    private function page(array $route, string $slug, int $status): array
    {
        return [
            'kind' => 'page',
            'route' => $route,
            'locale' => $route['locale'],
            'slug' => rawurldecode($slug),
            'shell' => $this->root.'/'.ltrim($route['shell'], '/'),
            'status' => $status,
        ];
    }
}
