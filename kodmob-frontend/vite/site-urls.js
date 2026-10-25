/**
 * Site adresleri — build tarafı (vite/i18n-pages.js kullanır; tests/site-urls.test.mjs sınar).
 *
 * Adres = dil öneki + rotanın o dildeki yolu: Türkçe (varsayılan, önek yok) `/projeler/x/`,
 * İngilizce `/en/projects/x/`. Önekler profilin locales.js'indedir (karar A: dil yapısı build
 * ile değişir). Sunucu (kodmob-core Site\Render\Router) yalnızca km-manifest.json'daki
 * adreslerle eşler; aynı sonuçları tests/fixtures/site-urls.json vektörleri denetler.
 *
 * Sayfa kabukları adresten bağımsız bir yerde durur: `_pages/<dil>/<yol>/index.html`.
 * Böylece kabuk adresle çakışmaz, eski sürümün `tr/` klasörü bayat kabuk sunmaz; .htaccess
 * `_pages/`'i dışarıya kapatır, render.php dosyadan okur.
 *
 * Adres geçmişi (profilin i18n/url-history.json): her build bugünkü adresleri öncekilerle
 * karşılaştırır; değişen adres ve kalkan önek `legacy` olarak manifest'e yazılır ve sunucu
 * eski adresi 301 ile yenisine yönlendirir (ör. `/tr/projeler/x/` → `/projeler/x/`).
 */

/** Önek biçimi: iki-üç harf, isteğe bağlı bölge eki (en, de, pt-br). */
export const PREFIX = /^[a-z]{2,3}(-[a-z0-9]{2,8})?$/;

/** Hiçbir dilin öneki olamaz: sitenin kendi klasörleri (kodmob-core LanguageService::RESERVED ile aynı). */
export const RESERVED = ['panel', 'api', 'media', 'assets', 'ui', 'sitemap', 'robots', 'llms', 'feed', 'index', 'static'];

/** Kabukların klasörü (dist kökünde; doğrudan erişilemez). */
export const SHELLS = '_pages';

/** Önek + yol: ('', '/projeler/') → '/projeler/', ('en', '/projects/') → '/en/projects/'. */
export const withPrefix = (prefix, path) => (prefix ? `/${prefix}${path}` : path);

/** Rotanın dildeki adresi; `params` verilirse `:slug` doldurulur, verilmezse kalıp döner. */
export function pathFor(route, locale, locales, params = null) {
    let p = route.paths[locale];
    if (p == null) return null;
    if (params) p = p.replace(/:(\w+)/g, (_, name) => encodeURIComponent(params[name] ?? ''));
    return route.prefix === false ? p : withPrefix(locales[locale]?.prefix || '', p);
}

/** Kabuk dosyasının site kökünden yolu: `_pages/tr/projeler/__entry/index.html`. Önekli olmayan rotalar (panel) adresinde. */
export function shellPath(route, locale, entry) {
    let p = route.paths[locale];
    if (route.dynamic) p = p.replace(':slug', entry);
    const file = p.endsWith('/') ? `${p}index.html` : p;
    return route.prefix === false ? file.replace(/^\//, '') : `${SHELLS}/${locale}${file}`;
}

/** Dil öneklerinin tutarlılığı: varsayılan dil öneksiz, diğerleri benzersiz ve geçerli. Sorun listesi döner. */
export function localeProblems(locales, defaultLocale) {
    const problems = [];
    const seen = new Map();
    if (!locales[defaultLocale]) problems.push(`varsayılan dil (${defaultLocale}) locales.js'te yok`);
    for (const [code, info] of Object.entries(locales)) {
        const prefix = info.prefix ?? null;
        if (prefix === null) problems.push(`${code}: prefix tanımsız`);
        else if (prefix === '') {
            if (code !== defaultLocale) problems.push(`${code}: yalnızca varsayılan dil (${defaultLocale}) öneksiz olabilir`);
        } else if (!PREFIX.test(prefix)) problems.push(`${code}: geçersiz önek "${prefix}"`);
        else if (RESERVED.includes(prefix)) problems.push(`${code}: "${prefix}" sitenin kendi adreslerinden biri`);
        else if (seen.has(prefix)) problems.push(`${code}: "${prefix}" öneki ${seen.get(prefix)} ile aynı`);
        seen.set(prefix, code);
    }
    if (locales[defaultLocale] && locales[defaultLocale].prefix !== '') problems.push(`${defaultLocale}: varsayılan dil öneksiz olmalı`);
    return problems;
}

/**
 * Adres geçmişini günceller. `routes`: "rota|dil" → bugünkü adres; `prefixes`: dil → önek.
 * Değişen adres `legacy.paths`'e, kalkan önek `legacy.prefixes`'e eklenir; bugün kullanılan
 * adres ve önekler legacy'den düşer (yeni sayfa her zaman önceliklidir).
 *
 * @returns {{ history: object, changed: boolean }}
 */
export function updateHistory(history, routes, prefixes) {
    const old = history || {};
    const legacy = {
        prefixes: Object.fromEntries(Object.entries(old.legacy?.prefixes || {}).map(([k, v]) => [k, [...v]])),
        paths: [...(old.legacy?.paths || [])].map((p) => ({ ...p })),
    };
    for (const [code, before] of Object.entries(old.prefixes || {})) {
        const now = prefixes[code];
        if (now !== undefined && before && before !== now) legacy.prefixes[code] = [...new Set([...(legacy.prefixes[code] || []), before])];
    }
    for (const [key, before] of Object.entries(old.routes || {})) {
        const now = routes[key];
        if (now && now !== before && !legacy.paths.some((p) => p.from === before)) {
            const [name, locale] = key.split('|');
            legacy.paths.push({ from: before, name, locale });
        }
    }
    const currentUrls = new Set(Object.values(routes));
    const currentPrefixes = new Set(Object.values(prefixes).filter(Boolean));
    legacy.paths = legacy.paths.filter((p) => !currentUrls.has(p.from));
    for (const code of Object.keys(legacy.prefixes)) {
        legacy.prefixes[code] = legacy.prefixes[code].filter((p) => !currentPrefixes.has(p));
        if (!legacy.prefixes[code].length) delete legacy.prefixes[code];
    }
    const next = { _: old._, routes, prefixes, legacy };
    return { history: next, changed: JSON.stringify(next) !== JSON.stringify(old) };
}
