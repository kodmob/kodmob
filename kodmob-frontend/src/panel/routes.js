/**
 * Panel adresleri — tek kaynak (done-coklu-dil-raporu.md §4.1).
 *
 * Adresler `/panel/…` yollarıdır (History API, `#` yok). Her rotanın kodda kullanılan,
 * değişmeyen bir adı (`name`) ve adreste görünen Türkçe bir yolu (`path`) vardır.
 * Bağlantılar elle yazılmaz:
 *
 *   href('editor', { type: 'projects', id: 12 })   → /panel/projeler/12
 *   href('seo', { tab: 'redirects' })                → /panel/seo/yonlendirmeler
 *   href('briefs', {}, { status: 'new' })            → /panel/talepler?status=new
 *
 * Yol parçaları: sabit metin · `:type` içerik türü (TYPE_SLUGS) · `:id` / `:job` sayı ·
 * `:idOrNew` sayı ya da `yeni` (yeni kayıt, id 0) · `:tab` görünümün sekmesi (TAB_SLUGS;
 * varsayılan sekme adrese yazılmaz). Sonu `?` olan parça isteğe bağlıdır. Sıra önemlidir:
 * ilk eşleşen rota kazanır.
 *
 * Build bu tabloyu km-manifest.json'a (`panel`) yazar; kodmob-core `Core\Support\PanelUrl`
 * aynı tablodan adres üretir. İki taraf aynı vektörlerle sınanır
 * (kodmob-core/tests/fixtures/panel-urls.json; kodmob-frontend/tests/panel-routes.test.mjs).
 *
 * Eski adresler (`/panel/#/briefs/12`: gönderilmiş e-postalar, yer imleri, eski bildirimler)
 * `fromLegacyHash()` ile yenisine çevrilir. Bu eşleme kalıcıdır, silinmemeli.
 */
export const BASE = '/panel';

/**
 * İçerik türü (API adı) → adresteki parça. Türleri types.js verir (`useTypes`; projeninkiler profilin
 * panel.js'inde); bu modül türleri kendisi yüklemez: Node (derleme, testler) de onu açabilsin.
 */
export let TYPE_SLUGS = {};
let TYPE_BY_SLUG = {};

/** Tür tablosundan (types.js TYPES biçimi) ad → adres parçası. */
export const typeSlugs = (types) => Object.fromEntries(Object.entries(types).map(([name, t]) => [name, t.slug]));

/** Panelin içerik türleri (types.js yüklenince; Node'da derleme ya da test verir). */
export function useTypes(types) {
    TYPE_SLUGS = typeSlugs(types);
    TYPE_BY_SLUG = flip(TYPE_SLUGS);
}

/** Görünüm → sekme anahtarı → adresteki parça. Listede olmayan sekme (varsayılan) adrese yazılmaz. */
export const TAB_SLUGS = {
    seo: { content: 'icerik', ai: 'yapay-zeka', pages: 'sayfalar', redirects: 'yonlendirmeler', 404: '404', robots: 'robots', settings: 'ayarlar' },
    security: { sessions: 'oturumlar', events: 'kayitlar', blocks: 'ip-engelleri', lockouts: 'kilitli-girisler', settings: 'ayarlar' },
    settings: { mail: 'e-posta', sms: 'sms', ai: 'yapay-zeka', jobs: 'arka-plan', backup: 'yedekleme' },
    languages: { list: 'liste', urls: 'adresler', visitor: 'ziyaretci', health: 'saglik' },
    history: { all: 'tumu' },
    tools: { discover: 'kesfet' },
    newsletter: { subscribers: 'aboneler' },
};

/**
 * `key`: görünüm ne zaman yeniden kurulur. Aynı anahtarda kalan adres değişikliği (sekme,
 * sohbet, sorgu) görünümü kurmaz; `km-route` olayı gider. Varsayılan anahtar rotanın adıdır.
 */
