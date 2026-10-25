/**
 * Kullanım Kılavuzu (/panel/kilavuz): sayfa açılınca menüyü
 * tanıtan tur başlar (arka plan bulanık, hedef açıkta; panel/tour.js); tur bitince rehberler okunur.
 *
 * - Tur adımları kişinin gördüğü yan menüden kurulur (bölümler, yetkiye göre; meta.js `buildSections`): her
 *   bölüm bir adım (SECTION_TEXT), adımda bölümün sekmeleri sayılır. Sayfaların metni NAV_TEXT'te (Tüm İşlemler,
 *   arama); `kodmob:module` ile eklenen içerik türü genel metinle anlatılır (test her menü sayfasını arar).
 * - Rehberler `panel/guides/*.html` parçalarıdır; yalnızca açılınca ayrı dosya olarak iner
 *   (`?raw` dinamik içe aktarma) ve bellekte kalır. Rehberdeki panel bağlantısı adres yazmaz:
 *   `<a data-route="seo" data-tab="ai">` rota tablosundan çözülür (routes.js `href`).
 * - `?rehber=<anahtar>` rehberi doğrudan açar, tur başlamaz (paylaşılabilir bağlantı). Rehberi
 *   açıp kapamak adresi değiştirir; tarayıcının geri tuşu listeye döner (kmPanel `km-route`).
 */
import { tour } from './tour.js';
import { buildSections } from './meta.js';
import { PROJECT_PAGES } from './project.js';
import { href, navigate } from './routes.js';
import { toast } from '../ui/toast.js';

/** Yan menünün bölümleri: turda ne anlatılır. Anahtar meta.js SECTIONS `key`. */
export const SECTION_TEXT = {
    overview: 'Panelin açılış sayfası. "Sıradaki adımlar" siteyi yayına hazırlarken sizi adım adım götürür; altında bugünün özeti: yeni talepler, son değişen kayıtlar, süren işler.',
    content: 'Sitede görünen her şey: sayfaların bölümleri (Sayfalar), blog ve projenizin kayıtları. Bölümün sayfaları üstte sekme olarak durur.',
    inbox: 'Siteden size gelenler: iletişim ve teklif talepleri; Mağaza ya da Rezervasyon açıksa siparişler ve rezervasyonlar. Yeni olanların sayısı menüde görünür.',
    growth: 'Sitenin bulunması ve büyümesi: SEO (arama motorları ve yapay zekâ), diller ve sayfa metinleri, açıksa bülten.',
    settings: 'Projenin ve kurulumun ayarları: kimlik, iletişim ve yasal bilgiler (Proje), yasal belgeler, kullanıcılar, e-posta ve yedek (Sistem), sistem sağlığı ve araçlar.',
};

