/**
 * Yönetim paneli kökü (`<body x-data="kmPanel">`): oturum, adres yönlendirmesi,
 * rol bazlı menü ve kaydedilmemiş değişiklik koruması.
 *
 * Adresler `/panel/…` yollarıdır (History API; rota tablosu routes.js): /panel/ genel
 * bakış · /panel/projeler liste · /panel/projeler/yeni · /panel/projeler/12 düzenle ·
 * /panel/talepler/12 · /panel/jarvis/5 · /panel/jarvis/domain-arama/3 ·
 * /panel/seo/yonlendirmeler · /panel/ayarlar/yedekleme · /panel/islem-gecmisi/tumu …
 * Bilinmeyen adres "Sayfa bulunamadı" görünümüdür. Eski `/panel/#/…` adresleri (e-postalar,
 * yer imleri) açılışta yenisine çevrilir.
 *
 * Gezinme: paneldeki `<a href="/panel/…">` tıklamaları sayfa yenilenmeden açılır (Ctrl/Cmd ya
 * da orta tık yeni sekme; sunucu her panel adresine panel/index.html verir). Görünümler
 * `navigate()` / `replaceUrl()` (routes.js) kullanır. Her görünüm kendi bileşenidir
 * (panel/*.js); `route.key` değişince yeniden kurulur, aynı anahtarda kalan değişiklik
 * (sekme, sohbet, sorgu) görünümü kurmaz, `km-route` olayı gider.
 *
 * Menü rolün menüsünden (Roller ve İzinler > Yan Menü) ya da varsayılandan kurulur ve
 * yetkiye göre süzülür ($store.session). Yetkisi olmayan sayfa adresle açılırsa
 * "Yetkiniz yok" ekranı çıkar. Asıl yetki sunucudadır: oturum düşerse istek 401 alır,
 * http.js yenilemeyi dener, olmazsa aynı adreste giriş ekranı açılır.
 */
import { auth } from '../services/auth.js';
import { storage } from '../services/storage.js';
import { STORAGE_KEYS } from '../services/config.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { fmt } from '../domain/format.js';
import { TYPES, BOTTOM_CANDIDATES, PAGES, buildSections, pageByView } from './meta.js';
import { match, isPanelPath, toPanelUrl, href } from './routes.js';
import { api } from './api.js';
import { isLazyView, loadView, preloadViews } from './views.js';
import { toast } from '../ui/toast.js';
import { spotlight, spotlightFromHash } from '../ui/spotlight.js';

/** Görünümlerin paylaştığı durum: `$store.panel`. */
export function createPanelStore() {
    return {
        dirty: false,
        newBriefs: 0,
        activeJobs: 0,
        unreadNotifications: 0,
        user: storage.getJSON(STORAGE_KEYS.SESSION, null),

        async refreshBadges() {
            if (!window.Alpine.store('session').can('km_view_briefs')) return;
            try {
                this.newBriefs = (await api.briefs({ status: 'new', per_page: 1 })).total;
            } catch {
                /* rozet önemsiz */
            }
        },

        async refreshJobs() {
            if (!window.Alpine.store('session').can('km_use_domains')) return;
            try {
                this.activeJobs = (await api.jarvisJobs({ status: 'active', per_page: 1 })).total;
            } catch {
                /* rozet önemsiz */
            }
        },
    };
}

const here = () => window.location.pathname + window.location.search;
const parse = (url) => {
    const [pathname, search = ''] = String(url).split('?');
    return match(pathname, search);
};

/**
 * Açılıştaki adresi kanonik hale getirir: eski `#/…` adresi yenisine, sondaki `/` ve
 * `index.html` düzeltilir (replaceState; geçmişe kayıt eklenmez).
 */
function canonicalStart() {
    const legacy = window.location.hash.startsWith('#/') ? toPanelUrl(window.location.hash) : null;
    const url = legacy || here();
    const target = parse(url).canonical || url;
    if (target !== here() + window.location.hash) history.replaceState(null, '', target);
    return target;
}

/**
 * Görünüm dosyası inmediğinde bir kez yenileme bayrağı (sekme oturumunda). Argümansız: okur
 * (false = yok, null = depolama kullanılamıyor); true/false: yazar/siler, başarıyı döner.
 */
function reloadFlag(value) {
    const key = 'km_view_reload';
    try {
        if (value === undefined) return sessionStorage.getItem(key) === '1';
        if (value) sessionStorage.setItem(key, '1');
        else sessionStorage.removeItem(key);
        return true;
    } catch {
        return null;
    }
}