export const ROUTES = [
    { name: 'overview', path: '' },
    { name: 'other', path: 'diger-islemler' },
    { name: 'briefs', path: 'talepler/:id?', key: (p) => `briefs-${p.id || ''}` },
    { name: 'domains', path: 'jarvis/domain-arama/:job?', key: (p) => `domains-${p.job || ''}` },
    { name: 'jarvis', path: 'jarvis/:id?' },
    // Çoklu Dil: Metinler kendi görünümü (sayfa metinleri yetkisi), diğer sekmeler `languages`.
    { name: 'texts', path: 'diller/metinler' },
    { name: 'languages', path: 'diller/:tab?' },
    // Sayfa düzenleyici: sayfa ve dil sorguda (?sayfa=home&dil=en).
    { name: 'builder', path: 'sayfa-duzenleyici' },
    // Yasal belgeler: açık modüllerin aydınlatma metinleri ve sözleşmeleri, dil başına özelleştirme.
    { name: 'legal', path: 'yasal-belgeler' },
    { name: 'estimate', path: 'teklif-hesaplayici' },
    // Mağaza (isteğe bağlı modül commerce): siparişler ve kuponlar; ürünler içerik türü (/panel/urunler).
    { name: 'orders', path: 'siparisler/:id?', key: (p) => `orders-${p.id || ''}` },
    { name: 'coupons', path: 'kuponlar' },
    { name: 'bookings', path: 'rezervasyonlar' },
    // Bülten (isteğe bağlı araç newsletter): bültenler (varsayılan) ve aboneler.
    { name: 'newsletter', path: 'bulten/:tab?' },
    { name: 'seo', path: 'seo/:tab?' },
    // Ayarlar ikiye ayrık: Proje Ayarları (bu proje) ve Sistem Ayarları (kurulumun tamamı; rota adı `settings`).
    { name: 'projectSettings', path: 'proje-ayarlari/:tab?' },
    { name: 'settings', path: 'sistem-ayarlari/:tab?' },
    { name: 'history', path: 'islem-gecmisi/:tab?/:id?' },
    { name: 'users', path: 'kullanicilar/:id?' },
    { name: 'roles', path: 'roller' },
    { name: 'security', path: 'guvenlik/:tab?' },
    { name: 'sites', path: 'siteler' },
    // Araç Pazarı: projede kurulu araçlar (aç / kapat) ve eklenebilecekler.
    { name: 'tools', path: 'araclar/:tab?' },
    { name: 'health', path: 'sistem-sagligi' },
    { name: 'profile', path: 'profilim' },
    // Bildirimler: kişinin bütün bildirimleri (zilde son 30); `?durum=okunmamis&tur=brief&sayfa=2`.
    { name: 'notifications', path: 'bildirimler' },
    // Kullanım Kılavuzu: menüyü tanıtan tur ve rehberler; `?rehber=<anahtar>` rehberi açar.
    { name: 'guide', path: 'kilavuz' },
    { name: 'editor', path: ':type/:idOrNew', key: (p) => `editor-${p.type}-${p.id}` },
    { name: 'list', path: ':type', key: (p) => `list-${p.type}` },
];

const withSegments = (r) => ({ ...r, segments: r.path.split('/').filter(Boolean) });
const BY_NAME = Object.fromEntries(ROUTES.map((r) => [r.name, withSegments(r)]));

/**
 * Projenin özel sayfaları (profilin panel.js `PAGES`; project.js verir): içerik türü adreslerinden
 * (`:type`) önce eşlenir. Çatının sayfasıyla aynı ad ya da yol olamaz.
 */
export function usePages(pages) {
    for (const p of pages) {
        if (BY_NAME[p.key]) {
            if (BY_NAME[p.key].project) continue;
            throw new Error(`panel.js PAGES: "${p.key}" çatının sayfası`);
        }
        const route = { name: p.key, path: p.path, project: true };
        ROUTES.splice(ROUTES.findIndex((r) => r.name === 'editor'), 0, route);
        BY_NAME[p.key] = withSegments(route);
    }
}
const PARAM = { ':idOrNew': 'id' };
const paramName = (token) => PARAM[token] || token.slice(1);
function flip(map) {
    return Object.fromEntries(Object.entries(map || {}).map(([k, v]) => [v, k]));
}
const TAB_BY_SLUG = Object.fromEntries(Object.entries(TAB_SLUGS).map(([view, tabs]) => [view, flip(tabs)]));
const isId = (v) => Number.isInteger(v) && v > 0;

