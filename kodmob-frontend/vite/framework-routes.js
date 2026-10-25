/**
 * Çatının rotaları: her profilde aynı, profilin rota tablosuna eklenir (vite.config.js). Şablon ve betik
 * kodmob-frontend/src altında (profilde değil). Alan anlamları profilin routes.config.js'indeki gibi.
 */
import { bookingRoutes, commerceRoutes, hotelRoutes } from './commerce-routes.js';
import { legalRoutes } from './legal.js';

const page = (name) => ({ template: `pages/${name}/index.njk`, script: `/pages/${name}/main.js` });

export const FRAMEWORK_ROUTES = [
    // Yönetim paneli (gizli adres, giriş gerekir). Türkçe; içerik kendi uçlarından gelir.
    { name: 'panel', paths: { tr: '/panel/' }, prefix: false, ...page('panel'), sitemap: false, noindex: true },
    // Panel bileşen vitrini (panel stil paketi; ortak tablo, rozetler, formlar, katmanlar). Yalnızca geliştirmede.
    { name: 'uiPanel', paths: { tr: '/_ui/panel/' }, prefix: false, ...page('_ui-panel'), titleKey: 'ui.title', dev: true, sitemap: false, noindex: true },
];

/**
 * Blok sayfaları (panelden açılan, içeriği Sayfa düzenleyicinin blokları; kodmob-core Site\Pages\PageType): her dilde
 * kökte `/:slug/`. `last`: sunucu bu kalıbı en son dener (sitenin sayfaları ve eski adres yönlendirmeleri önce).
 */
export const blockPageRoute = (codes) => ({
    name: 'page', dynamic: 'page', fallback: 'home', last: true,
    paths: Object.fromEntries(codes.map((code) => [code, '/:slug/'])),
    ...page('page'),
    titleKey: 'meta.siteName',
});

/**
 * Bülten'in sayfası (araç `newsletter`): e-postadaki onay ve abonelikten çıkma bağlantıları buraya gelir; bağlantısız
 * açılınca abonelik formu (bölge). Arama motoru dışı. Profil adresini değiştirmek isterse rotalarına
 * `...newsletterRoutes({ newsletter: { tr: '/e-bulten/' } }, ['tr'])` yazar.
 */
export const NEWSLETTER_PATHS = { newsletter: { tr: '/bulten/', en: '/newsletter/', de: '/newsletter/' } };

export function newsletterRoutes(paths = {}, locales = null) {
    const p = { ...NEWSLETTER_PATHS, ...paths };
    const only = (byLocale) => (locales ? Object.fromEntries(Object.entries(byLocale).filter(([code]) => locales.includes(code))) : byLocale);

    return [{
        name: 'newsletter', paths: only(p.newsletter), ...page('newsletter'), module: 'newsletter',
        titleKey: 'newsletter.meta.title', descriptionKey: 'newsletter.meta.description', sitemap: false, noindex: true,
    }];
}

/**
 * Çatının araçlarının site sayfaları (Mağaza, Rezervasyon, Oteller, Bülten): profil kendi rotalarında yazmadıysa
 * (adını kullanmadıysa) varsayılan adresleriyle her derlemeye girer. Araç kapalıyken sayfalar 404 ve site haritası
 * dışı (rotanın `module`'ü); araç Araç Pazarı › Keşfet'ten derlemesiz eklenince açılır. Profil `toolPages: false` ile almaz.
 */
export const toolRoutes = (codes, existing = []) => {
    const names = new Set(existing.map((r) => r.name));
    return [...commerceRoutes({}, codes), ...bookingRoutes({}, codes), ...hotelRoutes({}, codes), ...newsletterRoutes({}, codes)]
        .filter((r) => !names.has(r.name) && Object.keys(r.paths).length);
};

/**
 * Profilin rotalarından sonra gelenler: panel, yasal belgeler (vite/legal.js; `legal`: readLegal'in çıktısı), araç
 * sayfaları, blok sayfası en sonda.
 */
export const frameworkRoutes = (codes, existing = [], { tools = true, legal = {} } = {}) => [
    ...FRAMEWORK_ROUTES,
    ...legalRoutes(codes, legal, existing),
    ...(tools ? toolRoutes(codes, existing) : []),
    blockPageRoute(codes),
];
