/**
 * Genel bakış: "Sıradaki adımlar" (siteyi yayına hazırlarken sırayla; kodmob-core Core\Setup\Onboarding, adımlar
 * gerçek veriden işaretlenir) ve kartlar (widget; kodmob-core Core\Dashboard\Widgets: modüllerin `widgets()`'ı).
 * Kişi kartları sürükler ya da oklarla sıralar, boyutunu (küçük / orta / geniş) seçer, gizler; düzen sunucuda kişiye
 * saklanır (`/me/dashboard`), "Varsayılana dön" siler. Kart türleri: `stats` (sayılar), `list` (satırlar), `custom`
 * (anahtarına özel şablon, ör. domains). Adımlar bitince kart kısalır; "Gizle" bu tarayıcıda, projeye göre hatırlanır.
 */
import { api } from './api.js';
import { TYPES, relative } from './meta.js';
import { href } from './routes.js';
import { toast } from '../ui/toast.js';
import { storage } from '../services/storage.js';

const HIDDEN = 'km_steps_hidden';

export const SIZES = [
    { key: 'sm', label: 'Küçük' },
    { key: 'md', label: 'Orta' },
    { key: 'lg', label: 'Geniş' },
];

/** Satır ve sayı rozetinin tonu → rozet sınıfı. */
const BADGE = { success: 'badge-success', warning: 'badge-warning', danger: 'badge-danger', neutral: 'badge-neutral', primary: 'badge-primary', info: 'badge-primary' };

