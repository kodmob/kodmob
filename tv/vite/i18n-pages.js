/**
 * Çok dilli sayfa kabukları (Nunjucks + Vite).
 *
 * Profilin routes.config.js'indeki (ve çatının) her rota, her dil için ayrı bir HTML olarak üretilir. Adres =
 * dil öneki + yol (varsayılan dil öneksiz): `/projeler/` → dist/_pages/tr/projeler/index.html,
 * `/en/projects/` → dist/_pages/en/projects/index.html (vite/site-urls.js). Sayfalarda içerik yoktur:
 * metinler, menüler ve sabit bölümler HTML'dedir; projeler, yazılar,
 * hizmetler, referanslar ve site bilgileri ziyaret anında gelir.
 *
 * Canlıda (Hostinger) her sayfa isteği api/render.php (kodmob-api/public/render.php)
 * üzerinden geçer: kabuğa `#km-data` (sayfanın verisi) gömülür, detay
 * sayfalarına SEO etiketleri eklenir. Bunun için derleme `km-manifest.json`
 * yazar (rotalar, kabuk dosyaları, başlık metinleri). Veri gömülmemişse
 * (geliştirme sunucusu) tarayıcı `GET /page-data` ile ister.
 *
 * Dinamik rotalar (`dynamic: 'project' | 'post'`) her dilde tek kabuk üretir:
 * `/projeler/:slug/` → dist/_pages/tr/projeler/__entry/index.html.
 *
 * Şablonda kullanılabilenler:
 *   t('nav.projects'), t('x', { name })   → çeviri (dizi/nesne de dönebilir)
 *   tx('home.hero.title')                  → panelden değiştirilebilen metin (yalnızca
 *                                            etiket içeriğinde; öznitelikte/JSON'da t())
 *   zone('home.main', 'Etiket')            → sayfa düzenleyicinin bölgesi: varsayılan blokları (profilin
 *                                            site.config.js `zones`) yazılır; sunucu yayındaki düzeni koyar
 *                                            (vite/blocks.js, km-blocks.json)
 *   url('projects')                        → geçerli dildeki adres (yoksa null)
 *   locale, localeInfo, locales, defaultLocale, page ({ name, path, dynamic, needs }), alternates,
 *   switcher, siteUrl, canonical
 */
import fs from 'node:fs';
import path from 'node:path';
import nunjucks from 'nunjucks';
import { normalizePath } from 'vite';
import { panelManifest } from '../src/panel/routes.js';
import { pathFor as sitePath, shellPath, localeProblems, updateHistory, withPrefix } from './site-urls.js';
import { CATEGORIES, ZONE_KEY, blockEntry, instanceOf, readBlocks, renderInstance, spriteIcons } from './blocks.js';
import { legalManifest } from './legal.js';

/** Dinamik rotanın kabuk adresindeki yer tutucu. */
const ENTRY = '__entry';

/**
 * Çok site: `overlay` = tv/sites/<anahtar> (KM_SITE). Alt sitenin derlemesi ana kaynağın üstüne:
 * `i18n/<dil>.json` sözlüğe derin birleşir, `templates/` aynı yoldaki şablonun yerine geçer
 * (ör. templates/shell/site-header.njk), `site.config.js` vite.config.js'te birleşir (`exclude`:
 * derlenmeyecek rotalar). Panel ve render.php'den geçmeyen rotalar (prefix: false) alt sitede üretilmez:
 * panel ortaktır. Adres geçmişi sitenin kendi klasöründe (url-history.json).
 */
export const deepMerge = (base, extra) => Object.fromEntries([...new Set([...Object.keys(base), ...Object.keys(extra)])].map((key) => {
    const [a, b] = [base[key], extra[key]];
    const objects = a && b && typeof a === 'object' && typeof b === 'object' && !Array.isArray(a) && !Array.isArray(b);
    return [key, objects ? deepMerge(a, b) : (b === undefined ? a : b)];
}));

