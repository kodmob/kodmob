/**
 * Panel aramasının yerel dizini (sunucuya gitmeden aranır): sayfalar ve sekmeler, ayarlar.
 *
 * - Sayfalar ve sekmeler: işlem kataloğundan (operations.js); yeni sayfa orada tanımlanınca burada da.
 * - Ayar kaydındaki ayarlar (kodmob-api SettingsRegistry; GET /settings): modülün eklediği ayar
 *   kendiliğinden aranır. Hangi sayfada durduğu `settingPlace()`: grup ya da anahtar öneki;
 *   eşleşmeyen grup kapsamına göre Proje Ayarları ya da Sistem Ayarları › Genel'dedir (panel/settings.js).
 * - Ayar kaydında olmayan panel formları (SMTP, SMS, yedek, profil, Claude…): FIELDS.
 *
 * Her ayarın `anchor`'ı şablondaki `data-setting` değeridir; sonuç açılınca alan bulunup
 * ortalanır ve vurgulanır (ui/spotlight.js). Alan kapalıysa (ör. SMTP seçili değil) bir üst
 * bölüm (`mail.host` → `mail`) vurgulanır. tests/panel-search.test.mjs her FIELDS anchor'ının
 * şablonlarda bulunduğunu denetler.
 */
import { href } from './routes.js';
import { ITEMS } from './operations.js';

/** Sayfa > sekme adları (yol gösterimi). Sekme anahtarları routes.js TAB_SLUGS ile aynı. */
const PAGES = {
    settings: ['Sistem Ayarları', { general: 'Genel', mail: 'E-posta', sms: 'SMS', ai: 'Yapay zekâ', jobs: 'Arka plan', backup: 'Yedekleme' }],
    projectSettings: ['Proje Ayarları', {}],
    seo: ['SEO', { overview: 'Genel bakış', content: 'İçerik', ai: 'Yapay zekâ', pages: 'Sayfalar', redirects: 'Yönlendirmeler', 404: '404', robots: 'Robots ve llms', settings: 'Ayarlar' }],
    security: ['Güvenlik', { overview: 'Genel bakış', sessions: 'Oturumlar', events: 'Güvenlik kayıtları', blocks: 'IP engelleri', lockouts: 'Kilitli girişler', settings: 'Ayarlar' }],
    languages: ['Sayfalar ve Diller', { overview: 'Genel bakış', list: 'Diller', urls: 'Adresler', visitor: 'Ziyaretçi', health: 'Sağlık' }],
    profile: ['Profilim', {}],
    jarvis: ['Jarvis', {}],
    users: ['Kullanıcılar ve Roller', {}],
};

/** Varsayılan sekme adrese yazılmaz (TAB_SLUGS'ta yok); yolda adı görünür. */
const DEFAULT_TAB = { settings: 'general', seo: 'overview', security: 'overview', languages: 'overview' };

export function place(view, tab = null) {
    const [label, tabs] = PAGES[view] || [view, {}];
    const shown = tab || DEFAULT_TAB[view];
    return {
        href: href(view, tab && tab !== DEFAULT_TAB[view] ? { tab } : {}),
        path: [label, tabs[shown]].filter(Boolean).join(' › '),
    };
}

/** Ayar kaydındaki gruplardan kendi sayfasında çizilenler; diğerleri kapsamına göre Proje Ayarları ya da Sistem Ayarları › Genel. */
const GROUP_PLACES = { Güvenlik: ['security', 'settings'], SEO: ['seo', 'settings'], 'Çoklu dil': ['languages', null] };
const KEY_PLACES = [
    [/^seo\.module\./, ['seo', null]],
    [/^seo\.(ai_bots|llms_intro|sitemap_images)$/, ['seo', 'robots']],
];

export function settingPlace(definition) {
    const byKey = KEY_PLACES.find(([re]) => re.test(definition.key));
    const own = (definition.scope || 'project') === 'system' ? ['settings', null] : ['projectSettings', null];
    const [view, tab] = byKey ? byKey[1] : GROUP_PLACES[definition.group] || own;
    const p = place(view, tab);
    return view === 'settings' || view === 'projectSettings' ? { ...p, path: `${p.path} › ${definition.group}` } : p;
}