export function kmOverview() {
    return {
        loading: true,
        data: null,
        relative,
        api,
        stepsHidden: false,
        sizes: SIZES,
        widgets: [],
        layout: [],
        custom: false,
        widgetsLoading: true,
        editing: false,
        dragging: null,
        over: null,
        saveTimer: null,

        async init() {
            this.stepsHidden = (storage.getJSON(HIDDEN, []) || []).includes(this.projectKey);
            this.loadWidgets();
            try {
                this.data = await api.overview();
                if (this.data.briefs) this.$store.panel.newBriefs = this.data.briefs.new;
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        async loadWidgets() {
            try {
                this.apply(await api.dashboard());
            } catch (error) {
                toast.error(error);
            } finally {
                this.widgetsLoading = false;
            }
        },

        apply(res) {
            this.widgets = res.widgets || [];
            this.layout = res.layout || [];
            this.custom = !!res.custom;
        },

        /** Düzende görünen kartlar, sırasıyla (tanım + boyut). */
        get shown() {
            const byKey = Object.fromEntries(this.widgets.map((w) => [w.key, w]));
            return this.layout.filter((l) => !l.hidden && byKey[l.key]).map((l) => ({ ...byKey[l.key], size: l.size }));
        },

        get hiddenWidgets() {
            const hidden = new Set(this.layout.filter((l) => l.hidden).map((l) => l.key));
            return this.widgets.filter((w) => hidden.has(w.key));
        },

        // ------------------------------------------------------------ düzenleme
        setSize(key, size) {
            this.layout = this.layout.map((l) => (l.key === key ? { ...l, size } : l));
            this.save();
        },

        hide(key) {
            this.layout = this.layout.map((l) => (l.key === key ? { ...l, hidden: true } : l));
            this.save();
        },

        show(key) {
            const item = this.layout.find((l) => l.key === key);
            this.layout = [...this.layout.filter((l) => l.key !== key), { ...item, hidden: false }];
            // Gizliyken verisi hesaplanmadı: kaydedince sunucu getirir.
            this.save(true);
        },

        /** Görünen kartlar arasında bir adım öne / arkaya. */
        move(key, step) {
            const visible = this.layout.filter((l) => !l.hidden).map((l) => l.key);
            const from = visible.indexOf(key);
            const to = from + step;
            if (from < 0 || to < 0 || to >= visible.length) return;
            this.reorder(key, visible[to]);
        },

        /** `key` kartını `target`'ın yerine taşır (sürükle-bırak ve oklar). */
        reorder(key, target) {
            if (!key || key === target) return;
            const list = [...this.layout];
            const item = list.splice(list.findIndex((l) => l.key === key), 1)[0];
            const at = list.findIndex((l) => l.key === target);
            const fromBefore = this.layout.findIndex((l) => l.key === key) < this.layout.findIndex((l) => l.key === target);
            list.splice(fromBefore ? at + 1 : at, 0, item);
            this.layout = list;
            this.save();
        },

        dragStart(key, event) {
            this.dragging = key;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', key);
        },

        drop(target) {
            this.reorder(this.dragging, target);
            this.dragging = null;
            this.over = null;
        },

        /** Kaydeder (kısa gecikmeyle; art arda değişiklik tek istek). `now`: hemen (gizli kart açıldı, veri gerek). */
        save(now = false) {
            clearTimeout(this.saveTimer);
            const run = async () => {
                try {
                    this.apply(await api.dashboardSave({ items: this.layout }));
                } catch (error) {
                    toast.error(error);
                }
            };
            if (now) run();
            else this.saveTimer = setTimeout(run, 400);
        },

        async reset() {
            clearTimeout(this.saveTimer);
            try {
                this.apply(await api.dashboardReset());
                toast.success('Kartlar varsayılan düzene döndü.');
            } catch (error) {
                toast.error(error);
            }
        },

        // ------------------------------------------------------------ kart içeriği
        link(item) {
            if (item.url) return item.url;
            return item.route ? href(item.route, item.params || {}) : null;
        },

        badgeClass(badge) {
            return BADGE[badge?.[1]] || 'badge-neutral';
        },

        when(time) {
            return time ? relative(String(time).replace(' ', 'T')) : '';
        },

        /** İçerik türleri (üstteki "Yeni …" düğmesi için). */
        get types() {
            return Object.fromEntries(Object.entries(TYPES).filter(([key]) => !this.data || this.data.counts?.[key]));
        },

        /** Üstteki "Yeni …" düğmesi: projenin yetkisi olan ilk içerik türü (kodmob.com: proje); blog ayrı düğme. */
        get firstType() {
            const entry = Object.entries(TYPES).find(([key, t]) => key !== 'posts' && this.$store.session.can(t.can));
            return entry ? { key: entry[0], ...entry[1] } : null;
        },

        get projectKey() {
            return this.$store.session.me?.project?.key || 'main';
        },

        /** Sıradaki adımlar (yetkiye göre; sunucu verir). */
        get steps() {
            return this.data?.steps || [];
        },

        get stepsDone() {
            return this.steps.filter((s) => s.done).length;
        },

        /** Sıradaki iş: yapılmamış ilk adım. */
        get nextStep() {
            return this.steps.find((s) => !s.done) || null;
        },

        /** Adımın ekranı; ayar adımında alanın kendisine (`#ayar=`: sekmesi açılır, alan vurgulanır). */
        stepHref(step) {
            const url = href(step.route, { ...step.params, ...(step.tab ? { tab: step.tab } : {}) });
            return step.anchor ? `${url}#ayar=${encodeURIComponent(step.anchor)}` : url;
        },

        toggleSteps(hidden) {
            const list = (storage.getJSON(HIDDEN, []) || []).filter((k) => k !== this.projectKey);
            storage.setJSON(HIDDEN, hidden ? [...list, this.projectKey] : list);
            this.stepsHidden = hidden;
        },

        get greeting() {
            const hour = new Date().getHours();
            const name = (this.$store.panel.user?.name || '').split(' ')[0];
            const hello = hour < 6 ? 'İyi geceler' : hour < 12 ? 'Günaydın' : hour < 18 ? 'İyi günler' : 'İyi akşamlar';
            return name ? `${hello}, ${name}` : hello;
        },
    };
}
