/**
 * Panel sayfaları, menü ve içerik türleri. `type` API'deki addır. Sayfanın adresi rota
 * tablosundan gelir (routes.js; `href`), elle yazılmaz. `key` sayfanın kalıcı anahtarıdır:
 * rolün yan menüsü adres değil bu anahtarı saklar. `orderable`: listede sürükle-bırak sıralama.
 *
 * Her sayfanın görünmesi için gereken yetki `can`'dedir (kodmob-api Core\Security\Capability);
 * yetkisi olmayan sayfa menüde görünmez, adresle açılırsa "Yetkiniz yok" ekranı çıkar.
 * Rolün yan menüsü (Roller ve İzinler > Yan Menü) bu katalogdan kurulur; menüde olmak
 * sayfayı açmaz, yetki yine geçerlidir.
 */
import { href, match } from './routes.js';
import { TYPES } from './types.js';
import { PROJECT_PAGES } from './project.js';
import { FRAMEWORK_TYPES } from './extension.js';

/** İçerik türleri types.js'te (tek kaynak); buradan da alınabilsin. */
export { TYPES };

/** Kayıt adından (project, post) içerik türü: [API adı, tanım]; yoksa null. */
export function typeByRecord(record) {
    return Object.entries(TYPES).find(([, t]) => t.record === record) || null;
}

const page = (key, view, label, icon, group, can, extra = {}) => ({ key, view, href: href(view), label, icon, group, can, ...extra });

/**
 * Bütün sayfalar (menü kataloğu). `group`: menüdeki grubu (docs/done-panel-yapisi-raporu.md): Genel bakış,
 * Temel (her projede olan çatı yapıları), Proje modülleri (projenin türleri, sayfaları ve araçları),
 * Proje Ayarları, en altta Sistem. Seyrek olanlar (`menu: false`) Tüm İşlemler'de, panel aramasında ve
 * Hızlı İşlemler'de. Rolün yan menüsüne (Roller ve İzinler › Yan Menü) her ikisi de eklenebilir; `hidden`
 * sayfa menüye hiç girmez (Profilim, Kılavuz, alt görünümler).
 */
