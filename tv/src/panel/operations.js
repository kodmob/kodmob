/**
 * İşlem kataloğu: Tüm İşlemler sayfasının (other.js) kartları ve Hızlı İşlemler'e eklenebilen
 * her şey. "Diğer İşlemler" düzeni: her grup tek kart, işler kartta satır satır.
 *
 * Kaynak sayfa kataloğudur (meta.js PAGES, types.js TYPES): kartta sayfa anahtarı yazılır, ad,
 * ikon, adres ve yetki oradan gelir. İçerik kartı bütün içerik türlerini kendisi alır;
 * `kodmob:module` ile eklenen tür burada da kendiliğinden görünür (listesi ve "Yeni …").
 * Sayfanın içindeki bölümler (sekme) `shortcut()` ile; adresleri rota tablosundan (`href`).
 *
 * Anahtarlar kalıcıdır: kişilerin Hızlı İşlemler seçimi bu anahtarları saklar (kodmob-api
 * /me/quick-actions). Değiştirilen anahtar seçimlerden sessizce düşer.
 *
 * Yeni sayfa: meta.js PAGES'e eklenir, sonra buradaki uygun karta anahtarı yazılır. Kartta
 * olmayan sayfa testte yakalanır (tests/panel-operations.test.mjs).
 */
import { href } from './routes.js';
import { PAGES, TYPES } from './meta.js';
import { PROJECT_PAGES } from './project.js';

export const QUICK_MAX = 12;

/** Hiç düzenlemeyen kişinin Hızlı İşlemler'i (yetkisi olmayanlar görünmez). */
export const DEFAULT_QUICK = ['posts-new', 'briefs', 'texts', 'seo-redirects', 'users', 'history'];

const BY_KEY = new Map(PAGES.map((p) => [p.key, p]));

/** Sayfa satırı: ad, ikon, adres, yetki sayfa kataloğundan. */
const page = (key, hint, extra = {}) => {
    const p = BY_KEY.get(key);
    if (!p) throw new Error(`[panel] işlem kataloğu: bilinmeyen sayfa ${key}`);
    return { key, label: p.label, icon: p.icon, href: p.href, can: p.can, hint, page: key, ...extra };
};

/** Sayfanın içindeki bölüm (sekme, yeni kayıt): `page` hangi sayfanın parçası olduğu. */
const shortcut = (key, parent, label, route, hint, can = BY_KEY.get(parent)?.can, icon = BY_KEY.get(parent)?.icon) => ({
    key, label, icon, href: href(route[0], route[1] || {}, route[2] || {}), can, hint, page: parent, sub: true,
});

/** İçerik türleri: listesi ve yeni kayıt. */
const contentLinks = () => Object.entries(TYPES).flatMap(([type, t]) => [
    page(type, `${t.label}: liste, arama, sıralama, çeviri durumu, silinenler.`),
    shortcut(`${type}-new`, type, t.newLabel || `Yeni ${t.singular.toLocaleLowerCase('tr')}`, ['editor', { type, id: 0 }], `Boş formla yeni ${t.singular.toLocaleLowerCase('tr')}.`, t.can, 'plus'),
]);

/**
 * Kartlar. `tone`: ikon zemini (tone-primary, tone-success…). Bağlantı yetkiye göre görünür
 * (`can` 'a|b' biçiminde ise herhangi biri yeter); bağlantısı kalmayan kart gizlenir.
 */