export function i18nPages({ srcDir, routes: allRoutes, site = {}, locales, defaultLocale, cookie, siteUrl, indexable = false, overlay = null, profile, profileDir, panelPages = [], extensionsDir = null, blockSources = [], iconsFile = null, legal = {} }) {
    const codes = Object.keys(locales);
    // Sözlük: çatınınki (tv/src/i18n: ortak bileşenlerin metinleri) + profilinki üstüne (+ alt sitenin katmanı).
    const i18nDir = path.join(srcDir, 'i18n');
    const profileI18n = path.join(profileDir, 'i18n');
    /** Profil rotasının göreli betiği profilin klasöründen (çatının rotaları `/pages/…` ile mutlak). */
    const scriptOf = (route) => (route.script.startsWith('/') ? route.script : `/profiles/${profile}/${route.script}`);
    const exclude = new Set(site.exclude || []);
    const routes = overlay ? allRoutes.filter((r) => r.prefix !== false && !exclude.has(r.name)) : allRoutes;
    const routeByName = new Map(routes.map((r) => [r.name, r]));
    let dicts = {};
    let isDev = false;
    let includeDev = false;
    let pages = [];
    let pagesByUrl = new Map();
    let pagesByFile = new Map();
    /** tx() ile basılan anahtarlar → kullanıldığı rotalar (manifest'e yazılır). */
    const editable = new Map();
    /** Metnin kendi sayfasındaki sırası (panelde sayfadaki sırayla listelenir). */
    const editableOrder = new Map();
    /** zone() ile açılan bölgeler → { label, routes } ve rotanın bölgeleri (sayfadaki sırayla): km-blocks.json. */
    const zoneUse = new Map();
    const routeZones = new Map();
    /** Bölgelerin varsayılan blokları (profilin `zones`'u; her dilde çözülmüş örnekler), bir kez kurulur. */
    const zoneDefaultsCache = new Map();
    // Şablonun verdiği ilk bloklar (profil `zones`'unda yoksa).
    const zoneFallbacks = new Map();
    /** Bloklar (vite/blocks.js): çatı, profil (aynı adla çatınınkinin yerine geçer), araç paketleri. */
    let blocks = {};

    function loadBlocks() {
        blocks = {};
        for (const { dir, source } of blockSources) {
            for (const [name, block] of Object.entries(readBlocks(dir, source))) {
                if (blocks[name] && source !== 'profile') throw new Error(`[blok] ${name} iki kez: ${blocks[name].source} ve ${source}`);
                blocks[name] = block;
            }
        }
        zoneDefaultsCache.clear();
    }

    // ------------------------------------------------------------ sözlük
    function loadDicts() {
        const read = (file) => (fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf-8')) : {});
        dicts = Object.fromEntries(codes.map((code) => {
            const base = deepMerge(read(path.join(i18nDir, `${code}.json`)), read(path.join(profileI18n, `${code}.json`)));
            return [code, overlay ? deepMerge(base, read(path.join(overlay, 'i18n', `${code}.json`))) : base];
        }));
    }

    function leafKeys(obj, prefix = '') {
        return Object.entries(obj).flatMap(([key, value]) => {
            const full = prefix ? `${prefix}.${key}` : key;
            return value && typeof value === 'object' && !Array.isArray(value) ? leafKeys(value, full) : [full];
        });
    }

    /** Varsayılan dildeki her anahtar diğer dillerde de olmalı (ve tersi). */
    function translationProblems() {
        const base = new Set(leafKeys(dicts[defaultLocale]));
        return codes.filter((c) => c !== defaultLocale).flatMap((code) => {
            const keys = new Set(leafKeys(dicts[code]));
            return [
                ...[...base].filter((k) => !keys.has(k)).map((k) => `${code}.json: eksik "${k}"`),
                ...[...keys].filter((k) => !base.has(k)).map((k) => `${code}.json: fazla "${k}" (${defaultLocale}.json'da yok)`),
            ];
        });
    }

    const lookup = (dict, key) => key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), dict);
    const interpolate = (text, params) => text.replace(/\{(\w+)\}/g, (match, name) => (params[name] ?? match));

    function translator(locale) {
        return (key, params = {}) => {
            let value = lookup(dicts[locale], key);
            if (value === undefined) value = lookup(dicts[defaultLocale], key);
            if (value === undefined) {
                const message = `[i18n] tanımsız anahtar: ${key} (${locale})`;
                if (!isDev) throw new Error(message);
                console.warn(message);
                return `⟦${key}⟧`;
            }
            return typeof value === 'string' ? interpolate(value, params) : value;
        };
    }

    // ------------------------------------------------------------ rotalar
    /** Rotanın dildeki adresi; dinamik rotada `:slug` verilmezse kalıp döner. */
    function pathFor(route, locale, params = null) {
        return sitePath(route, locale, locales, params);
    }

    /** Dilin ana sayfası (`/`, `/en/`). */
    const homeOf = (locale) => withPrefix(locales[locale]?.prefix || '', '/');

    // ------------------------------------------------------------ bloklar ve bölgeler
    const blockContext = () => ({ codes, defaultLocale, translators: Object.fromEntries(codes.map((code) => [code, translator(code)])) });

    /**
     * Bölgenin varsayılan blokları: profilin site.config.js `zones[anahtar]` listesi, yoksa şablonun verdiği
     * (`zone('tour.sections', 'Etiket', ['entry-highlights', …])`) → örnekler (`d1`, `d2`…).
     */
    function zoneDefaults(key) {
        if (!zoneDefaultsCache.has(key)) {
            const list = (site.zones || {})[key] || zoneFallbacks.get(key) || [];
            zoneDefaultsCache.set(key, list.map((raw, i) => {
                const item = typeof raw === 'string' ? { type: raw } : raw;
                if (!blocks[item.type]) throw new Error(`[blok] site.config.js zones['${key}']: blok yok: ${item.type}`);
                return instanceOf(blocks[item.type], item, { id: `d${i + 1}`, ...blockContext() });
            }));
        }
        return zoneDefaultsCache.get(key);
    }

    /** Sunucunun blok kitaplığı (kodmob-api Site\Blocks\BlockLibrary): bloklar, bölgeler, düzenlenebilir sayfalar. */
    function blocksManifest() {
        const context = blockContext();
        const t = translator(defaultLocale);
        // Sayfanın adı: menüdeki kısa adı; kayıt sayfasında listesinin adı; yoksa başlığın ilk parçası.
        const label = (route) => {
            const nav = (name) => lookup(dicts[defaultLocale], `nav.${name}`);
            if (route.last) return 'Blok sayfası';
            if (typeof nav(route.name) === 'string') return nav(route.name);
            if (route.dynamic && typeof nav(route.fallback) === 'string') return `${nav(route.fallback)} · kayıt sayfası`;
            const title = route.titleKey ? t(route.titleKey) : '';
            return typeof title === 'string' && title ? title.split(' · ')[0] : route.name;
        };
        return {
            version: 1,
            built: new Date().toISOString(),
            categories: CATEGORIES,
            icons: spriteIcons(iconsFile),
            blocks: Object.fromEntries(Object.entries(blocks).map(([name, block]) => [name, blockEntry(name, block, context)])),
            zones: Object.fromEntries([...zoneUse].map(([key, use]) => [key, { label: use.label || key, routes: [...use.routes], blocks: zoneDefaults(key) }])),
            pages: routes.filter((r) => !r.dev && r.prefix !== false).map((r) => ({
                name: r.name,
                label: label(r),
                dynamic: r.dynamic || null,
                ...(r.module ? { module: r.module } : {}),
                // Blok sayfası: düzenleyicide sayfa listesinde değil, kayıtlarıyla (Blok sayfaları).
                ...(r.last ? { records: true } : {}),
                paths: Object.fromEntries(codes.map((code) => [code, pathFor(r, code)]).filter(([, p]) => p)),
                zones: routeZones.get(r.name) || [],
            })),
        };
    }

    function collectPages() {
        pages = routes
            .filter((route) => includeDev || !route.dev)
            .flatMap((route) => Object.keys(route.paths).map((locale) => {
                const urlPath = pathFor(route, locale, route.dynamic ? { slug: ENTRY } : {});
                // Kabuk adresten bağımsız yerde (_pages/<dil>/…): adres değişse de dosya yeri sabit.
                const file = normalizePath(path.join(srcDir, shellPath(route, locale, ENTRY)));
                return { route, locale, urlPath, file, name: `${route.name}-${locale}`.replace(/[^\w-]/g, '_') };
            }));
        pagesByUrl = new Map(pages.map((p) => [p.urlPath, p]));
        pagesByFile = new Map(pages.map((p) => [p.file, p]));
    }

    /** Geliştirme sunucusu: adres → sayfa (dinamik rotalar kalıpla eşlenir). */
    function pageForUrl(url) {
        if (pagesByUrl.has(url)) return pagesByUrl.get(url);
        return pages.find((p) => {
            if (!p.route.dynamic) return false;
            const pattern = pathFor(p.route, p.locale).replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(':slug', '[^/]+');
            return new RegExp(`^${pattern}$`).test(url);
        });
    }

    // ------------------------------------------------------------ şablon
    function envFor(locale, routeName = '') {
        // Şablon arama sırası: alt sitenin katmanı → profil → çatı (tv/src).
        // Araç paketlerinin şablonları sonda (`<anahtar>/panel/_x.njk`; vite/extensions.js).
        const dirs = [...(overlay && fs.existsSync(path.join(overlay, 'templates')) ? [path.join(overlay, 'templates')] : []), profileDir, srcDir, ...(extensionsDir && fs.existsSync(extensionsDir) ? [extensionsDir] : [])];
        const env = new nunjucks.Environment(new nunjucks.FileSystemLoader(dirs, { noCache: isDev }), {
            autoescape: true,
            trimBlocks: true,
            lstripBlocks: true,
        });
        env.addGlobal('t', translator(locale));
        // Panelden değiştirilebilen metin: render.php işaretlerin arasını kayıtlı metinle değiştirir.
        env.addGlobal('tx', (key) => {
            const value = translator(locale)(key);
            if (typeof value !== 'string') throw new Error(`[i18n] tx() yalnızca metin alır: ${key}`);
            if (!editable.has(key)) editable.set(key, new Set());
            if (routeName) editable.get(key).add(routeName);
            if (routeName === key.split('.')[0] && !editableOrder.has(key)) editableOrder.set(key, editableOrder.size);
            return new nunjucks.runtime.SafeString(`<!--km:t ${key}-->${nunjucks.lib.escape(value)}<!--/km:t-->`);
        });
        // Sayfa düzenleyicinin bölgesi: varsayılan bloklar işaretlerin arasına; sunucu yayındaki düzenle değiştirir.
        env.addGlobal('zone', (key, label = '', defaults = null) => {
            if (!ZONE_KEY.test(key)) throw new Error(`[blok] bölge adı geçersiz: ${key} (ör. home.main)`);
            if (Array.isArray(defaults) && !zoneFallbacks.has(key)) zoneFallbacks.set(key, defaults);
            const use = zoneUse.get(key) || { label: '', routes: new Set() };
            if (label && !use.label) use.label = label;
            if (routeName) {
                use.routes.add(routeName);
                const list = routeZones.get(routeName) || [];
                if (!list.includes(key)) list.push(key);
                routeZones.set(routeName, list);
            }
            zoneUse.set(key, use);
            const href = (name) => (routeByName.has(name) ? pathFor(routeByName.get(name), locale, {}) : null);
            const html = zoneDefaults(key).map((instance) => renderInstance(blocks[instance.type], instance, locale, href)).join('');
            return new nunjucks.runtime.SafeString(`<!--km:zone ${key}-->${html}<!--/km:zone-->`);
        });
        env.addGlobal('url', (name, params = {}) => {
            const route = routeByName.get(name);
            // Alt sitede derlenmeyen rota (exclude, panel): bağlantı yok; şablon {% if url(...) %} ile gizler.
            if (!route && allRoutes.some((r) => r.name === name)) return null;
            if (!route) throw new Error(`[i18n] tanımsız rota: ${name}`);
            return pathFor(route, locale, params);
        });
        // <script type="application/json|ld+json"> içine güvenli JSON (</script> kapanmasın).
        env.addFilter('json', (value) => new nunjucks.runtime.SafeString(JSON.stringify(value).replace(/</g, '\\u003c')));
        // ISO tarih → "5 Eylül 2026" (sayfanın dilinde; ör. yasal metinlerin güncelleme tarihi)
        env.addFilter('date', (value) => new Intl.DateTimeFormat(locales[locale].intl, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(value)));
        return env;
    }

    function organization(locale) {
        const t = translator(locale);
        const org = site.organization || {};
        return {
            '@context': 'https://schema.org',
            '@type': org.type || 'Organization',
            '@id': `${siteUrl}/#organization`,
            name: t('meta.siteName'),
            url: `${siteUrl}${homeOf(locale)}`,
            logo: `${siteUrl}/assets/favicon/favicon.svg`,
            description: t('meta.description'),
            ...(org.address ? { address: { '@type': 'PostalAddress', ...org.address } } : {}),
            ...(org.areaServed?.length ? { areaServed: org.areaServed } : {}),
        };
    }

    /**
     * Tarayıcıya giden sayfa bilgisi (#km-i18n): istemci metinleri, rota
     * adresleri (içerik bağlantıları tarayıcıda kurulur), etiket sözlükleri,
     * dil önerisi bandı ve arama dizininin sayfa kısmı.
     */
    function clientPayload(page, alternates) {
        const { locale } = page;
        const t = translator(locale);
        const patterns = {};
        for (const route of routes) {
            if (route.dynamic) patterns[route.name] = Object.fromEntries(codes.map((code) => [code, pathFor(route, code)]).filter(([, p]) => p));
        }
        const routeUrls = Object.fromEntries(routes.filter((r) => !r.dev).map((r) => [r.name, pathFor(r, locale)]).filter(([, p]) => p));
        return {
            locale,
            strings: lookup(dicts[locale], 'client') ?? {},
            routes: routeUrls,
            patterns,
            // Kayıt sayfaları: kayıt adı (rotanın `dynamic`'i: post, project…) → rota adı (patterns anahtarı).
            records: Object.fromEntries(routes.filter((r) => r.dynamic).reverse().map((r) => [r.dynamic, r.name])),
            // Etiket sözlükleri profilin vocab listesinden (ör. blogCategories, projectCategories) + okuma süresi.
            labels: {
                ...Object.fromEntries(Object.entries(site.vocab || {}).map(([name, key]) => [name, lookup(dicts[locale], key) ?? {}])),
                readTime: lookup(dicts[locale], 'blog.readTime') ?? '',
            },
            alternates: Object.fromEntries(alternates.map((a) => [a.locale, a.href])),
            banner: Object.fromEntries(codes.map((code) => [code, dicts[code].langBanner])),
            searchPages: (site.searchPages || ['home'])
                .filter((name) => routeByName.has(name))
                .map((name) => ({ title: t(`nav.${name}`), url: pathFor(routeByName.get(name), locale) }))
                .filter((item) => item.url),
        };
    }

    async function render(page) {
        const { route, locale } = page;
        const t = translator(locale);
        const home = routeByName.get('home');
        const abs = (p) => `${siteUrl}${p}`;
        // Dinamik kabukta dil karşılıkları kayda göre değişir: tarayıcı ve render.php doldurur.
        const alternates = route.dynamic || route.noindex ? [] : Object.keys(route.paths)
            .map((code) => ({ locale: code, href: pathFor(route, code, {}), abs: abs(pathFor(route, code, {})) }));
        // Dil seçici: sayfanın o dildeki karşılığı yoksa rotanın yedeğine (ör. proje listesi), o da yoksa ana sayfaya.
        // Dizinlenmeyen sayfalar (404) diğer dillerin aynı sayfasına değil ana sayfasına gider.
        const fallback = route.fallback ? routeByName.get(route.fallback) : home;
        const switcher = codes.map((code) => ({
            locale: code,
            ...locales[code],
            href: (!route.dynamic && !route.noindex && pathFor(route, code, {})) || pathFor(fallback, code) || pathFor(home, code),
            current: code === locale,
        }));
        const context = {
            // Projenin özel panel sayfaları (panel kabuğu şablonlarını içerir; profilin panel.js PAGES).
            panelPages,
            locale,
            localeInfo: locales[locale],
            locales,
            defaultLocale,
            page: { name: route.name, path: page.urlPath, script: scriptOf(route), dynamic: route.dynamic || null, needs: route.needs || [] },
            title: route.titleKey ? t(route.titleKey) : t('meta.siteName'),
            description: route.descriptionKey ? t(route.descriptionKey) : t('meta.description'),
            siteUrl,
            canonical: abs(page.urlPath),
            alternates,
            switcher,
            robots: indexable && !route.noindex ? 'index, follow' : 'noindex, nofollow',
            year: new Date().getFullYear(),
            organization: organization(locale),
            clientPayload: clientPayload(page, alternates),
        };
        if (route.data) Object.assign(context, await route.data(locale));
        return envFor(locale, route.name).render(route.template, context);
    }

    // ------------------------------------------------------------ üretilen dosyalar
    /** render.php'nin rota tablosu (site kökünde km-manifest.json). */
    function manifest() {
        return {
            built: new Date().toISOString(),
            siteUrl,
            indexable,
            // Dil yapısı (karar A: build ile değişir). Sunucudaki dil kaydı önek ve varsayılanı buradan alır.
            default: defaultLocale,
            locales: Object.fromEntries(codes.map((code) => [code, {
                prefix: locales[code].prefix || '', label: locales[code].label, short: locales[code].short,
                intl: locales[code].intl, og: locales[code].og, dir: locales[code].dir || 'ltr', flag: locales[code].flag,
            }])),
            strings: Object.fromEntries(codes.map((code) => {
                const t = translator(code);
                // Modüle ya da profile bağlı metinler: profilde yoksa boş (ör. projeler türü olmayan site).
                const opt = (key) => (lookup(dicts[code], key) ?? lookup(dicts[defaultLocale], key) ?? '');
                return [code, {
                    siteName: t('meta.siteName'),
                    titleSuffix: t('meta.titleSuffix'),
                    // Logo yazısı (üst bar ve alt bilgi); sunucu panelde değiştirilmişse kabukta değiştirir.
                    brand: t('nav.brand'),
                    brandSub: t('nav.brandSub'),
                    home: t('nav.home'),
                    projects: opt('nav.projects'),
                    blog: opt('nav.blog'),
                    // Kayıt sayfalarının listesi (rotanın `fallback`'i) → menüdeki adı: kırıntı yolu, llms.txt.
                    lists: Object.fromEntries([...new Set(routes.filter((r) => r.dynamic).map((r) => r.fallback || 'home'))].map((name) => [name, opt(`nav.${name}`)])),
                    blogTitle: `${opt('nav.blog')} · ${t('meta.siteName')}`,
                    blogDescription: opt('blog.meta.description'),
                    // Sunucunun bastığı metinler (kodmob-api SiteManifest::string).
                    description: t('meta.description'),
                    toc: opt('post.toc'),
                    briefReplySubject: opt('brief.reply.subject'),
                    briefReplyBody: opt('brief.reply.body'),
                }];
            })),
            // Sözlükler (site.config.js vocab): dil → anahtar → etiket. Sunucu panel seçeneklerini ve doğrulamayı buradan alır.
            vocab: Object.fromEntries(Object.entries(site.vocab || {}).map(([name, key]) => [name, Object.fromEntries(codes.map((code) => {
                const value = translator(code)(key);
                return [code, value && typeof value === 'object' ? value : {}];
            }))])),
            // Projenin profili (site.config.js `profile`): açık modüller ve içerik türleri (kodmob-api Modules, Types).
            ...(site.profile ? { profile: { name: profile, modules: site.profile.modules, contentTypes: site.profile.contentTypes, needs: site.profile.needs || [] } } : {}),
            // Panelin "Sayfa metinleri": anahtar → { on: [rotalar], tr, en, de } (sözlükteki hali; sayfadaki sırayla)
            texts: Object.fromEntries([...editable.keys()].sort((a, b) => (editableOrder.get(a) ?? Infinity) - (editableOrder.get(b) ?? Infinity)).map((key) => [key, {
                on: [...editable.get(key)],
                ...Object.fromEntries(codes.map((code) => [code, translator(code)(key)])),
            }])),
            routes: pages.filter((p) => !p.route.dev && p.route.prefix !== false).map((p) => ({
                name: p.route.name,
                locale: p.locale,
                ...(p.route.dynamic ? { pattern: pathFor(p.route, p.locale), dynamic: p.route.dynamic, fallback: p.route.fallback || 'home' } : { path: p.urlPath }),
                // En son denenen kalıp (blok sayfaları `/:slug/`; kodmob-api Router::findLast).
                ...(p.route.last ? { last: true } : {}),
                shell: path.posix.relative(normalizePath(srcDir), p.file),
                needs: p.route.needs || [],
                // Rotanın ait olduğu isteğe bağlı modül: projede kapalıysa sayfa 404 (kodmob-api Renderer::moduleOff).
                ...(p.route.module ? { module: p.route.module } : {}),
                // Yasal belge (vite/legal.js; gövdesini sunucu yazar: Site\Legal\LegalSsr).
                ...(p.route.legal ? { legal: p.route.legal } : {}),
                sitemap: !p.route.dynamic && p.route.sitemap !== false,
                ...(p.route.name === 'notFound' ? { status: 404 } : {}),
            })),
            // Panel adresleri (src/panel/routes.js): kodmob-api PanelUrl bildirim, e-posta ve işlem kaydı bağlantılarını buradan üretir.
            panel: panelManifest(),
            // Eski adresler (profilin i18n/url-history.json): render.php 301 ile yenisine yönlendirir.
            legacy: history.legacy,
        };
    }

    // ------------------------------------------------------------ adres geçmişi
    const historyFile = overlay ? path.join(overlay, 'url-history.json') : path.join(profileI18n, 'url-history.json');
    let history = { legacy: { prefixes: {}, paths: [] } };

    /** Bugünkü adresler önceki build'inkilerle karşılaştırılır; değişenler legacy'ye girer. Dosyayı build yazar. */
    function refreshHistory(write) {
        const before = fs.existsSync(historyFile) ? JSON.parse(fs.readFileSync(historyFile, 'utf-8')) : null;
        const current = Object.fromEntries(pages.filter((p) => !p.route.dev && p.route.prefix !== false).map((p) => [`${p.route.name}|${p.locale}`, p.route.dynamic ? pathFor(p.route, p.locale) : p.urlPath]));
        const prefixes = Object.fromEntries(codes.map((code) => [code, locales[code].prefix || '']));
        const result = updateHistory(before, current, prefixes);
        history = result.history;
        if (write && result.changed) fs.writeFileSync(historyFile, `${JSON.stringify(history, null, 2)}\n`);
    }

    return {
        name: 'km-i18n-pages',
        enforce: 'pre',

        config(_, { command, mode }) {
            isDev = command === 'serve';
            includeDev = isDev || mode !== 'production';
            const problems = localeProblems(locales, defaultLocale);
            if (problems.length) throw new Error(`[i18n] locales.js:\n  ${problems.join('\n  ')}`);
            loadDicts();
            loadBlocks();
            collectPages();
            refreshHistory(false);
            if (isDev) return undefined;
            return { build: { rollupOptions: { input: Object.fromEntries(pages.map((p) => [p.name, p.file])) } } };
        },

        buildStart() {
            const problems = translationProblems();
            if (!problems.length) return;
            const message = `Eksik ya da fazla çeviri:\n  ${problems.join('\n  ')}`;
            if (isDev) this.warn(message);
            else this.error(message);
        },

        resolveId(id) {
            return pagesByFile.has(normalizePath(id)) ? normalizePath(id) : null;
        },

        load(id) {
            const page = pagesByFile.get(normalizePath(id));
            return page ? render(page) : null;
        },

        configureServer(server) {
            server.middlewares.use(async (req, res, next) => {
                if (req.method !== 'GET' && req.method !== 'HEAD') return next();
                let url = decodeURI(req.url.split('?')[0]);
                if (url.startsWith('/api') || url.startsWith('/@') || url.startsWith('/node_modules')) return next();

                if (path.extname(url) && !url.endsWith('.html')) return next();
                // Panel tek sayfa: /panel/ altındaki her adres panelin kendisi (yönlendirme src/panel/routes.js).
                if (url.startsWith('/panel/') && !path.extname(url)) url = '/panel/';

                let page = pageForUrl(url);
                if (!page && !path.extname(url) && pageForUrl(`${url}/`)) {
                    res.writeHead(302, { Location: `${url}/` });
                    return res.end();
                }
                let status = 200;
                if (!page) {
                    if (!(req.headers.accept || '').includes('text/html')) return next();
                    // 404 sayfasının dili adresin önekinden (yoksa varsayılan dil).
                    const locale = codes.find((code) => locales[code].prefix && url.startsWith(`/${locales[code].prefix}/`)) || defaultLocale;
                    page = pages.find((p) => p.route.name === 'notFound' && p.locale === locale);
                    status = 404;
                }
                try {
                    const html = await server.transformIndexHtml(req.url, await render(page), req.originalUrl);
                    res.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8' });
                    res.end(html);
                } catch (error) {
                    server.config.logger.error(error.stack || String(error));
                    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
                    res.end(`Şablon hatası (${page.route.template}):\n\n${error.message}`);
                }
            });
        },

        handleHotUpdate({ file, server }) {
            const f = normalizePath(file);
            const isDict = (f.startsWith(normalizePath(i18nDir)) || f.startsWith(normalizePath(profileI18n))) && f.endsWith('.json');
            if (isDict) {
                loadDicts();
                const problems = translationProblems();
                if (problems.length) server.config.logger.warn(`Eksik ya da fazla çeviri:\n  ${problems.join('\n  ')}`);
            }
            const isBlock = /\/blocks\/[^/]+\/block\.(json|html)$/.test(f);
            if (isBlock) loadBlocks();
            if (isDict || isBlock || f.endsWith('.njk') || (f.endsWith('.html') && f.startsWith(normalizePath(srcDir)))) {
                server.ws.send({ type: 'full-reload' });
                return [];
            }
            return undefined;
        },

        generateBundle() {
            refreshHistory(true);
            this.emitFile({ type: 'asset', fileName: 'km-manifest.json', source: JSON.stringify(manifest(), null, 1) });
            this.emitFile({ type: 'asset', fileName: 'km-blocks.json', source: JSON.stringify(blocksManifest()) });
            this.emitFile({ type: 'asset', fileName: 'km-legal.json', source: JSON.stringify(legalManifest(legal, codes, translator)) });
        },
    };
}
