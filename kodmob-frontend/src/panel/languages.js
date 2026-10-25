/**
 * Çoklu Dil (/panel/diller; done-coklu-dil-raporu.md §5). Sekmeler adreste (`/panel/diller/saglik`):
 *
 * - Genel bakış: ana anahtar "Çoklu dil" (kapalıyken tek dilli mod), öneri bandı, dil başına
 *   içerik ve metin durumu, sağlık özeti.
 * - Diller: kayıt (kodmob-core `languages`): adlar, hreflang, Open Graph, yedek dil, yayına alma,
 *   sıralama, yeni dil (pasif), silme (pasif ve içeriksiz). Önek ve varsayılan dil derlemeden
 *   gelir (karar A); burada yalnızca gösterilir.
 * - Metinler: sayfa metinleri (ayrı görünüm, panel/texts.js; `km_manage_texts`).
 * - Adresler: derlemedeki rota × dil tablosu, eski adresler (301) ve adres geçmişi (salt okunur).
 * - Ziyaretçi: 404 sayfasındaki "sık ziyaret edilenler" (dil başına).
 * - Sağlık: sorun listesi; her satırda düzeltme bağlantısı.
 *
 * `km_manage_languages` yoksa (yalnızca metin yetkisi) sayfa Metinler'e açılır.
 */
import { href, navigate, replaceUrl } from './routes.js';
import { api } from './api.js';
import { typeByRecord } from './meta.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { fieldErrors } from '../domain/errors.js';

export const LANGUAGE_TABS = [
    { key: 'overview', label: 'Genel bakış', icon: 'grid', can: 'km_manage_languages' },
    { key: 'list', label: 'Diller', icon: 'globe', can: 'km_manage_languages' },
    { key: 'texts', label: 'Metinler', icon: 'book', can: 'km_manage_texts', route: 'texts' },
    { key: 'urls', label: 'Adresler', icon: 'link', can: 'km_manage_languages' },
    { key: 'visitor', label: 'Ziyaretçi', icon: 'users', can: 'km_manage_languages' },
    { key: 'health', label: 'Sağlık', icon: 'activity', can: 'km_manage_languages' },
];

/** Sekme çubuğu (Çoklu Dil ve Metinler görünümlerinde ortak; _languages-tabs.njk). */
export function kmLanguageTabs() {
    return {
        tabs: LANGUAGE_TABS,
        /** Seçili sekme: Metinler ayrı rota; diğerlerinde Çoklu Dil görünümünün sekmesi (üst kapsamdaki `tab`). */
        selected(r) {
            return r?.view === 'texts' ? 'texts' : this.tab || 'overview';
        },
        tabHref(t) {
            return t.route ? href(t.route) : href('languages', { tab: t.key === 'overview' ? null : t.key });
        },
        visible(t) {
            return this.$store.session.can(t.can);
        },
    };
}

const LEVELS = {
    error: { label: 'Hata', badge: 'badge-danger', icon: 'alert-circle', tone: 'text-danger' },
    warning: { label: 'Uyarı', badge: 'badge-warning', icon: 'alert', tone: 'text-warning' },
    info: { label: 'Bilgi', badge: 'badge-neutral', icon: 'info', tone: 'text-muted' },
};

const blankLanguage = () => ({ code: '', name: '', native: '', short: '', hreflang: '', og: '', intl: '', fallback: '', flag: '' });
const EDITABLE = ['name', 'native', 'short', 'hreflang', 'og', 'intl', 'fallback', 'flag'];