export const PAGES = [
    page('overview', 'overview', 'Genel bakış', 'home', 'main', 'km_view_overview'),
    // Tüm İşlemler: bütün işler gruplu kartlarda + kişiye özel Hızlı İşlemler (operations.js); üst bardan açılır.
    page('other', 'other', 'Tüm İşlemler', 'list', 'main', null, { menu: false }),
    // İçerik türleri: çatınınki (blog) Temel'de, projeninkiler Proje modüllerinde.
    ...Object.entries(TYPES).map(([type, t]) => ({ key: type, view: 'list', type, href: href('list', { type }), label: t.label, icon: t.icon, group: t.group || (FRAMEWORK_TYPES[type] ? 'base' : 'project'), can: t.can, ...(t.menu === false ? { menu: false } : {}) })),
    page('briefs', 'briefs', 'Talepler', 'mail', 'base', 'km_view_briefs', { badge: 'briefs' }),
    // Çoklu dil: Metinler sekmesi kendi görünümüdür (sayfa metinleri yetkisi); menüde tek öğe.
    page('languages', 'languages', 'Sayfalar ve Diller', 'globe', 'base', 'km_manage_languages|km_manage_texts'),
    page('texts', 'texts', 'Sayfa metinleri', 'book', 'base', 'km_manage_texts', { hidden: true, parent: 'languages' }),
    // Sayfa düzenleyici: sayfaların bölümleri (bloklar), sürükle-bırak, önizleme, Jarvis.
    page('builder', 'builder', 'Sayfa düzenleyici', 'pen-tool', 'base', 'km_edit_layout'),
    page('seo', 'seo', 'SEO', 'search', 'base', 'km_manage_seo'),
    page('legal', 'legal', 'Yasal belgeler', 'file-check', 'base', 'km_manage_legal'),
    // Araçlar (isteğe bağlı modüller; kapalıysa yetkisi yok, menüde görünmez). Jarvis üst bardaki düğmeden açılır.
    page('estimate', 'estimate', 'Teklif hesaplayıcı', 'clipboard', 'project', 'km_manage_estimate'),
    page('domains', 'domains', 'Domain arama', 'link', 'project', 'km_use_domains', { badge: 'jobs' }),
    // Mağaza (commerce): siparişler ve kuponlar; ürünler yukarıdaki içerik türlerinde.
    page('orders', 'orders', 'Siparişler', 'cart', 'project', 'km_view_orders'),
    page('coupons', 'coupons', 'Kuponlar', 'tag', 'project', 'km_manage_coupons', { menu: false }),
    page('bookings', 'bookings', 'Rezervasyonlar', 'calendar', 'project', 'km_view_bookings'),
    page('newsletter', 'newsletter', 'Bülten', 'send', 'project', 'km_manage_newsletter'),
    page('jarvis', 'jarvis', 'Asistan', 'sparkles', 'project', 'km_use_jarvis', { menu: false }),
    // Proje modüllerinin sonunda: Araç Pazarı'nın Keşfet sekmesi.
    page('tools-add', 'tools', 'Araç ekle', 'plus', 'project', 'km_manage_settings', { href: href('tools', { tab: 'discover' }), alias: true }),
    // Projenin özel sayfaları (profilin panel.js PAGES): kendi gruplarında (varsayılan Proje modülleri).
    ...PROJECT_PAGES.map((p) => page(p.key, p.key, p.label, p.icon, p.group, p.can, { project: true, ...(p.menu === false ? { menu: false } : {}), ...(p.hidden ? { hidden: true } : {}) })),
    page('project-settings', 'projectSettings', 'Proje Ayarları', 'sliders', 'project-settings', 'km_manage_settings|km_manage_ai'),
    // Sistem: kurulumun tamamı (menünün altında).
    page('system-settings', 'settings', 'Sistem Ayarları', 'cog', 'system', 'km_manage_settings|km_manage_ai|km_manage_backups|km_manage_sms'),
    page('users', 'users', 'Kullanıcılar ve Roller', 'user', 'system', 'km_view_users'),
    page('health', 'health', 'Sistem Sağlığı', 'activity', 'system', 'km_view_health'),
    page('tools', 'tools', 'Araç Pazarı', 'package', 'system', 'km_manage_settings'),
    // İşlemlerim hesap menüsünde; herkesin işlemleri Tüm İşlemler'den.
    page('history', 'history', 'İşlem Geçmişi', 'history', 'system', null, { menu: false }),
    page('roles', 'roles', 'Roller ve İzinler', 'shield-check', 'system', 'km_manage_roles', { menu: false }),
    page('security', 'security', 'Güvenlik', 'lock', 'system', 'km_manage_security', { menu: false }),
    // Projeler (çok site): üstteki proje seçiciden; yetki yalnızca ana projede vardır.
    page('sites', 'sites', 'Projeler', 'layers', 'system', 'km_manage_sites', { menu: false }),
    page('profile', 'profile', 'Profilim', 'user', 'account', null, { hidden: true }),
    // Kullanım Kılavuzu: menüde değil; Tüm İşlemler ve kullanıcı menüsünden açılır, herkes görür.
    page('guide', 'guide', 'Kullanım Kılavuzu', 'book', 'account', null, { hidden: true }),
];

/**
 * Varsayılan menü grupları. Başlıksız grup (`label: ''`) açılıp kapanmaz; `bottom` grup menünün altında
 * durur (Sistem). Rolün kaydedilmiş menüsü kendi gruplarını saklar (değişmez).
 */
export const GROUPS = [
    { key: 'main', label: '' },
    { key: 'base', label: 'Temel' },
    { key: 'project', label: 'Proje modülleri' },
    { key: 'project-settings', label: '' },
    { key: 'system', label: 'Sistem', bottom: true },
];

const BY_KEY = new Map(PAGES.map((p) => [p.key, p]));

