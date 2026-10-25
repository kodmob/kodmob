/**
 * SEO (/panel/seo, `km_manage_seo`) — kodmob-seo-core WordPress eklentisinin panel karşılığı
 * (kodmob-core Modules/Seo). Sekmeler adreste (`/panel/seo/yonlendirmeler`; routes.js TAB_SLUGS):
 *
 * - Genel bakış: SEO modülünü tamamen aç/kapat, alt modüller, göstergeler, kontrol listesi.
 * - İçerik: yayındaki proje ve yazıların SEO puanı (dil başına); düzenleyicide SEO kutusu.
 * - Yapay zekâ: yapay zekâ botlarının ziyaretleri (ChatGPT, Claude, Perplexity…), site denetimi ve içeriğin
 *   yapay zekâ cevaplarına hazırlığı (kodmob-core Seo\Services\AiVisibility).
 * - Sayfalar: sabit sayfaların başlık, açıklama, noindex ve paylaşım görseli (arama önizlemesiyle).
 * - Yönlendirmeler: 301/302/307/410, düzenli ifade, "Dene", CSV içe/dışa aktarma.
 * - 404: bulunamayan adresler (iç bağlantıdan gelenler işaretli, önerilen hedef); tek tıkla
 *   yönlendirme, yoksay, sil. Üstte içerikteki kırık / yönlenen iç bağlantılar (kodmob-core
 *   Seo\Services\LinkChecker; her gece ve "Yeniden denetle" ile).
 * - Robots ve llms: robots.txt ek kuralları, yapay zekâ botları, llms.txt, site haritası.
 * - Ayarlar: paylaşım, kurum şeması, doğrulama kodları, içerik (içindekiler, dış bağlantılar), IndexNow.
 *
 * SEO kapalıyken yalnızca genel bakıştaki anahtar görünür; veriler silinmez.
 */
import { href, navigate, replaceUrl } from './routes.js';
import { api } from './api.js';
import { languages, TYPES } from './meta.js';
import { recordPatterns } from '../i18n/client.js';

/** SEO > İçerik tür süzgeci: kendi sayfası olan türler (kodmob.com: proje) ve blog. */
const seoTypeOptions = () => [
    ...Object.values(TYPES).filter((t) => t.record !== 'post' && recordPatterns(t.record)).map((t) => ({ value: t.record, label: t.singular })),
    { value: 'post', label: 'Blog' },
];
import { fmt } from '../domain/format.js';
import { localSource } from '../ui/grid/local-source.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const TABS = [
    { key: 'overview', label: 'Genel bakış', icon: 'grid' },
    { key: 'content', label: 'İçerik', icon: 'pencil', module: 'analysis' },
    { key: 'ai', label: 'Yapay zekâ', icon: 'sparkles', module: 'ai' },
    { key: 'pages', label: 'Sayfalar', icon: 'book', module: 'meta' },
    { key: 'redirects', label: 'Yönlendirmeler', icon: 'arrow-up-right', module: 'redirects' },
    { key: '404', label: '404', icon: 'alert-circle', module: 'monitor404' },
    { key: 'robots', label: 'Robots ve llms', icon: 'shield-check' },
    { key: 'settings', label: 'Ayarlar', icon: 'cog' },
];

const EMPTY_REDIRECT = { id: 0, source: '', target: '', code: 301, is_regex: false, active: true, note: '' };