export const CARDS = [
    {
        key: 'content', title: 'İçerik', icon: 'layers', tone: 'primary',
        text: 'Sitedeki kayıtlar: ekleme, düzenleme, sıralama, çeviriler ve yayın durumu.',
        links: contentLinks,
    },
    {
        // Projenin özel sayfaları (profilin panel.js PAGES); yoksa kart görünmez.
        key: 'project', title: 'Siteye özel', icon: 'briefcase', tone: 'info',
        text: 'Bu projeye özel ekranlar.',
        links: () => PROJECT_PAGES.filter((p) => !p.tool).map((p) => page(p.key, p.hint || p.guide || p.label)),
    },
    {
        key: 'crm', title: 'Müşteriler', icon: 'inbox', tone: 'success',
        text: 'Siteden gelen talepler, yanıtlar ve talep sahipleriyle yazışma.',
        links: () => [page('briefs', 'Yeni talepler, okundu ve arşiv; talep sahibine yanıt.')],
    },
    {
        key: 'site', title: 'Site ve Diller', icon: 'globe', tone: 'info',
        text: 'Sayfa düzenleyici, sayfa metinleri, sitenin dilleri ve adresleri.',
        links: () => [
            page('builder', 'Sayfaların bölümleri: sürükle-bırak, canlı önizleme, Jarvis ile metin.'),
            page('texts', 'Sayfalardaki başlık ve metinler, dil dil.'),
            page('languages', 'Sitenin dilleri, çeviri durumu ve dil ayarları.'),
            page('legal', 'Gizlilik, KVKK, çerez, kullanım koşulları; açık modüllerin sözleşme ve aydınlatma metinleri.'),
            shortcut('languages-urls', 'languages', 'Dil adresleri', ['languages', { tab: 'urls' }], 'Her dilin adres öneki ve eski adreslerden yönlendirmeler.', 'km_manage_languages'),
            shortcut('languages-health', 'languages', 'Çeviri sağlığı', ['languages', { tab: 'health' }], 'Eksik çeviriler, kırık bağlantılar ve dil sorunları.', 'km_manage_languages'),
        ],
    },
    {
        key: 'seo', title: 'SEO', icon: 'search', tone: 'warning',
        text: 'Arama motorları: başlık ve açıklamalar, yönlendirmeler, 404 kayıtları, robots.',
        links: () => [
            page('seo', 'SEO durumu ve öneriler.'),
            shortcut('seo-content', 'seo', 'Kayıtların SEO\'su', ['seo', { tab: 'content' }], 'Yazıların ve kayıtların başlık, açıklama ve puanları.'),
            shortcut('seo-ai', 'seo', 'Yapay zekâ görünürlüğü', ['seo', { tab: 'ai' }], 'ChatGPT, Claude, Perplexity ziyaretleri; içeriğin yapay zekâ cevaplarına hazırlığı.'),
            shortcut('seo-pages', 'seo', 'Sayfaların SEO\'su', ['seo', { tab: 'pages' }], 'Sabit sayfaların başlık, açıklama ve paylaşım görseli.'),
            shortcut('seo-redirects', 'seo', 'Yönlendirmeler', ['seo', { tab: 'redirects' }], '301 / 302 / 410 yönlendirmeleri; içe ve dışa aktarma.'),
            shortcut('seo-404', 'seo', '404 kayıtları', ['seo', { tab: '404' }], 'Bulunamayan adresler; tek tıkla yönlendirme.'),
            shortcut('seo-robots', 'seo', 'robots.txt', ['seo', { tab: 'robots' }], 'Arama motoru kuralları ve site haritası.'),
        ],
    },
    {
        key: 'settings', title: 'Ayarlar', icon: 'cog', tone: 'neutral',
        text: 'Proje Ayarları (kimlik, iletişim, yasal bilgiler) ve Sistem Ayarları (e-posta, SMS, yapay zekâ, arka plan, yedek).',
        links: () => [
            page('project-settings', 'Kimlik, iletişim, sosyal medya, yasal bilgiler, talepler, görseller, araçların ayarları.'),
            page('system-settings', 'Kurulumun tamamı: e-posta, SMS, yapay zekâ, arka plan işleri, yedekleme, sistem sağlığı raporu.'),
            shortcut('settings-mail', 'system-settings', 'E-posta ayarları', ['settings', { tab: 'mail' }], 'SMTP, gönderen, deneme e-postası ve son gönderimler.', 'km_manage_settings', 'mail'),
            shortcut('settings-sms', 'system-settings', 'SMS ayarları', ['settings', { tab: 'sms' }], 'NetGSM hesabı, onaylı başlık, deneme SMS\'i ve son gönderimler.', 'km_manage_sms', 'message'),
            shortcut('settings-ai', 'system-settings', 'Yapay zekâ', ['settings', { tab: 'ai' }], 'Claude, OpenAI ya da Gemini: etkin sağlayıcı, anahtarlar ve modeller.', 'km_manage_ai', 'sparkles'),
            shortcut('settings-jobs', 'system-settings', 'Arka plan işleri', ['settings', { tab: 'jobs' }], 'Cron durumu, kuyruk ve başarısız işler.', 'km_manage_settings', 'activity'),
            shortcut('settings-backup', 'system-settings', 'Yedekleme', ['settings', { tab: 'backup' }], 'Yedek al, yedekten geri yükle, otomatik ve uzak yedek.', 'km_manage_backups', 'download'),
        ],
    },
    {
        key: 'jarvis', title: 'Araçlar', icon: 'sparkles', tone: 'primary',
        text: 'Projede açık araçlar: yapay zekâ asistanı, domain arama, teklif hesaplayıcı.',
        links: () => [
            page('jarvis', 'Siteyle ilgili sorular, metin yazdırma ve çeviri.'),
            page('domains', 'Alan adı uygunluğu ve toplu tarama.'),
            page('estimate', 'Teklif hesaplayıcının kalemleri ve fiyatları.'),
            page('newsletter', 'E-posta bülteni: aboneler, bülten yazma, deneme ve gönderim.'),
            // Araç paketlerinin sayfaları (kodmob-api/extensions; vite/extensions.js).
            ...PROJECT_PAGES.filter((p) => p.tool).map((p) => page(p.key, p.hint || p.guide || p.label)),
            page('tools-add', 'Projeye yeni araç: çatının araçları ve nasıl eklendiği.'),
        ],
    },
    {
        key: 'commerce', title: 'Mağaza', icon: 'cart', tone: 'success',
        text: 'Siparişler, kuponlar ve mağaza ayarları; ürünler İçerik kartında.',
        links: () => [
            page('orders', 'Gelen siparişler: ödeme bekleyen, hazırlanan, kargodaki; durum değiştirme.'),
            page('coupons', 'İndirim kuponları: yüzde ya da tutar, alt sınır, kullanım ve tarih sınırı.'),
            page('bookings', 'Tur ve etkinlik rezervasyonları: tarih, kişi sayıları, müşteri, ödeme durumu.'),
            shortcut('shop-settings', 'project-settings', 'Mağaza ayarları', ['projectSettings'], 'Para birimi, KDV, kargo, havale ve kapıda ödeme.', 'km_manage_settings', 'cart'),
        ],
    },
    {
        key: 'admin', title: 'Sistem', icon: 'shield-check', tone: 'danger',
        text: 'Kullanıcılar, roller ve izinler, işlem geçmişi, güvenlik, projeler, sistem sağlığı.',
        links: () => [
            page('users', 'Kullanıcı ekleme, rol verme, pasife alma; SMS ve e-posta gönderme.'),
            page('roles', 'Hangi rol neyi görür, ne yapar; rolün yan menüsü.'),
            page('history', 'Kendi işlemleriniz; yetkiniz varsa herkesinkiler.'),
            shortcut('history-all', 'history', 'Bütün işlemler', ['history', { tab: 'all' }], 'Herkesin işlemleri, kişiye ve türe göre süzme.', 'km_view_all_history'),
            page('security', 'Güvenlik denetimi ve durum.'),
            page('sites', 'Projeler (aynı kurulumda): yeni proje, alan adları, modüller, tabloları güncelleme.'),
            page('health', 'Sunucu, güvenlik, hız, veritabanı, depolama, arka plan ve yedek özeti; hata kodları; teknik servise bildirme.'),
            page('tools', 'Projede kurulu araçları açma ve kapatma; eklenebilecek araçlar.'),
            shortcut('security-sessions', 'security', 'Açık oturumlar', ['security', { tab: 'sessions' }], 'Herkesin açık oturumları; oturum kapatma.'),
            shortcut('security-events', 'security', 'Güvenlik kayıtları', ['security', { tab: 'events' }], 'Başarısız girişler, engellenen istekler.'),
            shortcut('security-blocks', 'security', 'IP engelleri', ['security', { tab: 'blocks' }], 'Elle ve otomatik engellenen adresler.'),
            shortcut('security-lockouts', 'security', 'Kilitli girişler', ['security', { tab: 'lockouts' }], 'Çok deneme yüzünden kilitlenen hesaplar; kilidi açma.'),
        ],
    },
    {
        key: 'account', title: 'Hesabım', icon: 'user', tone: 'neutral',
        text: 'Genel bakış, profiliniz, şifre, iki adımlı doğrulama, oturumlarınız ve kullanım kılavuzu.',
        links: () => [
            page('overview', 'İçerik sayıları, son işlemler, dikkat gerekenler.'),
            page('profile', 'Bilgileriniz, şifre, iki adımlı doğrulama, oturumlarınız.'),
            page('guide', 'Menüyü tanıtan tur ve panel rehberleri.'),
        ],
    },
];