/** Menü sayfaları: ne işe yarar. Anahtar meta.js PAGES `key`. */
export const NAV_TEXT = {
    overview: 'Panelin açılış sayfası: içerik sayıları, son değişen kayıtlar, yeni talepler ve süren işler. Yeni kayıt ve yeni yazı kısayolları burada.',
    other: 'Paneldeki bütün işler tek sayfada, gruplu kartlarda; menüde olmayanlar da (üst bardaki ızgara simgesi). Üstteki Hızlı İşlemler size özeldir: satırdaki yıldızla ekleyin, sürükleyerek sıralayın. Bu kılavuz da buradan açılır.',
    posts: 'Blog yazıları: taslak, yayında ya da ileri bir tarihe zamanlanmış. Yazar rolü yazar; Editör ya da Yönetici yayına alır.',
    orders: 'Mağazanın siparişleri: ödeme bekleyen, hazırlanan, kargodaki. Siparişi açınca müşteri, adres ve ürünler; durumu değiştirince müşteriye e-posta gider.',
    coupons: 'İndirim kuponları: yüzde ya da tutar, alt sınır, kullanım sayısı ve tarih aralığı.',
    newsletter: 'E-posta bülteni: Bültenler sekmesinde yazın, kendinize deneme gönderin, onaylı abonelere gönderin (saatlik sınırla parça parça). Aboneler sekmesinde liste, CSV ve silme.',
    members: 'Site üyeleri: üyelik tarihi, doğrulama, son giriş. Kapat (oturumları sonlanır) ya da sil; siparişleri kalır.',
    bookings: 'Tur ve etkinlik rezervasyonları tarihe göre: Yaklaşan ve Geçmiş; kişi sayıları, müşteri ve siparişin ödeme durumu.',
    briefs: 'Siteden gelen talepler: proje brifi, teklif hesaplayıcı ve iletişim formu. Yeni, Okundu, Arşiv sekmeleri; talebi açınca okundu olur, Yanıtla e-posta programınızı açar.',
    jarvis: 'Yapay zekâ asistanı: siteyle ilgili sorular, metin yazdırma ve çeviri, site durumunun özeti, domain taraması. Talep sahiplerinin kişisel verisi yapay zekâya gönderilmez.',
    domains: 'Alan adı uygunluğu: hızlı kontrol (en çok 100 ad) ya da binlerce adayı arka planda tarayan tarama. Tarama bitince bildirim gelir.',
    builder: 'Sayfaların bölümlerini görerek düzenleyin: soldan blok sürükleyin, sayfada yazıya çift tıklayıp yazın, sağda ayarlarını değiştirin. Değişiklikler taslakta kalır; Yayınla deyince ziyaretçiler görür. Jarvis metin yazar, çevirir, sayfaya bölüm önerir.',
    legal: 'Gizlilik, KVKK, çerez ve kullanım koşulları; Mağaza, Bülten gibi modüller açılınca onların sözleşme ve aydınlatma metinleri de gelir. Firma bilgileri Proje Ayarları › Yasal\'dan dolar; bir belgeyi bir dilde özelleştirip istediğinizde şablona dönebilirsiniz.',
    languages: 'Sitenin dilleri ve sayfa metinleri (başlıklar, sabit bölümler), dil adresleri, ziyaretçiye dil önerisi ve çeviri sağlığı: eksik çeviriler, kırık bağlantılar.',
    estimate: 'Teklif hesaplayıcının proje türleri, özellikleri, fiyatları (TRY ve EUR) ve süreleri. Seçenek adları Sayfa metinleri\'nden değişir.',
    seo: 'Arama motorları ve yapay zekâ: SEO puanları, sayfa başlıkları, yönlendirmeler, 404 kayıtları, robots.txt ve llms.txt. Yapay zekâ sekmesi ChatGPT, Claude ve Perplexity\'nin sitenizi ne kadar okuduğunu gösterir.',
    'project-settings': 'Bu projenin bilgileri: kimlik (ad, logo, renkler), iletişim, sosyal medya, yasal bilgiler, görseller, talepler ve araçların ayarları. Kaydedince sitede hemen görünür.',
    'system-settings': 'Kurulumun tamamı: e-posta (SMTP) ve SMS gönderimi, yapay zekâ sağlayıcıları ve anahtarları, arka plan işleri, yedekleme ve sistem sağlığı raporu.',
    history: 'Kim, ne zaman, neyi değiştirdi: kayıt, alan, önceki ve sonraki değer. Kendi işlemleriniz; yetkiniz varsa herkesinki.',
    users: 'Panel kullanıcıları ve rolleri: ekleme, rol verme, pasife alma, oturumlarını kapatma; kullanıcıya SMS ya da e-posta gönderme. Rollerin yetkileri ve yan menüsü Roller ve İzinler\'de.',
    roles: 'Rollerin gördüğü sayfalar, işlem yetkileri (yayınlama, silme, kullanıcı ekleme) ve rolün yan menüsü. Yönetici rolü her şeyi görür ve değişmez.',
    security: 'Açık oturumlar, hatalı girişler, IP engelleri, kilitli girişler ve güvenlik ayarları; gerekirse bütün oturumları geçersiz kılma.',
    sites: 'Aynı kurulumdan alt siteler: her birinin alan adı, veritabanı, kullanıcıları ve ayarları ayrı. Site Sihirbazı işletme bilgisinden logo yazısı, renk ve metinleri önerir.',
    tools: 'Projede kurulu araçlar (Jarvis, Domain arama, Teklif hesaplayıcı…): bu projede açın ya da kapatın. Kapalı aracın menüsü ve işlemleri kalkar, verisi silinmez. Keşfet sekmesinde eklenebilecek araçlar.',
    'tools-add': 'Projeye yeni araç eklemek için: Araç Pazarı › Keşfet. Çatının araçları "Projeye ekle" ile derlemesiz eklenir.',
    health: 'Kurulumun tek bakışta özeti: sunucu, güvenlik, hız, veritabanı, depolama, arka plan ve yedek; dikkat gerekenler en üstte. Kullanıcı bir hata kodu (ör. DB-3F9A1C) iletince ayrıntısı burada. Bildir özeti teknik servise e-postayla gönderir.',
};