/** Adresteki parça → değer; uymuyorsa undefined. */
function readSegment(token, segment, name) {
    if (!token.startsWith(':')) return segment === token ? true : undefined;
    if (token === ':type') return TYPE_BY_SLUG[segment];
    if (token === ':tab') return TAB_BY_SLUG[name]?.[segment];
    if (token === ':idOrNew' && segment === 'yeni') return 0;
    return /^[1-9]\d{0,9}$/.test(segment) ? Number(segment) : undefined;
}

/** Değer → adresteki parça; yoksa ya da geçersizse null. */
function writeSegment(token, params, name) {
    const value = params[paramName(token)];
    if (token === ':type') return TYPE_SLUGS[value] ?? null;
    if (token === ':tab') return TAB_SLUGS[name]?.[value] ?? null;
    if (token === ':idOrNew') return value === 0 || value === 'new' ? 'yeni' : isId(Number(value)) ? String(Number(value)) : null;
    return value !== null && value !== undefined && value !== '' && isId(Number(value)) ? String(Number(value)) : null;
}

function walk(route, segs, i, j, params) {
    if (i === route.segments.length) return j === segs.length ? params : null;
    const part = route.segments[i];
    const optional = part.endsWith('?');
    const token = optional ? part.slice(0, -1) : part;
    if (j < segs.length) {
        const value = readSegment(token, segs[j], route.name);
        if (value !== undefined) {
            const found = walk(route, segs, i + 1, j + 1, token.startsWith(':') ? { ...params, [paramName(token)]: value } : params);
            if (found) return found;
        }
    }
    return optional ? walk(route, segs, i + 1, j, params) : null;
}

function toQuery(query = {}) {
    const search = new URLSearchParams();
    Object.entries(query).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        if (Array.isArray(value)) value.forEach((v) => search.append(`${key}[]`, v));
        else search.append(key, value);
    });
    const text = search.toString();
    return text ? `?${text}` : '';
}

/** Rota adı + parametreler (+ sorgu) → adres. Bilinmeyen rota ya da eksik zorunlu parametre: genel bakış (konsolda hata). */
export function href(name, params = {}, query = {}) {
    const route = BY_NAME[name];
    if (!route) {
        console.error(`[panel] bilinmeyen rota: ${name}`);
        return `${BASE}/`;
    }
    const parts = [];
    for (const part of route.segments) {
        const optional = part.endsWith('?');
        const token = optional ? part.slice(0, -1) : part;
        if (!token.startsWith(':')) {
            parts.push(token);
            continue;
        }
        const value = writeSegment(token, params || {}, name);
        if (value !== null) parts.push(value);
        else if (!optional) {
            console.error(`[panel] ${name} rotası için ${token} eksik`, params);
            return `${BASE}/`;
        }
    }
    return `${BASE}/${parts.join('/')}${toQuery(query || {})}`;
}

/** Panel yolu mu (`/panel`, `/panel/…`)? */
export const isPanelPath = (pathname) => pathname === BASE || pathname.startsWith(`${BASE}/`);

/**
 * Adres → rota: { name, view, type, id, job, tab, query, key, path }. Eşleşmeyen panel adresi
 * `notFound` görünümüdür. `canonical`: aynı rotanın kanonik adresi (sondaki `/`, `index.html`,
 * eski biçimler düzeltilmiş); yükte adres buna çevrilir.
 */