/** Menü öğesi → sayfa. Eski rol menüleri adres saklıyordu (`#/projects`); onlar da çözülür. */
export function pageByKey(item) {
    const value = String(item || '');
    // Sayfa metinleri Çoklu Dil'in sekmesi oldu; eski rol menülerindeki öğe oraya çıkar.
    if (value === 'texts' || value === '#/texts') return BY_KEY.get('languages');
    // Ayarlar ikiye ayrıldı; eski rol menülerindeki "Ayarlar" Proje Ayarları'na çıkar.
    if (value === 'settings' || value === '#/settings') return BY_KEY.get('project-settings');
    if (!value.startsWith('#')) return BY_KEY.get(value) || null;
    const path = value.replace(/^#\/?/, '').replace(/\/$/, '');
    return BY_KEY.get(path === '' ? 'overview' : path === 'jarvis/domains' ? 'domains' : path) || null;
}

/** Görünümün sayfası (kısayol menü öğeleri, ör. "Araç ekle", sayfanın kendisi sayılmaz). */
export const pageByView = (view, type = null) => PAGES.find((p) => p.view === view && !p.alias && (!type || p.type === type)) || null;

/** Bir panel adresinin sayfası: /panel/projeler/12 → Projeler, /panel/seo/yonlendirmeler → SEO. */
export function pageForUrl(url) {
    const [pathname, search = ''] = String(url || '').split('?');
    const route = match(pathname, search);
    if (route.view === 'notFound') return null;
    return route.view === 'editor' || route.view === 'list' ? pageByView('list', route.type) : pageByView(route.view);
}

/** Varsayılan menü (rolün kendi menüsü yoksa): { key, label, items: [sayfa anahtarı] }. */
export function defaultMenu() {
    return GROUPS.map((g) => ({ ...g, items: PAGES.filter((p) => p.group === g.key && !p.hidden && p.menu !== false).map((p) => p.key) })).filter((g) => g.items.length);
}

/**
 * Kullanıcının menüsü: rolün menüsü (yoksa varsayılan), yetkisi olmayan sayfalar ve boş
 * gruplar çıkarılmış. `can`: (yetki) => bool.
 */
export function buildMenu(roleMenu, can) {
    return (roleMenu?.length ? roleMenu : defaultMenu())
        .map((g) => ({ key: g.key, label: g.label, bottom: !!GROUPS.find((d) => d.key === g.key)?.bottom, items: (g.items || []).map(pageByKey).filter((p) => p && can(p.can)) }))
        .filter((g) => g.items.length);
}

/** Mobil alt menü adayları (ilk dört yetkili sayfa; sonuncusu çekmeceyi açar). */
export const BOTTOM_CANDIDATES = [
    { ...BY_KEY.get('overview'), label: 'Özet' },
    BY_KEY.get('briefs'),
    BY_KEY.get('posts'),
    { ...BY_KEY.get('jarvis'), label: 'Jarvis' },
    { ...BY_KEY.get('domains'), label: 'Domain' },
    { ...BY_KEY.get('history'), label: 'Geçmiş' },
    BY_KEY.get('profile'),
];
/** Oturum yüklenmeden (giriş ekranı) kullanılan yedek; asıl liste sunucudaki dil kaydıdır. */
const FALLBACK_LANGUAGES = [
    { code: 'tr', name: 'Türkçe', default: true },
    { code: 'en', name: 'İngilizce' },
    { code: 'de', name: 'Almanca' },
];

/** Dil kaydı (kodmob-api `languages`; /me `languages`): içerik dilleri, sıralı, pasifler dahil. */
export function languages() {
    const list = window.Alpine?.store('session')?.me?.languages;
    return list?.length ? list : FALLBACK_LANGUAGES;
}

/** Kod → paneldeki adı (Türkçe, İngilizce…). */
export const localeLabels = () => Object.fromEntries(languages().map((l) => [l.code, l.name]));
export const localeCodes = () => languages().map((l) => l.code);
/** Sitede açık diller (eksik çeviri bunlara göre sayılır; tek dilli modda yalnızca varsayılan dil). */
export const activeLocaleCodes = () => languages().filter((l) => (l.live ?? l.active) !== false).map((l) => l.code);
export const defaultLocale = () => (languages().find((l) => l.default) || languages()[0]).code;

export const STATUS = {
    publish: { label: 'Yayında', badge: 'badge-success' },
    future: { label: 'Zamanlandı', badge: 'badge-primary' },
    draft: { label: 'Taslak', badge: 'badge-neutral' },
};

/** "3 dk önce", "dün", "12 Eyl" */
export function relative(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const diff = (Date.now() - date.getTime()) / 1000;
    if (diff < 60) return 'az önce';
    if (diff < 3600) return `${Math.floor(diff / 60)} dk önce`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} sa önce`;
    if (diff < 172800) return 'dün';
    return new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short', year: date.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' }).format(date);
}