const LEAVE = {
    title: 'Kaydedilmemiş değişiklikler var',
    message: 'Sayfadan çıkarsanız yaptığınız değişiklikler kaybolur.',
    confirmText: 'Çık, kaydetme',
    cancelText: 'Sayfada kal',
    tone: 'danger',
};

/** Projeler arası geçiş anahtarı (`?gecis=`; kodmob-core Auth\Services\Handoff): okunur ve adresten hemen silinir (geçmişte kalmasın). */
function takePass() {
    const url = new URL(window.location.href);
    const pass = url.searchParams.get('gecis');
    if (!pass) return null;
    url.searchParams.delete('gecis');
    history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    return pass;
}

export function kmPanel() {
    const pass = takePass();
    // Yeni sekmede açılan ayar bağlantısı (/panel/ayarlar/e-posta#ayar=mail.host); adres kanonikleşmeden önce.
    const startSpot = spotlightFromHash(window.location.hash);
    const start = canonicalStart();
    return {
        authed: auth.hasSession(),
        route: parse(start),
        navOpen: false,
        types: TYPES,
        lastUrl: start,
        now: new Date(),
        fmt,
        /** İndirilmiş görünümler (views.js): görünüm bileşenleri kaydedilmeden açılmaz. */
        loadedViews: {},

        init() {
            window.addEventListener('popstate', () => this.onPop());
            window.addEventListener('km-navigate', (event) => this.go(event.detail.url, { replace: event.detail.replace }));
            // Görünüm adresi kendisi değiştirdi (sekme, sohbet, süzgeç): yalnızca hatırlanır.
            window.addEventListener('km-url-replaced', (event) => (this.lastUrl = event.detail));
            window.addEventListener('km-signed-out', () => this.signedOut());
            // Kılavuz turu (panel/tour.js): menü adımlarında mobilde çekmece açılır.
            window.addEventListener('km-tour-nav', (event) => {
                this.navOpen = !!event.detail?.drawer;
            });
            document.addEventListener('click', (event) => this.onLinkClick(event));
            window.addEventListener('beforeunload', (event) => {
                if (this.$store.panel.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
            setInterval(() => (this.now = new Date()), 30000);
            // Görünüm giriş ekranı açıkken de iner; girişten sonra beklenmez.
            this.ensureView(this.route.view);
            if (pass) this.handoff(pass);
            else if (this.authed) this.start();
            if (startSpot) spotlight(startSpot);
        },

        /** Adresteki görünüm açılabilir mi (modülü indi ve bileşenleri kaydedildi). */
        get viewReady() {
            return !isLazyView(this.route.view) || !!this.loadedViews[this.route.view];
        },

        async ensureView(view) {
            if (this.loadedViews[view] || !isLazyView(view)) return;
            try {
                await loadView(window.Alpine, view);
                this.loadedViews = { ...this.loadedViews, [view]: true };
                reloadFlag(false);
                preloadViews(window.Alpine);
            } catch {
                // Yeni sürüm yüklendi, eski dosya adları artık yok: sayfa bir kez yenilenir
                // (bayrak yazılamıyorsa yenilenmez; sonsuz döngü olmasın).
                if (reloadFlag() === false && reloadFlag(true)) {
                    window.location.reload();
                    return;
                }
                toast.error('Sayfa yüklenemedi. Bağlantınızı kontrol edip sayfayı yenileyin.');
            }
        },

        /** Oturum açıkken: kullanıcı ve yetkiler, rozetler, nabız. */
        async start() {
            const panel = this.$store.panel;
            try {
                await this.$store.session.load();
                panel.user = { name: this.$store.session.me.display_name, email: this.$store.session.me.email };
                // Genel bakış yetkisi yoksa açılış sayfası menüdeki ilk sayfa.
                if (this.route.view === 'overview' && !this.allowed) this.go(this.homeUrl, { replace: true });
            } catch {
                /* 401 ise http.js giriş ekranına döner */
            }
            panel.refreshBadges();
            panel.refreshJobs();
            this.$store.pulse.start();
            this.$watch(() => this.$store.pulse.version('brief'), () => panel.refreshBadges());
            this.$watch(() => this.$store.pulse.version('jarvis_job'), () => panel.refreshJobs());
            // Rol değişince (Roller ve İzinler) menü ve yetkiler tazelenir.
            this.$watch(() => this.$store.pulse.version('role'), () => this.$store.session.load().catch(() => {}));
        },

        // ------------------------------------------------------------ gezinme
        /** Panel içi bağlantı: aynı sekmede, değiştirici tuşsuz sol tık → sayfa yenilenmeden. */
        onLinkClick(event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest?.('a[href]');
            if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download')) return;
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin || !isPanelPath(url.pathname)) return;
            // Ayar bağlantısı (#ayar=anahtar): sayfa açılır, alan bulunup gösterilir (ui/spotlight.js).
            const spot = spotlightFromHash(url.hash);
            // Aynı sayfadaki çapa (#bölüm) tarayıcıya kalır; eski `#/…` bağlantısı çevrilir.
            if (url.hash && !spot && !url.hash.startsWith('#/') && url.pathname + url.search === here()) return;
            event.preventDefault();
            if (spot && url.pathname + url.search === here()) return spotlight(spot);
            this.go(url.hash.startsWith('#/') ? url.hash : url.pathname + url.search).then(() => spot && spotlight(spot));
        },

        async confirmLeave() {
            if (!this.$store.panel.dirty) return true;
            if (!(await confirmAction(LEAVE))) return false;
            this.$store.panel.dirty = false;
            return true;
        },

        /** Adrese git (görünümlerden `navigate()`, bağlantılardan). `replace`: geçmişe kayıt ekleme. */
        async go(url, { replace = false } = {}) {
            const target = toPanelUrl(url);
            const route = parse(target);
            const next = route.canonical || target;
            if (next === this.lastUrl && next === here()) return;
            if (!(await this.confirmLeave())) return;
            history[replace ? 'replaceState' : 'pushState'](null, '', next);
            this.apply(next, route);
        },

        /** Geri / ileri: adres zaten değişti; vazgeçilirse önceki adrese dönülür. */
        async onPop() {
            const next = here();
            if (next === this.lastUrl) return;
            if (!(await this.confirmLeave())) {
                history.pushState(null, '', this.lastUrl);
                return;
            }
            this.apply(next, parse(next));
        },

        apply(next, route) {
            this.lastUrl = next;
            this.ensureView(route.view);
            // Aynı ekran içinde adres değişti (ör. sohbet seçimi, sekme): görünüm yeniden kurulmaz, haber verilir.
            if (route.key === this.route.key) {
                this.route = { ...this.route, ...route };
                window.dispatchEvent(new CustomEvent('km-route', { detail: route }));
            } else {
                this.route = route;
                window.scrollTo({ top: 0 });
            }
            this.navOpen = false;
        },

        // ------------------------------------------------------------ yetki
        /** Adresteki sayfa açılabilir mi (yetki). Profil ve İşlem Geçmişi herkese açık. */
        get allowed() {
            const r = this.route;
            const page = r.view === 'editor' ? pageByView('list', r.type) : pageByView(r.view, r.type);
            return !page || this.$store.session.can(page.can);
        },

        /** Genel bakış yetkisi olmayanın açılış sayfası: menüsündeki ilk bölüm. */
        get homeUrl() {
            return this.sections[0]?.href || href('profile');
        },

        // ------------------------------------------------------------ menü
        /** Proje seçici (menünün üstü): bu proje ve (ana projede, projeleri yöneten kişiye) diğerleri. */
        get project() {
            return this.$store.session.me?.project || { key: 'main', name: 'kodmob', main: true };
        },

        get projects() {
            return this.$store.session.me?.projects || [];
        },

        get canManageProjects() {
            return this.project.main && this.$store.session.can('km_manage_sites');
        },

        /** Yan menü: bölümler (Genel bakış, İçerik, Gelenler, Görünürlük, Ayarlar), her biri sayfalarıyla. */
        get sections() {
            return buildSections((cap) => this.$store.session.can(cap));
        },

        /** Açık sayfanın katalogdaki karşılığı (alt görünüm, ör. Metinler → Sayfalar ve Diller). */
        get currentPage() {
            const page = PAGES.find((p) => !p.alias && !p.hidden && this.isActive(p)) || pageByView(this.route.view);
            return page?.hidden && page.parent ? PAGES.find((p) => p.key === page.parent) : page;
        },

        get currentSection() {
            return this.sections.find((s) => s.key === this.currentPage?.section) || null;
        },

        /** Bölümün sekmeleri; seyrek sayfadaysanız (ör. Roller) o da sekme olarak eklenir. Tek sayfalık bölümde yok. */
        get sectionTabs() {
            const section = this.currentSection;
            if (!section) return [];
            const tabs = section.pages.some((p) => p.key === this.currentPage.key) ? section.pages : [...section.pages, this.currentPage];
            return tabs.length > 1 ? tabs : [];
        },

        isSectionActive(section) {
            return section.key === this.currentPage?.section;
        },

        sectionBadge(section) {
            return section.pages.reduce((sum, p) => sum + (Number(this.badgeFor(p)) || 0), 0);
        },

        get bottomNav() {
            const list = BOTTOM_CANDIDATES.filter((i) => this.$store.session.can(i.can)).slice(0, 4);
            return [...list, { action: 'menu', label: 'Menü', icon: 'menu' }];
        },

        isActive(item) {
            // Kısayol öğesi ("Araç ekle" → Araç Pazarı › Keşfet) sayfanın kendisi gibi işaretlenmez.
            if (item.alias) return false;
            if (item.view === 'list') return this.route.type === item.type && ['list', 'editor'].includes(this.route.view);
            // Alt görünümler (ör. Metinler → Çoklu Dil) üst sayfayı işaretler.
            return !!item.view && (this.route.view === item.view || pageByView(this.route.view)?.parent === item.key);
        },

        badgeFor(item) {
            if (item.badge === 'briefs') return this.$store.panel.newBriefs;
            if (item.badge === 'jobs') return this.$store.panel.activeJobs;
            return 0;
        },

        runBottom(item) {
            if (item.action === 'menu') this.navOpen = true;
        },

        get title() {
            const r = this.route;
            if (r.view === 'list') return TYPES[r.type].label;
            if (r.view === 'editor') return r.id ? `${TYPES[r.type].singular} düzenle` : TYPES[r.type].newLabel;
            if (r.view === 'notFound') return 'Sayfa bulunamadı';
            return pageByView(r.view)?.label || 'Panel';
        },

        /** Bilinmeyen adres için öneriler: menüdeki sayfalardan adresi ya da adı benzeyenler (en fazla 4). */
        get suggestions() {
            if (this.route.view !== 'notFound') return [];
            const fold = (s) => String(s || '').toLocaleLowerCase('tr-TR');
            const words = fold(this.route.path).replace(/^\/panel\/?/, '').split(/[/\-_.]+/).filter((w) => w.length >= 3);
            const score = (p) => words.reduce((n, w) => n + (fold(`${p.href} ${p.label}`).includes(w.slice(0, 4)) ? 1 : 0), 0);
            return this.sections.flatMap((s) => s.pages).map((p) => [p, score(p)]).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]).slice(0, 4).map(([p]) => p);
        },

        get initials() {
            return fmt.initials(this.$store.panel.user?.name || 'K');
        },

        // ------------------------------------------------------------ oturum
        /** Ana projeden tek oturumla geçiş: anahtar oturuma çevrilir; geçersizse giriş ekranı (ya da var olan oturum). */
        async handoff(token) {
            try {
                this.signedIn(await auth.handoff(token));
            } catch (error) {
                toast.error(error.message);
                if (this.authed) this.start();
            }
        },

        /** Proje seçiciden başka projeye: tek oturumla geçiş bağlantısı; olmazsa o projenin girişi. */
        async switchProject(project) {
            try {
                window.location.assign((await api.handoffSite(project.key)).url);
            } catch (error) {
                toast.error(error.message || 'Projeye geçilemedi.');
                setTimeout(() => window.location.assign(project.panel), 1800);
            }
        },

        /** Giriş başarılı: kullanıcı bilgisi saklanır, panel açılır. */
        signedIn(data) {
            const user = { name: data.user_display_name, email: data.user_email };
            storage.setJSON(STORAGE_KEYS.SESSION, user);
            this.$store.panel.user = user;
            this.authed = true;
            this.start();
        },

        async logout() {
            if (this.$store.panel.dirty && !(await confirmAction({ title: 'Kaydedilmemiş değişiklikler var', message: 'Çıkış yaparsanız değişiklikler kaybolur.', confirmText: 'Çıkış yap', tone: 'danger' }))) {
                return;
            }
            this.$store.panel.dirty = false;
            await auth.logout();
            this.signedOut();
        },

        /** Oturum kapandı (bu sekmede çıkış ya da başka sekmede çıkış: `km-signed-out`). */
        signedOut() {
            this.$store.pulse.stop();
            this.$store.session.clear();
            this.authed = false;
            this.$store.panel.user = null;
        },
    };
}