export function match(pathname, search = '') {
    const query = Object.fromEntries(new URLSearchParams(search));
    let rest = String(pathname || '').slice(BASE.length).replace(/\/index\.html$/, '/');
    let segs;
    try {
        segs = legacy(rest.split('/').filter(Boolean).map(decodeURIComponent));
    } catch {
        segs = null;
    }
    for (const route of segs ? ROUTES : []) {
        const params = walk(BY_NAME[route.name], segs, 0, 0, {});
        if (!params) continue;
        const key = route.key ? route.key(params) : route.name;
        return { name: route.name, view: route.name, ...params, query, key, canonical: href(route.name, params) + (search && search !== '?' ? (search.startsWith('?') ? search : `?${search}`) : '') };
    }
    rest = rest || '/';
    return { name: 'notFound', view: 'notFound', query, key: `notFound:${rest}`, path: `${BASE}${rest}`, canonical: null };
}

/** Eski yol (yer imleri, gönderilmiş bağlantılar): `ayarlar/e-posta` → Sistem Ayarları, `ayarlar` → Proje Ayarları. */
function legacy(segs) {
    if (segs[0] !== 'ayarlar') return segs;
    return Object.values(TAB_SLUGS.settings).includes(segs[1]) ? ['sistem-ayarlari', segs[1]] : ['proje-ayarlari'];
}

/** Eski hash adresi (`#/briefs/12`, `#/settings?tab=backup`) → yeni adres. */
export function fromLegacyHash(hash) {
    const [path, search = ''] = String(hash || '').replace(/^#?\/?/, '').split('?');
    const q = Object.fromEntries(new URLSearchParams(search));
    const [head, id] = path.split('/').filter(Boolean);
    const num = (v) => (/^[1-9]\d*$/.test(String(v ?? '')) ? Number(v) : null);
    if (!head) return href('overview');
    if (TYPE_SLUGS[head]) return id ? href('editor', { type: head, id: id === 'new' ? 0 : num(id) ?? 0 }) : href('list', { type: head });
    if (head === 'jarvis') return id === 'domains' ? href('domains', { job: num(q.job) }) : href('jarvis', { id: num(id) });
    if (head === 'briefs') return href('briefs', { id: num(id) });
    if (head === 'history') return href('history', { tab: q.scope === 'all' ? 'all' : null, id: num(q.id) }, { user_id: q.user_id });
    if (head === 'users') return href('users', { id: num(q.id) });
    // Ayarlar ikiye ayrıldı: sistem sekmesi (yedekleme, e-posta…) Sistem Ayarları'na, gerisi Proje Ayarları'na.
    if (head === 'settings') return TAB_SLUGS.settings[q.tab] ? href('settings', { tab: q.tab }) : href('projectSettings');
    if (head === 'seo' || head === 'security') return href(head, { tab: q.tab || null });
    if (BY_NAME[head]) return href(head);
    return href('overview');
}

/** Saklanmış bağlantı (yeni `/panel/…` ya da eski `#/…`) → panel adresi; panel dışıysa olduğu gibi. */
export function toPanelUrl(url) {
    const value = String(url || '');
    if (value.startsWith('#/') || value === '#') return fromLegacyHash(value);
    const legacy = value.match(/^\/panel\/?#(\/.*)?$/);
    return legacy ? fromLegacyHash(`#${legacy[1] || '/'}`) : value;
}

/**
 * Panel içinde gezinme (görünümlerden): kaydedilmemiş değişiklik koruması ve geçmiş kaydı
 * kmPanel'dedir (app.js `km-navigate`).
 */
export function navigate(url, { replace = false } = {}) {
    window.dispatchEvent(new CustomEvent('km-navigate', { detail: { url: toPanelUrl(url), replace } }));
}

/** Görünümü yeniden kurmadan adresi değiştirir (sekme, sohbet, süzgeç): `replaceState` + kmPanel'e haber. */
export function replaceUrl(url) {
    const next = toPanelUrl(url);
    if (next !== window.location.pathname + window.location.search) history.replaceState(history.state, '', next);
    window.dispatchEvent(new CustomEvent('km-url-replaced', { detail: next }));
}

/** km-manifest.json `panel` bölümü (kodmob-core PanelUrl okur). */
export function panelManifest() {
    return { base: BASE, types: TYPE_SLUGS, tabs: TAB_SLUGS, routes: Object.fromEntries(ROUTES.map((r) => [r.name, r.path])) };
}