/** Kartlar ve bağlantıları (tanımlar bir kez çözülür). */
export const RESOLVED = CARDS.map((c) => ({ key: c.key, title: c.title, icon: c.icon, tone: c.tone, text: c.text, links: c.links() }));

/** Hızlı İşlemler'e eklenebilen her şey: anahtar → bağlantı (kart adıyla). */
export const ITEMS = new Map(RESOLVED.flatMap((c) => c.links.map((l) => [l.key, { ...l, card: c.title, tone: c.tone }])));

/** Kaydedilmiş eski anahtar → bugünkü (Ayarlar ikiye ayrıldı: Proje Ayarları). */
const LEGACY = { settings: 'project-settings' };

/** Hızlı İşlem anahtarının bağlantısı (eski anahtar da çözülür). */
export const itemFor = (key) => ITEMS.get(LEGACY[key] ?? key);

/** `can` 'a|b' biçiminde ise herhangi biri yeter; boş yetki herkese açıktır. */
export const allowed = (cap, can) => !cap || String(cap).split('|').some((c) => can(c));

/** Yetkiye göre süzülmüş kartlar; bağlantısı kalmayan kart çıkar. */
export function cardsFor(can) {
    return RESOLVED.map((c) => ({ ...c, links: c.links.filter((l) => allowed(l.can, can)) })).filter((c) => c.links.length);
}