export function kmSeo(route = {}) {
    // Tablolarda dil rozeti: dil kaydındaki kısa ad (TR, EN…).
    const LOCALE_LABELS = Object.fromEntries(languages().map((l) => [l.code, l.short || l.code.toUpperCase()]));
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _onRoute: null,
        tabs: TABS,
        tab: TABS.some((t) => t.key === route.tab) ? route.tab : 'overview',
        overview: null,
        loading: true,
        settings: { items: [], values: {}, original: {}, errors: {}, saving: false },
        pages: [],
        pagesReady: null,
        pageEdit: null,
        content: [],
        contentReady: null,
        ai: null,
        aiLoading: false,
        aiShowAll: false,
        redirect: null,
        redirectErrors: {},
        tester: { url: '', result: null },
        importing: false,
        robots: { extra: '', preview: '', indexable: false, ai_bots: [], saving: false, error: '' },
        indexnow: null,
        fmt,
        localeLabels: LOCALE_LABELS,
        contentGrid: null,
        pagesGrid: null,
        redirectGrid: null,
        notFoundGrid: null,
        links: { checked_at: null, items: [], loading: false },
        LINK_PROBLEMS: {
            broken: { label: 'Kırık', badge: 'badge-danger' },
            gone: { label: 'Silinmiş içerik', badge: 'badge-warning' },
            redirect: { label: 'Yönleniyor', badge: 'badge-neutral' },
        },

        async init() {
            const self = this;
            this.contentGrid = {
                id: 'seo-content',
                rowKey: 'key',
                source: async (q) => {
                    await self.contentReady;
                    return localSource(self.content, { search: ['title', 'seo_title', 'focus'], facets: ['grade', 'type', 'locale'] })(q);
                },
                pageSize: 20,
                sort: { key: 'score', order: 'asc' },
                exportName: 'seo-icerik',
                empty: { title: 'Yayında kayıt yok', icon: 'pencil' },
                columns: [
                    { key: 'title', label: 'Kayıt', strong: true, sortable: true, card: 'title' },
                    { key: 'type_label', label: 'Tür', filterBy: 'type', filterValue: 'type', card: 'spec' },
                    { key: 'locale', label: 'Dil', cell: (r) => LOCALE_LABELS[r.locale], filterBy: 'locale', card: 'spec' },
                    { key: 'grade', label: 'Puan', badge: 'seoGrade', badgeLabel: (r) => `${r.score} · ${{ good: 'İyi', ok: 'Orta', bad: 'Zayıf' }[r.grade]}`, sortable: true, sortKey: 'score', card: 'badge' },
                    { key: 'title_length', label: 'Başlık', format: 'number', sortable: true, hideBelow: 'lg', card: 'spec' },
                    { key: 'description_length', label: 'Açıklama', format: 'number', sortable: true, hideBelow: 'lg', card: 'spec' },
                    { key: 'focus', label: 'Odak kelime', muted: true, hideBelow: 'xl' },
                    { key: 'issue_text', label: 'Sorunlar', muted: true, class: 'max-w-sm truncate', card: 'meta' },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Başlık ya da odak kelime' },
                    { key: 'grade', type: 'tabs', label: 'Puan', statusKind: 'seoGrade' },
                    { key: 'type', type: 'select', label: 'Tür', options: seoTypeOptions() },
                    { key: 'locale', type: 'select', label: 'Dil', options: Object.entries(LOCALE_LABELS).map(([value, label]) => ({ value, label })) },
                ],
                rowActions: [
                    { key: 'edit', label: 'Düzenle', primary: true },
                    { key: 'view', label: 'Sitede aç', icon: 'external', when: (r) => !!r.url },
                ],
            };
            this.pagesGrid = {
                id: 'seo-pages',
                rowKey: 'key',
                source: async (q) => {
                    await self.pagesReady;
                    return localSource(self.pages.map((p) => ({ ...p, state: p.customized ? 'custom' : 'default' })), { search: ['path', 'title', 'default_title'], facets: ['locale'] })(q);
                },
                pageSize: 50,
                sort: { key: 'path', order: 'asc' },
                exportName: 'seo-sayfalar',
                columns: [
                    { key: 'path', label: 'Sayfa', strong: true, mono: true, sortable: true, card: 'title' },
                    { key: 'locale', label: 'Dil', cell: (r) => LOCALE_LABELS[r.locale], filterBy: 'locale', card: 'spec' },
                    { key: 'title', label: 'Başlık', cell: (r) => r.title || r.default_title, card: 'subtitle' },
                    { key: 'description', label: 'Açıklama', cell: (r) => r.description || r.default_description, muted: true, class: 'max-w-md truncate', hideBelow: 'lg' },
                    { key: 'state', label: 'Durum', cell: (r) => (r.noindex ? 'Gizli (noindex)' : r.customized ? 'Düzenlendi' : 'Varsayılan'), card: 'meta' },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Adres ya da başlık' },
                    { key: 'locale', type: 'tabs', label: 'Dil', options: Object.entries(LOCALE_LABELS).map(([value, label]) => ({ value, label })) },
                ],
                rowActions: [{ key: 'edit', label: 'Düzenle', primary: true, open: true }],
                export: false,
            };
            this.redirectGrid = {
                id: 'seo-redirects',
                source: (q) => api.redirects(q),
                // Panel aramasından gelen yönlendirme (?q=kaynak) süzülmüş açılır.
                values: { q: new URLSearchParams(window.location.search).get('q') || '' },
                pageSize: 50,
                sort: { key: 'created_at', order: 'desc' },
                exportCsv: () => self.exportRedirects(),
                exportName: 'yonlendirmeler',
                empty: { title: 'Yönlendirme yok', text: 'Eski adresleri yeni sayfalara yönlendirin; 404 listesinden tek tıkla da eklenir.', icon: 'arrow-up-right' },
                columns: [
                    { key: 'source', label: 'Kaynak', mono: true, strong: true, sortable: true, card: 'title' },
                    { key: 'target', label: 'Hedef', mono: true, cell: (r) => r.target || '—', card: 'subtitle' },
                    { key: 'code', label: 'Tür', badge: 'redirect', card: 'badge' },
                    { key: 'is_regex', label: 'Eşleşme', cell: (r) => (r.is_regex ? 'Düzenli ifade' : 'Kesin'), hideBelow: 'lg', card: 'spec' },
                    { key: 'hits', label: 'Tıklanma', format: 'number', sortable: true, card: 'spec' },
                    { key: 'last_hit_at', label: 'Son', format: 'relative', sortable: true, hideBelow: 'lg', card: 'meta' },
                    { key: 'active', label: 'Aktif', cell: (r) => (r.active ? 'Evet' : 'Kapalı'), hideBelow: 'xl' },
                    { key: 'note', label: 'Not', muted: true, hideBelow: 'xl', hidden: true },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Kaynak, hedef ya da not' },
                    { key: 'code', type: 'tabs', label: 'Tür', statusKind: 'redirect' },
                    { key: 'is_regex', type: 'select', label: 'Eşleşme', options: [{ value: '0', label: 'Kesin' }, { value: '1', label: 'Düzenli ifade' }] },
                    { key: 'active', type: 'select', label: 'Durum', options: [{ value: '1', label: 'Aktif' }, { value: '0', label: 'Kapalı' }] },
                ],
                rowActions: [
                    { key: 'edit', label: 'Düzenle', primary: true, open: true },
                    { key: 'test', label: 'Dene', icon: 'external', when: (r) => !r.is_regex },
                    { key: 'delete', label: 'Sil', icon: 'trash', tone: 'danger' },
                ],
                bulkActions: [{ key: 'delete', label: 'Seçilenleri sil', icon: 'trash', tone: 'danger' }],
            };
            this.notFoundGrid = {
                id: 'seo-404',
                source: (q) => api.notFound(q),
                pageSize: 50,
                sort: { key: 'last_seen_at', order: 'desc' },
                values: { status: 'new', bot: '0' },
                exportName: '404',
                empty: { title: 'Bulunamayan adres yok', text: 'Site 404 verdikçe adresler burada toplanır.', icon: 'check-circle' },
                columns: [
                    { key: 'path', label: 'Adres', mono: true, strong: true, sortable: true, card: 'title' },
                    { key: 'status', label: 'Durum', badge: 'notFound', card: 'badge' },
                    { key: 'internal', label: 'Gelen', cell: (r) => (r.internal ? 'İç bağlantı' : 'Dış'), hideBelow: 'md', card: 'spec' },
                    { key: 'suggestion', label: 'Önerilen hedef', mono: true, muted: true, class: 'max-w-xs truncate', hideBelow: 'lg' },
                    { key: 'hits', label: 'Sayı', format: 'number', sortable: true, card: 'spec' },
                    { key: 'last_seen_at', label: 'Son', format: 'relative', sortable: true, card: 'spec' },
                    { key: 'referrer', label: 'Yönlendiren', muted: true, class: 'max-w-xs truncate', hideBelow: 'lg', card: 'meta' },
                    { key: 'bot', label: 'Kaynak', cell: (r) => (r.bot ? 'Bot' : 'Ziyaretçi'), hideBelow: 'xl' },
                    { key: 'first_seen_at', label: 'İlk', format: 'date', sortable: true, hidden: true, hideBelow: 'xl' },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Adres ya da gelen sayfa' },
                    { key: 'status', type: 'tabs', label: 'Durum', statusKind: 'notFound' },
                    { key: 'bot', type: 'select', label: 'Kaynak', options: [{ value: '0', label: 'Ziyaretçi' }, { value: '1', label: 'Bot' }] },
                    { key: 'internal', type: 'select', label: 'Gelen', options: [{ value: '1', label: 'İç bağlantı (kırık)' }, { value: '0', label: 'Dış' }] },
                    { key: 'last_seen_at', type: 'date-range', label: 'Son görülme' },
                ],
                rowActions: [
                    { key: 'suggested', label: 'Önerilene yönlendir', icon: 'arrow-up-right', primary: true, when: (r) => r.status !== 'redirected' && !!r.suggestion },
                    { key: 'redirect', label: 'Yönlendir', primary: true, when: (r) => r.status !== 'redirected' && !r.suggestion },
                    { key: 'redirectOther', label: 'Başka yere yönlendir', icon: 'arrow-up-right', when: (r) => r.status !== 'redirected' && !!r.suggestion },
                    { key: 'ignore', label: 'Yoksay', icon: 'eye-off', when: (r) => r.status === 'new' },
                    { key: 'delete', label: 'Sil', icon: 'trash', tone: 'danger' },
                ],
                bulkActions: [
                    { key: 'ignore', label: 'Yoksay', icon: 'eye-off' },
                    { key: 'delete', label: 'Sil', icon: 'trash', tone: 'danger' },
                ],
            };
            this._onRoute = (e) => this.show(e.detail?.tab || 'overview');
            window.addEventListener('km-route', this._onRoute);
            await Promise.all([this.loadOverview(), this.loadSettings()]);
            await this.show(this.tab, true);
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
        },

        get enabled() {
            return !!this.overview?.state?.enabled;
        },

        /** Sekme görünür mü: SEO kapalıyken yalnız genel bakış; modüle bağlı sekme modül kapalıysa gizli. */
        visible(t) {
            if (t.key === 'overview') return true;
            if (!this.enabled) return false;
            return !t.module || !!this.overview?.state?.modules?.[t.module];
        },

        async show(tab, force = false) {
            if (!force && tab === this.tab) return;
            if (!this.visible(TABS.find((t) => t.key === tab) || TABS[0])) tab = 'overview';
            if (tab === 'content') this.contentReady = this.loadContent();
            if (tab === 'pages') this.pagesReady = this.loadPages();
            this.tab = tab;
            replaceUrl(href('seo', { tab }));
            if (tab === 'robots') await this.loadRobots();
            if (tab === '404') await this.loadLinks();
            if (tab === 'settings') await this.loadIndexNow();
            if (tab === 'ai') await this.loadAi();
        },

        // ------------------------------------------------------------ yapay zekâ görünürlüğü
        async loadAi() {
            this.aiLoading = !this.ai;
            try {
                this.ai = await api.seoAi();
            } catch (error) {
                toast.error(error);
            } finally {
                this.aiLoading = false;
            }
        },

        /** Günlük çubuk yüksekliği (en yoğun güne göre yüzde). */
        aiBar(day) {
            const total = (d) => Object.keys(this.ai.purposes).reduce((sum, k) => sum + (d[k] || 0), 0);
            const max = Math.max(1, ...this.ai.daily.map(total));
            return Math.round((total(day) / max) * 100);
        },

        /** Bilinen bütün botlar, ziyaret sayısıyla (gelmeyenler 0); önce en çok gelen. */
        get aiRows() {
            if (!this.ai) return [];
            const seen = Object.fromEntries(this.ai.bots.map((b) => [b.name, b]));
            return this.ai.catalog
                .map((c) => ({ ...c, hits: 0, pages: 0, last_at: null, ...(seen[c.name] || {}) }))
                .sort((a, b) => b.hits - a.hits);
        },

        /** Varsayılan: yalnızca gelen botlar; hiç ziyaret yoksa ya da istenirse bütün katalog (robots durumu için). */
        get aiVisibleRows() {
            const rows = this.aiRows;
            return this.aiShowAll || !rows.some((b) => b.hits) ? rows : rows.filter((b) => b.hits);
        },

        get aiVisits() {
            return this.ai ? this.ai.totals.training + this.ai.totals.search + this.ai.totals.user : 0;
        },

        aiDayTitle(day) {
            const parts = Object.entries(this.ai.purposes).filter(([k]) => day[k]).map(([k, label]) => `${label}: ${day[k]}`);
            return `${fmt.date(day.day)} · ${parts.join(', ') || 'ziyaret yok'}`;
        },

        reloadGrid(id) {
            window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id } }));
        },

        // ------------------------------------------------------------ genel bakış ve ayarlar
        async loadOverview() {
            this.loading = !this.overview;
            try {
                this.overview = await api.seo();
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        async loadSettings() {
            try {
                const items = (await api.settingsGroup('SEO')).items;
                const values = Object.fromEntries(items.map((i) => [i.key, i.value]));
                this.settings = { items, values, original: { ...values }, errors: {}, saving: false };
            } catch (error) {
                toast.error(error);
            }
        },

        setting(key) {
            return this.settings.items.find((i) => i.key === key) || null;
        },

        /** Ayar grubunun alanları (Ayarlar sekmesindeki bölümler). */
        fields(keys) {
            return keys.map((k) => this.setting(k)).filter(Boolean);
        },

        hasOptions(item) {
            return item.options && Object.keys(item.options).length > 0;
        },

        get changedSettings() {
            return Object.keys(this.settings.values).filter((k) => !k.startsWith('seo.module.') && k !== 'seo.enabled' && this.settings.values[k] !== this.settings.original[k]);
        },

        async saveSettings(keys = this.changedSettings) {
            const st = this.settings;
            if (!keys.length || st.saving) return;
            st.saving = true;
            st.errors = {};
            try {
                const result = await api.saveSettings(Object.fromEntries(keys.map((k) => [k, st.values[k]])));
                st.errors = result.errors || {};
                const failed = Object.keys(st.errors);
                const items = result.items.filter((i) => i.group === 'SEO');
                const keep = Object.fromEntries(failed.map((k) => [k, st.values[k]]));
                st.items = items;
                st.values = { ...Object.fromEntries(items.map((i) => [i.key, i.value])), ...keep };
                st.original = Object.fromEntries(items.map((i) => [i.key, i.value]));
                if (failed.length) toast.error('Bazı ayarlar kaydedilemedi; işaretli alanları düzeltin.');
                else toast.success('SEO ayarları kaydedildi.');
                await this.loadOverview();
                this.$store.session.load().catch(() => {});
            } catch (error) {
                toast.error(error);
            } finally {
                st.saving = false;
            }
        },

        /** SEO'yu tamamen aç/kapat. */
        async toggleSeo() {
            const next = !this.enabled;
            if (!next && !(await confirmAction({
                title: 'SEO modülü kapatılsın mı?',
                message: 'Yönlendirmeler, 404 kaydı, başlık/açıklama düzenlemeleri, şema, doğrulama etiketleri, llms.txt ve IndexNow durur. Site temel etiketlerle yayına devam eder; ayarlar ve veriler silinmez, açınca geri gelir.',
                confirmText: 'SEO\'yu kapat',
                tone: 'danger',
            }))) return;
            this.settings.values['seo.enabled'] = next;
            await this.saveSettings(['seo.enabled']);
        },

        async toggleModule(key) {
            const k = `seo.module.${key}`;
            this.settings.values[k] = !this.settings.values[k];
            await this.saveSettings([k]);
        },

        async pickImage(key) {
            const current = this.settings.values[key];
            const picked = await this.$store.media.pick({ current: current ? [{ id: current }] : null });
            if (picked?.[0]) this.settings.values[key] = picked[0].id;
        },

        // ------------------------------------------------------------ içerik
        async loadContent() {
            try {
                this.content = (await api.seoContent()).items;
                this.reloadGrid('seo-content');
            } catch (error) {
                toast.error(error);
            }
        },

        onContent({ action, row }) {
            if (action === 'edit') navigate(row.edit);
            if (action === 'view' && row.url) window.open(row.url, '_blank', 'noopener');
        },

        // ------------------------------------------------------------ sayfalar
        async loadPages() {
            try {
                this.pages = (await api.seoPages()).items;
                this.reloadGrid('seo-pages');
            } catch (error) {
                toast.error(error);
            }
        },

        editPage(row) {
            this.pageEdit = { ...row, errors: {}, saving: false };
        },

        async pickPageImage() {
            const picked = await this.$store.media.pick({ current: this.pageEdit.image ? [this.pageEdit.image] : null });
            if (picked?.[0]) {
                this.pageEdit.image = picked[0];
                this.pageEdit.image_id = picked[0].id;
            }
        },

        async savePage() {
            const p = this.pageEdit;
            p.saving = true;
            try {
                this.pages = (await api.saveSeoPage({ route: p.route, locale: p.locale, title: p.title, description: p.description, noindex: p.noindex, image_id: p.image_id || 0 })).items;
                this.reloadGrid('seo-pages');
                this.pageEdit = null;
                toast.success('Sayfa SEO\'su kaydedildi; site hemen güncellendi.');
            } catch (error) {
                p.errors = error.details?.fields || {};
                toast.error(error);
            } finally {
                if (this.pageEdit) p.saving = false;
            }
        },

        /** Google arama sonucu önizlemesi (yaklaşık). */
        serp(title, description, path) {
            const site = this.overview?.urls?.sitemap?.replace(/\/sitemap\.xml$/, '') || '';
            return {
                title: title.length > 60 ? `${title.slice(0, 58)}…` : title,
                url: `${site.replace(/^https?:\/\//, '')}${path}`.replace(/\/$/, ''),
                description: description.length > 160 ? `${description.slice(0, 157)}…` : description,
            };
        },

        lengthTone(n, min, max) {
            return n === 0 ? 'text-muted' : n < min || n > max ? 'text-warning-ink' : 'text-success-ink';
        },

        // ------------------------------------------------------------ yönlendirmeler
        newRedirect(source = '', target = '') {
            this.redirect = { ...EMPTY_REDIRECT, source, target };
            this.redirectErrors = {};
        },

        editRedirect(row) {
            this.redirect = { ...row };
            this.redirectErrors = {};
        },

        async saveRedirect() {
            const r = this.redirect;
            this.redirectErrors = {};
            try {
                const body = { source: r.source, target: r.target, code: Number(r.code), is_regex: r.is_regex, active: r.active, note: r.note };
                if (r.id) await api.updateRedirect(r.id, body);
                else await api.createRedirect(body);
                toast.success(r.id ? 'Yönlendirme kaydedildi.' : 'Yönlendirme eklendi.');
                this.redirect = null;
                this.reloadGrid('seo-redirects');
                this.reloadGrid('seo-404');
                this.loadOverview();
            } catch (error) {
                this.redirectErrors = error.details?.fields || {};
                toast.error(error);
            }
        },

        async deleteRedirects(rows) {
            const ok = await confirmAction({
                title: rows.length > 1 ? `${rows.length} yönlendirme silinsin mi?` : `${rows[0].source} yönlendirmesi silinsin mi?`,
                message: 'Bu adresler artık yönlenmez; sayfa yoksa 404 verir.',
                confirmText: 'Sil',
                tone: 'danger',
            });
            if (!ok) return;
            for (const row of rows) await api.deleteRedirect(row.id).catch((e) => toast.error(e));
            toast.success('Silindi.');
            this.reloadGrid('seo-redirects');
        },

        onRedirect({ action, row, rows }) {
            if (action === 'delete') return this.deleteRedirects(rows || [row]);
            if (action === 'test') {
                this.tester.url = row.source;
                return this.test();
            }
        },

        async test() {
            if (!this.tester.url.trim()) return;
            try {
                this.tester.result = await api.testRedirect(this.tester.url.trim());
            } catch (error) {
                toast.error(error);
            }
        },

        async exportRedirects() {
            const response = await api.exportRedirects();
            const blob = await response.blob();
            const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `yonlendirmeler-${new Date().toISOString().slice(0, 10)}.csv` });
            a.click();
            URL.revokeObjectURL(a.href);
        },

        async importRedirects(files) {
            const file = files?.[0];
            if (!file) return;
            this.importing = true;
            try {
                const result = await api.importRedirects(await file.text());
                toast.success(`${result.added} yeni, ${result.updated} güncellendi.`);
                if (result.errors.length) toast.warning(`${result.errors.length} satır alınamadı: ${result.errors.slice(0, 3).join(' · ')}`);
                this.reloadGrid('seo-redirects');
            } catch (error) {
                toast.error(error);
            } finally {
                this.importing = false;
            }
        },

        // ------------------------------------------------------------ 404
        async onNotFound({ action, row, rows }) {
            const list = rows || [row];
            if (['redirect', 'redirectOther', 'suggested'].includes(action)) {
                this.newRedirect(row.path, action === 'suggested' ? row.suggestion : '');
                return;
            }
            if (action === 'ignore') {
                await api.notFoundStatus(list.map((r) => r.id), 'ignored').catch((e) => toast.error(e));
                toast.success('Yoksayıldı.');
            }
            if (action === 'delete') {
                if (!(await confirmAction({ title: `${list.length} kayıt silinsin mi?`, message: 'Adres yeniden 404 verirse listeye tekrar girer.', confirmText: 'Sil', tone: 'danger' }))) return;
                await api.notFoundDelete(list.map((r) => r.id)).catch((e) => toast.error(e));
                toast.success('Silindi.');
            }
            this.reloadGrid('seo-404');
            this.loadOverview();
        },

        // ------------------------------------------------------------ içerikteki iç bağlantılar
        async loadLinks(check = false) {
            this.links.loading = true;
            try {
                Object.assign(this.links, await (check ? api.checkSeoLinks() : api.seoLinks()));
                if (check) toast.success(this.links.items.length ? `${this.links.items.length} sorunlu bağlantı bulundu.` : 'İçerikteki bütün iç bağlantılar çalışıyor.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.links.loading = false;
            }
        },

        get movedLinks() {
            return this.links.items.filter((i) => i.problem === 'redirect').length;
        },

        /** Yönlenen bağlantılar içerikte son hedefle değişir (çeviriler eskimez). */
        async fixLinks() {
            const ok = await confirmAction({
                title: `${this.movedLinks} bağlantı güncellensin mi?`,
                message: 'Yönlenen bağlantılar içerikte yeni adresleriyle değiştirilir (ör. /tr/iletisim/ → /iletisim/). Kırık bağlantılar değişmez; onları kayıtta düzeltin.',
                confirmText: 'Güncelle',
            });
            if (!ok) return;
            this.links.loading = true;
            try {
                const result = await api.fixSeoLinks();
                Object.assign(this.links, result);
                toast.success(`${result.fixed} kayıttaki bağlantılar güncellendi.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.links.loading = false;
            }
        },

        /** Bağlantının bulunduğu kaydın düzenleyicisi (dil sekmesiyle). */
        linkSource(item) {
            return href('editor', { type: item.type, id: item.id }, item.type === 'posts' ? {} : { dil: item.locale });
        },

        // ------------------------------------------------------------ robots, llms
        async loadRobots() {
            try {
                Object.assign(this.robots, await api.robots(), { error: '' });
            } catch (error) {
                toast.error(error);
            }
        },

        async saveRobots() {
            this.robots.saving = true;
            this.robots.error = '';
            try {
                Object.assign(this.robots, await api.saveRobots(this.robots.extra));
                toast.success('robots.txt güncellendi.');
            } catch (error) {
                this.robots.error = error.details?.fields?.extra || error.message;
            } finally {
                this.robots.saving = false;
            }
        },

        // ------------------------------------------------------------ IndexNow
        async loadIndexNow() {
            try {
                this.indexnow = await api.indexNow();
            } catch {
                this.indexnow = null;
            }
        },

        async submitIndexNow() {
            try {
                const result = await api.submitIndexNow();
                this.indexnow.history = result.history;
                const msg = { sent: `${result.count} adres bildirildi.`, skipped: 'Site arama motorlarına kapalı; gönderilmedi.', failed: 'Gönderilemedi.' }[result.status] || result.status;
                result.status === 'sent' ? toast.success(msg) : toast.warning(msg);
            } catch (error) {
                toast.error(error);
            }
        },
    };
}