// Projenin özel sayfaları kendi metnini getirir (profilin panel.js PAGES `guide`); içerik türleri `TYPES[…].guide`.
for (const p of PROJECT_PAGES) NAV_TEXT[p.key] = p.guide || `${p.label}: bu projeye özel ekran.`;

const FLOW = ['İçerik ekle', 'Çevir', 'SEO', 'Yayınla', 'Talepleri yanıtla'];

/** Üst bar adımları (kimlik `top-`: menüdeki aynı adlı sayfayla karışmasın). `can`: yalnızca yetkisi olana. */
const TOP = [
    { id: 'top-search', target: 'search', eyebrow: 'Üst bar', title: 'Panelde arayın', text: 'Sayfa, ayar ya da kayıt adını yazın: projeler, yazılar, talepler, kullanıcılar ve ayarlar bulunur; ayar sonucunda alan açılıp gösterilir. Kısayol: Ctrl K ya da /.' },
    { id: 'top-jarvis', target: 'jarvis', eyebrow: 'Üst bar', title: 'Jarvis', text: 'Yapay zekâ asistanına her sayfadan ulaşın. Sohbet için Sistem Ayarları › Yapay zekâ\'da bir sağlayıcının (Claude, OpenAI ya da Gemini) anahtarı gerekir.', can: 'km_use_jarvis' },
    { id: 'top-all', target: 'all-ops', eyebrow: 'Üst bar', title: 'Tüm İşlemler', text: 'Paneldeki bütün işler gruplu kartlarda; menüde olmayanlar (İşlem Geçmişi, Roller, Güvenlik, Projeler…) da burada. Sık kullandıklarınızı yıldızla Hızlı İşlemler\'e ekleyin.' },
    { id: 'top-notifications', target: 'notifications', eyebrow: 'Üst bar', title: 'Bildirimler', text: 'Yeni talepler ve biten arka plan işleri (ör. domain taraması). Bildirime dokununca ilgili kayıt açılır.' },
    { id: 'top-user', target: 'user', eyebrow: 'Üst bar', title: 'Hesabınız', text: 'Profilim (bilgiler, şifre, iki adımlı doğrulama, oturumlar), İşlemlerim, bu kılavuz, siteyi açma, tema (sistem, açık, koyu) ve çıkış.' },
];

/**
 * Tur adımları: karşılama, üst bar, yan menünün her bölümü (sekmeleriyle), kapanış.
 * `sections`: buildSections sonucu; `can`: (yetki) => bool.
 */