const field = (anchor, title, [view, tab], can, words = '', hint = '') => ({ anchor, title, view, tab, can, words, hint });

/** Ayar kaydında olmayan panel formları. `words`: eş anlamlılar ve teknik adlar (aramada sayılır). */
export const FIELDS = [
    // Ayarlar > E-posta
    field('mail', 'E-posta gönderimi', ['settings', 'mail'], 'km_manage_settings', 'mail smtp eposta email gönderim', 'Talep bildirimleri ve yanıtlar bu yolla gider.'),
    field('mail.mailer', 'E-posta gönderim yöntemi', ['settings', 'mail'], 'km_manage_settings', 'smtp sendmail env log mailer'),
    field('mail.host', 'SMTP sunucusu', ['settings', 'mail'], 'km_manage_settings', 'smtp host sunucu hostinger e-posta'),
    field('mail.encryption', 'SMTP şifreleme', ['settings', 'mail'], 'km_manage_settings', 'ssl tls starttls'),
    field('mail.port', 'SMTP portu', ['settings', 'mail'], 'km_manage_settings', 'port 465 587'),
    field('mail.username', 'SMTP kullanıcı adı', ['settings', 'mail'], 'km_manage_settings', 'smtp kullanıcı'),
    field('mail.password', 'SMTP şifresi', ['settings', 'mail'], 'km_manage_settings', 'smtp parola password'),
    field('mail.from_address', 'Gönderen e-posta adresi', ['settings', 'mail'], 'km_manage_settings', 'from gönderen adres'),
    field('mail.from_name', 'Gönderen adı', ['settings', 'mail'], 'km_manage_settings', 'from gönderen isim'),
    field('mail.test', 'Deneme e-postası gönder', ['settings', 'mail'], 'km_manage_settings', 'test deneme e-posta'),
    field('mail.log', 'Son gönderilen e-postalar', ['settings', 'mail'], 'km_manage_settings', 'gönderim kaydı log e-posta geçmişi'),
    // Ayarlar > SMS
    field('sms', 'SMS gönderimi', ['settings', 'sms'], 'km_manage_sms', 'sms netgsm mesaj'),
    field('sms.provider', 'SMS sağlayıcısı', ['settings', 'sms'], 'km_manage_sms', 'netgsm sağlayıcı provider'),
    field('sms.username', 'NetGSM kullanıcı adı', ['settings', 'sms'], 'km_manage_sms', 'netgsm abone numara usercode'),
    field('sms.password', 'NetGSM API şifresi', ['settings', 'sms'], 'km_manage_sms', 'netgsm şifre parola'),
    field('sms.sender', 'SMS gönderici başlığı', ['settings', 'sms'], 'km_manage_sms', 'msgheader başlık netgsm onaylı'),
    field('sms.iys_filter', 'SMS ileti türü (İYS)', ['settings', 'sms'], 'km_manage_sms', 'iys ticari bilgilendirme'),
    field('sms.app_name', 'NetGSM uygulama adı', ['settings', 'sms'], 'km_manage_sms', 'appname'),
    field('sms.test', 'Deneme SMS\'i gönder', ['settings', 'sms'], 'km_manage_sms', 'test deneme sms'),
    field('sms.log', 'Son gönderilen SMS\'ler', ['settings', 'sms'], 'km_manage_sms', 'sms kaydı log'),
    // Ayarlar > Arka plan
    field('jobs.cron', 'Cron ve kuyruk durumu', ['settings', 'jobs'], 'km_manage_settings', 'cron zamanlanmış görev kuyruk queue schedule'),
    field('jobs.failed', 'Başarısız arka plan işleri', ['settings', 'jobs'], 'km_manage_settings', 'failed job hata yeniden dene'),
    // Ayarlar > Yedekleme
    field('backup.export', 'Yedek al (indir)', ['settings', 'backup'], 'km_manage_backups', 'yedek backup indir dışa aktar json'),
    field('backup.restore', 'Yedekten geri yükle', ['settings', 'backup'], 'km_manage_backups', 'geri yükle restore içe aktar'),
    field('backup.auto', 'Otomatik yedek', ['settings', 'backup'], 'km_manage_backups', 'zamanlanmış yedek günlük haftalık'),
    field('backup.auto.frequency', 'Otomatik yedek sıklığı', ['settings', 'backup'], 'km_manage_backups', 'günlük haftalık sıklık'),
    field('backup.auto.keep', 'Saklanan yedek sayısı', ['settings', 'backup'], 'km_manage_backups', 'son n yedek saklama'),
    field('backup.remote', 'Uzak yedek deposu (S3)', ['settings', 'backup'], 'km_manage_backups', 's3 bucket kova r2 wasabi uzak depo endpoint erişim anahtarı'),
    // Profilim
    field('profile.info', 'Adım, e-postam, telefonum', ['profile', null], null, 'profil bilgilerim ad soyad telefon e-posta'),
    field('profile.password', 'Şifremi değiştir', ['profile', null], null, 'şifre parola password değiştir'),
    field('profile.two_factor', 'İki adımlı doğrulama', ['profile', null], null, '2fa totp doğrulama kodu authenticator google yedek kod'),
    field('profile.sessions', 'Oturumlarım', ['profile', null], null, 'oturum cihaz çıkış'),
    // Ayarlar > Yapay zekâ
    field('ai', 'Yapay zekâ sağlayıcısı (etkin)', ['settings', 'ai'], 'km_manage_ai', 'ai yapay zeka llm claude openai gpt gemini google sağlayıcı', 'Jarvis sohbeti ve içerik çevirisi bu sağlayıcıyla çalışır.'),
    field('ai.anthropic', 'Claude (Anthropic) API anahtarı ve modeli', ['settings', 'ai'], 'km_manage_ai', 'claude anthropic opus sonnet haiku fable api key anahtar model'),
    field('ai.openai', 'OpenAI API anahtarı ve modeli', ['settings', 'ai'], 'km_manage_ai', 'openai gpt chatgpt api key anahtar model'),
    field('ai.google', 'Google Gemini API anahtarı ve modeli', ['settings', 'ai'], 'km_manage_ai', 'google gemini api key anahtar model'),
    field('ai.behavior', 'Yapay zekâ düşünme düzeyi ve web araması', ['settings', 'ai'], 'km_manage_ai', 'effort düşünme web arama jarvis adım sınırı'),
    // Güvenlik
    field('security.key', 'Oturum imza anahtarını yenile', ['security', null], 'km_manage_security', 'jwt imza anahtarı herkesin oturumunu kapat'),
    // Çoklu Dil
    field('i18n.enabled', 'Çoklu dil (aç / kapat)', ['languages', null], 'km_manage_languages', 'tek dil çok dil'),
];

