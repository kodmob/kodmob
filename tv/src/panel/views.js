/**
 * Panel görünümlerinin tembel yüklenmesi (kod bölme). İlk açılışta yalnızca kabuk iner: giriş,
 * menü, bildirimler, medya penceresi. Her görünümün modülü ve kullandığı bileşenler o görünüme
 * ilk geçişte indirilir. kmPanel görünümü bileşenler Alpine'a kaydedildikten sonra açar
 * (index.njk `viewReady`), böylece şablondaki `x-data="kmEditor(...)"` hazır bileşen bulur.
 *
 * Yeni görünüm: buraya `görünüm: yükleyici` (şablondaki x-data adlarıyla) ve index.njk'ye x-if.
 * Görünüm burada yoksa kabuktadır, beklenmeden açılır (ör. notFound). Projenin sayfaları profilin
 * panel.js'inden (`PAGES`: `load`, `template`) kendiliğinden eklenir.
 */
import { PROJECT_PAGES } from './project.js';

const mod = (load, ...names) => () => load().then((m) => Object.fromEntries(names.map((name) => [name, m[name]])));
const all = (...loaders) => () => Promise.all(loaders.map((load) => load())).then((parts) => Object.assign({}, ...parts));

// Birden çok görünümün kullandığı parçalar (Vite bunları ortak dosyaya ayırır).
const grid = mod(() => import('../ui/grid/grid.js'), 'kmGrid', 'dfGrid');
const languageTabs = mod(() => import('./languages.js'), 'kmLanguageTabs');

const VIEWS = {
    overview: mod(() => import('./overview.js'), 'kmOverview'),
    other: mod(() => import('./other.js'), 'kmOther'),
    list: all(grid, mod(() => import('./list.js'), 'kmList')),
    editor: all(mod(() => import('./editor.js'), 'kmEditor'), mod(() => import('./richtext.js'), 'kmRichText'), mod(() => import('./seo-box.js'), 'kmSeoBox')),
    texts: all(languageTabs, mod(() => import('./texts.js'), 'kmTexts')),
    languages: all(languageTabs, mod(() => import('./languages.js'), 'kmLanguages')),
    builder: all(mod(() => import('./builder.js'), 'kmBuilder'), mod(() => import('./richtext.js'), 'kmRichText')),
    legal: all(mod(() => import('./legal.js'), 'kmLegal'), mod(() => import('./richtext.js'), 'kmRichText')),
    estimate: mod(() => import('./estimate.js'), 'kmEstimate'),
    briefs: all(grid, mod(() => import('./briefs.js'), 'kmBriefs')),
    jarvis: mod(() => import('./jarvis.js'), 'kmJarvis'),
    domains: all(grid, mod(() => import('./domains.js'), 'kmDomains')),
    orders: all(grid, mod(() => import('./orders.js'), 'kmOrders')),
    coupons: all(grid, mod(() => import('./coupons.js'), 'kmCoupons')),
    bookings: all(grid, mod(() => import('./bookings.js'), 'kmBookings')),
    newsletter: all(grid, mod(() => import('./newsletter.js'), 'kmNewsletter'), mod(() => import('./richtext.js'), 'kmRichText')),
    projectSettings: mod(() => import('./settings.js'), 'kmSettings'),
    settings: all(mod(() => import('./settings.js'), 'kmSettings'), mod(() => import('./backup.js'), 'kmBackup'), mod(() => import('./system.js'), 'kmMail', 'kmSms', 'kmAi', 'kmJobs'), mod(() => import('./auto-backup.js'), 'kmAutoBackup')),
    seo: all(grid, mod(() => import('./seo.js'), 'kmSeo')),
    history: all(grid, mod(() => import('./history.js'), 'kmHistory')),
    users: all(grid, mod(() => import('./users.js'), 'kmUsers')),
    roles: mod(() => import('./roles.js'), 'kmRoles'),
    security: all(grid, mod(() => import('./security.js'), 'kmSecurity')),
    sites: mod(() => import('./sites.js'), 'kmSites'),
    health: mod(() => import('./health.js'), 'kmHealth'),
    tools: mod(() => import('./tools.js'), 'kmTools'),
    profile: all(mod(() => import('./profile.js'), 'kmProfile'), mod(() => import('./two-factor.js'), 'kmTwoFactor')),
    guide: mod(() => import('./guide.js'), 'kmGuide', 'kmGuideTour'),
};

// Projenin özel sayfaları (profilin panel.js PAGES `load`): modülün dışa açtığı işlevler bileşen olarak kaydedilir.
const functions = (m) => Object.fromEntries(Object.entries(m).filter(([, value]) => typeof value === 'function'));
for (const page of PROJECT_PAGES) {
    if (typeof page.load === 'function') VIEWS[page.key] = () => page.load().then(functions);
}

const registered = new Set();
const pending = new Map();

/** Görünümün beklenmesi gerekiyor mu (yükleyicisi var mı). */
export function isLazyView(view) {
    return Object.hasOwn(VIEWS, view);
}

/** Görünümün bileşenlerini indirip Alpine'a kaydeder (bir kez). */
export function loadView(Alpine, view) {
    if (!isLazyView(view)) return Promise.resolve();
    if (!pending.has(view)) {
        pending.set(view, VIEWS[view]().then((components) => {
            for (const [name, factory] of Object.entries(components)) {
                if (registered.has(name) || typeof factory !== 'function') continue;
                Alpine.data(name, factory);
                registered.add(name);
            }
        }).catch((error) => {
            pending.delete(view);
            throw error;
        }));
    }
    return pending.get(view);
}

/** Boşta kalınca en sık açılan görünümleri önceden indir (sonraki tık beklemesin). */
export function preloadViews(Alpine, views = ['list', 'editor']) {
    const run = () => views.forEach((view) => loadView(Alpine, view).catch(() => {}));
    if ('requestIdleCallback' in window) window.requestIdleCallback(run, { timeout: 4000 });
    else setTimeout(run, 2000);
}