export function kmLanguages(route = {}) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _onRoute: null,
        tab: 'overview',
        levels: LEVELS,
        fmt,
        overview: null,
        health: null,
        urls: null,
        list: null,
        settings: { values: {}, saving: false },
        popular: {},
        popularSaving: false,
        editing: null,
        editErrors: {},
        adding: null,
        addErrors: {},
        busy: false,

        /** Adres geçmişi satırı → kaydın düzenleyicisi ("Proje #3", "Yazı #12"). */
        historyLink(h) {
            const [type, t] = typeByRecord(h.type) || [];
            return { href: type ? href('editor', { type, id: h.record_id }) : null, label: `${t?.singular || h.type} #${h.record_id}` };
        },

        async init() {
            if (!this.$store.session.can('km_manage_languages')) {
                navigate(href('texts'), { replace: true });
                return;
            }
            this._onRoute = (e) => this.show(e.detail?.tab || 'overview');
            window.addEventListener('km-route', this._onRoute);
            await this.loadSettings();
            await this.show(route.tab || 'overview', true);
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
        },

        async show(tab, force = false) {
            if (!force && tab === this.tab) return;
            if (!LANGUAGE_TABS.some((t) => t.key === tab && !t.route)) tab = 'overview';
            this.tab = tab;
            replaceUrl(href('languages', { tab: tab === 'overview' ? null : tab }));
            try {
                if (tab === 'overview') this.overview = await api.languagesOverview();
                if (tab === 'list') await this.loadList();
                if (tab === 'urls') this.urls = await api.languagesUrls();
                if (tab === 'visitor') this.popular = (await api.languagesVisitor()).popular || {};
                if (tab === 'health') this.health = (await api.languagesHealth()).items;
            } catch (error) {
                toast.error(error);
            }
        },

        // ------------------------------------------------------------ ayarlar (ana anahtar, bant)
        async loadSettings() {
            try {
                const items = (await api.settingsGroup('Çoklu dil')).items;
                this.settings.values = Object.fromEntries(items.map((i) => [i.key, i.value]));
            } catch (error) {
                toast.error(error);
            }
        },

        get enabled() {
            return this.settings.values['i18n.enabled'] !== false;
        },

        async toggleSetting(key) {
            const next = !this.settings.values[key];
            if (key === 'i18n.enabled' && !next) {
                const ok = await confirmAction({
                    title: 'Tek dilli moda geçilsin mi?',
                    message: 'Yalnızca varsayılan dil yayında kalır; diğer dillerin adresleri varsayılan dile yönlenir, dil seçici ve hreflang kalkar. İçerik ve çeviriler silinmez, açınca geri gelir.',
                    confirmText: 'Tek dilli moda geç',
                    tone: 'danger',
                });
                if (!ok) return;
            }
            this.settings.saving = true;
            try {
                await api.saveSettings({ [key]: next });
                this.settings.values = { ...this.settings.values, [key]: next };
                toast.success(key === 'i18n.enabled' ? (next ? 'Çoklu dil açıldı.' : 'Tek dilli mod açıldı.') : (next ? 'Dil önerisi bandı açıldı.' : 'Dil önerisi bandı kapandı.'));
            } catch (error) {
                toast.error(error);
                return;
            } finally {
                this.settings.saving = false;
            }
            // Panelin dil listesi ve özet tablo arkadan yenilenir (anahtar beklemez).
            await this.$store.session.load().catch(() => {});
            if (this.tab === 'overview') this.overview = await api.languagesOverview().catch(() => this.overview);
        },

        // ------------------------------------------------------------ genel bakış
        typesOf(o) {
            return Object.entries(o?.types || {});
        },

        coverage(l, type) {
            const c = l.content[type] || { published: 0, missing: 0 };
            const total = c.published + c.missing;
            return total ? Math.round((c.published / total) * 100) : 100;
        },

        // ------------------------------------------------------------ diller
        async loadList() {
            this.list = await api.languages();
        },

        builtIn(code) {
            return !this.list?.built || this.list.built.includes(code);
        },

        startEdit(l) {
            this.editing = Object.fromEntries([['code', l.code], ...EDITABLE.map((k) => [k, l[k] ?? ''])]);
            this.editErrors = {};
        },

        async saveEdit() {
            this.busy = true;
            this.editErrors = {};
            try {
                const body = Object.fromEntries(EDITABLE.map((k) => [k, this.editing[k]]));
                await api.updateLanguage(this.editing.code, body);
                this.editing = null;
                await Promise.all([this.loadList(), this.$store.session.load()]);
                toast.success('Dil kaydedildi.');
            } catch (error) {
                this.editErrors = fieldErrors(error);
                if (!Object.keys(this.editErrors).length) toast.error(error);
            } finally {
                this.busy = false;
            }
        },

        async toggleActive(l) {
            if (l.default) return;
            const next = !l.active;
            if (next && !this.builtIn(l.code)) {
                toast.info(`${l.name} sitenin derlemesinde yok; önce dil derlemeye eklenmeli (build).`);
                return;
            }
            if (!next) {
                const ok = await confirmAction({
                    title: `${l.name} yayından kaldırılsın mı?`,
                    message: 'Bu dilin adresleri varsayılan dile yönlenir; site haritası, hreflang ve dil seçiciden çıkar. İçerik silinmez.',
                    confirmText: 'Yayından kaldır',
                    tone: 'danger',
                });
                if (!ok) return;
            }
            try {
                await api.updateLanguage(l.code, { active: next });
                await Promise.all([this.loadList(), this.$store.session.load()]);
                toast.success(next ? `${l.name} yayında.` : `${l.name} yayından kaldırıldı.`);
            } catch (error) {
                toast.error(error);
            }
        },

        async move(index, step) {
            const items = [...this.list.items];
            const to = index + step;
            if (to < 0 || to >= items.length) return;
            [items[index], items[to]] = [items[to], items[index]];
            try {
                await api.orderLanguages(items.map((l) => l.code));
                await Promise.all([this.loadList(), this.$store.session.load()]);
            } catch (error) {
                toast.error(error);
            }
        },

        startAdd() {
            this.adding = blankLanguage();
            this.addErrors = {};
        },

        async saveAdd() {
            this.busy = true;
            this.addErrors = {};
            try {
                const body = Object.fromEntries(Object.entries(this.adding).filter(([, v]) => v !== ''));
                const created = await api.createLanguage(body);
                this.adding = null;
                await Promise.all([this.loadList(), this.$store.session.load()]);
                toast.success(`${created.name} eklendi (pasif). İçerikte çevirebilir, derlemeye eklenince yayına alabilirsiniz.`);
            } catch (error) {
                this.addErrors = fieldErrors(error);
                if (!Object.keys(this.addErrors).length) toast.error(error);
            } finally {
                this.busy = false;
            }
        },

        async remove(l) {
            const ok = await confirmAction({ title: `${l.name} silinsin mi?`, message: 'Yalnızca pasif ve içeriği olmayan dil silinebilir.', confirmText: 'Sil', tone: 'danger' });
            if (!ok) return;
            try {
                await api.deleteLanguage(l.code);
                await Promise.all([this.loadList(), this.$store.session.load()]);
                toast.success(`${l.name} silindi.`);
            } catch (error) {
                toast.error(error);
            }
        },

        // ------------------------------------------------------------ ziyaretçi
        languageList() {
            return this.$store.session.me?.languages || [];
        },

        linksOf(code) {
            if (!this.popular[code]) this.popular = { ...this.popular, [code]: [] };
            return this.popular[code];
        },

        addLink(code) {
            const list = this.linksOf(code);
            if (list.length < 6) list.push({ label: '', url: '/' });
        },

        async savePopular() {
            this.popularSaving = true;
            try {
                this.popular = (await api.saveLanguagesVisitor(this.popular)).popular || {};
                toast.success('404 bağlantıları kaydedildi.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.popularSaving = false;
            }
        },

        // ------------------------------------------------------------ sağlık
        linkOf(item) {
            const l = item.link;
            if (!l) return null;
            return l.route === 'languages' && !l.params?.tab ? href('languages') : href(l.route, l.params || {}, l.query || {});
        },

        get healthCounts() {
            const items = this.health || [];
            return { error: items.filter((i) => i.level === 'error').length, warning: items.filter((i) => i.level === 'warning').length, info: items.filter((i) => i.level === 'info').length };
        },
    };
}