/**
 * Yerel dizin. `can`: yetki denetimi; `definitions`: GET /settings öğeleri (yalnızca kullanıcının
 * değiştirebildikleri gelir).
 *
 * @returns {{ pages: object[], settings: object[] }} her öğe { key, title, path, text, href, icon, anchor, can }
 */
export function buildIndex(can, definitions = []) {
    const allowed = (cap) => !cap || String(cap).split('|').some((c) => can(c));
    const pages = [...ITEMS.values()].filter((i) => allowed(i.can)).map((i) => ({
        key: `op:${i.key}`, title: i.label, path: i.sub ? ITEMS.get(i.page)?.label || i.card : i.card, text: `${i.hint || ''} ${i.card}`, hint: i.hint || '', href: i.href, icon: i.icon,
    }));
    const registered = new Set(definitions.map((d) => d.key));
    const settings = [
        ...definitions.map((d) => {
            const p = settingPlace(d);
            return { key: `set:${d.key}`, title: d.label, path: p.path, text: `${p.path} ${d.help || ''} ${d.key}`, hint: d.help || '', href: p.href, icon: 'sliders', anchor: d.key };
        }),
        ...FIELDS.filter((f) => allowed(f.can) && !registered.has(f.anchor)).map((f) => {
            const p = place(f.view, f.tab);
            return { key: `field:${f.anchor}`, title: f.title, path: p.path, text: `${p.path} ${f.words} ${f.hint}`, hint: f.hint, href: p.href, icon: 'sliders', anchor: f.anchor };
        }),
    ];
    return { pages, settings };
}