export function tourSteps(sections, can = () => true) {
    const steps = [
        {
            id: 'welcome',
            eyebrow: 'Kullanım Kılavuzu',
            title: 'Kodmob Kullanım Kılavuzuna Hoş Geldiniz',
            text: 'Kısa bir turla üst barı ve sol menüdeki sayfaları tanıtacağız: içeriği eklemekten çevirmeye, arama motorlarına hazırlamaktan gelen talepleri yanıtlamaya. Turdan sonra aşağıda rehberler var.',
            flow: FLOW,
        },
        ...TOP.filter((s) => !s.can || can(s.can)),
        // Menünün üstü: hangi projede olduğunuz (ana projede diğer projelere geçiş, yeni proje).
        { id: 'project', target: 'project', area: 'nav', eyebrow: 'Menü', title: 'Proje', text: 'Menünün üstünde bu proje yazar. Aynı kurulumda başka projeler varsa (ör. alt siteler) yönetici buradan onlara geçer ya da yeni proje kurar; her projenin içeriği, kullanıcıları ve ayarları ayrıdır.' },
    ];
    sections.forEach((section) => {
        const tabs = section.pages.length > 1 ? ` Sekmeler: ${section.pages.map((p) => p.tab).join(', ')}.` : '';
        steps.push({ id: section.key, target: `nav-${section.key}`, area: 'nav', eyebrow: 'Menü', title: section.label, text: `${SECTION_TEXT[section.key] || ''}${tabs}` });
    });
    steps.push({
        id: 'done',
        eyebrow: 'Tur bitti',
        title: 'Hepsi bu kadar',
        text: 'Ayrıntılar rehberlerde: içerik ve çeviri, site ve SEO, talepler ve Jarvis, yönetim. Turu istediğiniz zaman "Turu başlat" ile yeniden izleyebilirsiniz.',
    });
    return steps;
}

/** Rehberler. `can`: görmek için gereken yetkilerden biri ('a|b'); boşsa herkes. */
export const GUIDES = [
    {
        key: 'baslangic', icon: 'compass', tone: 'primary', minutes: 7, audience: 'Herkes',
        title: 'Panele başlarken: içerik, yayın ve çeviri',
        summary: 'Menü, arama ve Hızlı İşlemler; kayıt ekleme ve sıralama, taslak ve yayın, çeviriler, görseller, işlem geçmişi ve hesabınızın güvenliği.',
        load: () => import('./guides/baslangic.html?raw'),
    },
    {
        key: 'site', icon: 'globe', tone: 'info', minutes: 9, audience: 'Editör, Yönetici', can: 'km_edit_layout|km_manage_texts|km_manage_languages|km_manage_seo',
        title: 'Site: sayfa düzenleyici, metinler, diller, SEO ve yapay zekâ',
        summary: 'Sayfa düzenleyici (bloklar, sürükle-bırak, yayın), sayfa metinleri, dil ekleme ve yayına alma, SEO puanı ve sayfa başlıkları, yönlendirmeler ve 404, robots ve llms.txt, yapay zekâ görünürlüğü.',
        load: () => import('./guides/site.html?raw'),
    },
    {
        key: 'talepler', icon: 'inbox', tone: 'success', minutes: 5, audience: 'Satış, Editör, Yönetici', can: 'km_view_briefs|km_use_jarvis|km_manage_estimate|km_use_domains',
        title: 'Talepler, teklif hesaplayıcı ve Jarvis',
        summary: 'Gelen talepleri okuma, yanıtlama ve arşivleme; hesaplayıcının fiyatları; Jarvis ile çalışma ve domain arama.',
        load: () => import('./guides/talepler.html?raw'),
    },
    {
        key: 'yonetim', icon: 'shield-check', tone: 'danger', minutes: 8, audience: 'Yönetici',
        can: 'km_view_users|km_manage_roles|km_manage_settings|km_manage_ai|km_manage_backups|km_manage_security|km_manage_sites|km_view_health',
        title: 'Yönetici: kullanıcılar, roller, ayarlar, yedek, sistem sağlığı ve siteler',
        summary: 'Kullanıcı ve rol yönetimi, güvenlik; e-posta, SMS ve yapay zekâ ayarları, arka plan işleri, yedekleme; alt siteler ve Site Sihirbazı.',
        load: () => import('./guides/yonetim.html?raw'),
    },
    {
        key: 'hiz', icon: 'zap', tone: 'warning', minutes: 6, audience: 'Yönetici, geliştirici', can: 'km_view_health|km_manage_settings',
        title: 'Hız, önbellek ve ölçek',
        summary: 'Sayfa önbelleği ve ne zaman yenilendiği, görseller ve WebP, tarayıcı önbelleği, hızı ölçmek, yük testi; trafik artınca CDN, VPS, PostgreSQL ve Redis.',
        load: () => import('./guides/hiz.html?raw'),
    },
];

const cache = new Map();

/** Rehber metni: panel bağlantıları rota tablosundan (data-route, data-tab, data-type). */
function prepare(raw) {
    const doc = new DOMParser().parseFromString(raw, 'text/html');
    doc.querySelectorAll('a[data-route]').forEach((a) => {
        const { route, tab, type } = a.dataset;
        a.setAttribute('href', href(route, { ...(tab ? { tab } : {}), ...(type ? { type } : {}) }));
    });
    return {
        html: doc.body.innerHTML,
        toc: [...doc.querySelectorAll('h2[id]')].map((h) => ({ id: h.id, label: h.textContent.trim() })),
    };
}

export function kmGuide() {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        onRoute: null,
        onTourEnd: null,
        observer: null,
        current: null,
        html: '',
        loading: false,
        toc: [],
        tocActive: '',

        init() {
            this.onRoute = (event) => this.show(event.detail?.query?.rehber || null);
            this.onTourEnd = () => {
                if (this.current) return;
                this.$refs.guidesTitle?.scrollIntoView({ block: 'start', behavior: 'smooth' });
                this.$refs.guidesTitle?.focus({ preventScroll: true });
            };
            window.addEventListener('km-route', this.onRoute);
            window.addEventListener('km-tour-end', this.onTourEnd);
            this.show(new URLSearchParams(window.location.search).get('rehber'));
        },

        destroy() {
            window.removeEventListener('km-route', this.onRoute);
            window.removeEventListener('km-tour-end', this.onTourEnd);
            this.observer?.disconnect();
        },

        get guides() {
            return GUIDES.filter((g) => !g.can || this.$store.session.can(g.can));
        },

        startTour() {
            if (this.current) this.close();
            window.dispatchEvent(new CustomEvent('km-tour-start'));
        },

        open(key) {
            navigate(href('guide', {}, { rehber: key }));
        },

        close() {
            navigate(href('guide'));
        },

        /** Adresteki rehberi gösterir (yoksa liste). */
        async show(key) {
            const guide = key ? this.guides.find((g) => g.key === key) : null;
            this.observer?.disconnect();
            if (!guide) {
                this.current = null;
                this.html = '';
                this.toc = [];
                return;
            }
            this.current = guide;
            this.toc = [];
            window.scrollTo({ top: 0 });
            if (!cache.has(key)) {
                this.loading = true;
                try {
                    cache.set(key, prepare((await guide.load()).default));
                } catch {
                    toast.error('Rehber yüklenemedi. Bağlantınızı kontrol edip yeniden deneyin.');
                    this.current = null;
                    return;
                } finally {
                    this.loading = false;
                }
            }
            if (this.current?.key !== key) return;
            ({ html: this.html, toc: this.toc } = cache.get(key));
            this.tocActive = this.toc[0]?.id || '';
            this.$nextTick(() => requestAnimationFrame(() => this.observe()));
        },

        /** Görünen bölümü içindekilerde vurgular. */
        observe() {
            const headings = this.$root.querySelectorAll('article.guide h2[id]');
            this.observer?.disconnect();
            if (!headings.length) return;
            this.observer = new IntersectionObserver((entries) => {
                const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
                if (visible[0]) this.tocActive = visible[0].target.id;
            }, { rootMargin: '-72px 0px -65% 0px' });
            headings.forEach((h) => this.observer.observe(h));
        },

        jump(id) {
            document.getElementById(id)?.scrollIntoView({ block: 'start', behavior: 'smooth' });
            this.tocActive = id;
        },

        print() {
            window.print();
        },
    };
}

/** Sayfa açılınca tur başlar; rehber bağlantısıyla gelindiyse başlamaz. */
export function kmGuideTour() {
    const session = () => window.Alpine.store('session');
    const can = (cap) => session().can(cap);
    return tour({
        steps: () => tourSteps(buildSections(can), can),
        autoStart: !new URLSearchParams(window.location.search).has('rehber'),
    });
}
