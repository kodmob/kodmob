/**
 * İşlem Geçmişi (/panel/islem-gecmisi; herkesin: /panel/islem-gecmisi/tumu): paneldeki her işlemin kaydı (kodmob-core Modules/History,
 * genel işlem kaydı Core\Activity). Sayfa iki sekmedir; ilk açılışta **İşlemlerim** gelir:
 *
 * - İşlemlerim: yalnızca kullanıcının kendi işlemleri (herkes).
 * - Tüm İşlemler: `km_view_all_history` olan herkesinkini, diğerleri yetkilerinin ilgili
 *   olduğu bölümün (içerik, talepler…) işlemlerini görür. Bölümü olmayanda sekme görünmez.
 *
 * Tablo ortak tablodur (kmGrid): bölüm sekmeleri sayılarıyla, kullanıcı ve tarih süzgeci,
 * gün başlıklarıyla gruplama, CSV. Satırdaki "Detay" yan panelde ayrıntıyı ve aynı kaydın
 * diğer işlemlerini gösterir. `/panel/islem-gecmisi/12` doğrudan o işlemi açar. Yeni işlem olunca
 * (nabız `events`) liste kendiliğinden yenilenir.
 */
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { pageForUrl } from './meta.js';
import { href, navigate, replaceUrl } from './routes.js';

/** Kaydın sayfası açılabilir mi (yetki): açılamıyorsa "Kayda git" gösterilmez. */
const canOpen = (url) => {
    const page = pageForUrl(url);
    return !!page && window.Alpine.store('session').can(page.can);
};

const SCOPES = ['mine', 'all'];

function dayLabel(iso) {
    if (!iso) return '—';
    const day = String(iso).slice(0, 10);
    const today = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const key = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    if (day === key(today)) return 'Bugün';
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    if (day === key(yesterday)) return 'Dün';
    return fmt.longDate(`${day}T12:00:00`);
}

export function kmHistory(route = {}) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _onRoute: null,
        scope: route.tab === 'all' ? 'all' : 'mine',
        options: { all: { available: false }, groups: [], users: [] },
        stats: null,
        grid: null,
        detail: null,
        detailLoading: false,
        fmt,

        init() {
            this.grid = this.gridConfig();
            api.historyOptions().then((o) => {
                this.options = o;
                if (this.scope === 'all' && !o.all.available) this.setScope('mine');
            }).catch(() => {});
            this.loadStats();
            if (route.id) this.show({ id: route.id });
            // Aynı sayfada adres değişti (menüden İşlemlerim, bağlantıdan bir işlem).
            this._onRoute = (e) => {
                this.setScope(e.detail?.tab === 'all' ? 'all' : 'mine', { url: false });
                if (e.detail?.id) this.show({ id: e.detail.id });
            };
            window.addEventListener('km-route', this._onRoute);
            this.$watch(() => this.$store.pulse.version('events'), () => this.loadStats());
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
        },

        setScope(scope, { url = true } = {}) {
            if (this.scope === scope) return;
            this.scope = scope;
            if (url) replaceUrl(href('history', { tab: scope === 'all' ? 'all' : null }));
            window.dispatchEvent(new CustomEvent('grid-filter', { detail: { id: 'history', values: {} } }));
            this.loadStats();
        },

        async loadStats() {
            try {
                this.stats = await api.historyStats({ scope: this.scope });
            } catch {
                this.stats = null;
            }
        },

        get change() {
            const t = this.stats?.total;
            if (!t || !t.previous) return null;
            const diff = Math.round(((t.count - t.previous) / t.previous) * 100);
            return { diff, text: `${diff > 0 ? '+' : ''}${diff}% önceki 7 güne göre`, up: diff >= 0 };
        },

        /** En çok işlem olan üç bölüm. */
        get topGroups() {
            const groups = this.stats?.groups || {};
            const labels = Object.fromEntries((this.options.groups || []).map((g) => [g.value, g.label]));
            return Object.entries(groups).sort((a, b) => b[1] - a[1]).slice(0, 3).map(([key, n]) => ({ key, label: labels[key] || key, n }));
        },

        gridConfig() {
            const self = this;
            return {
                id: 'history',
                syncUrl: true,
                source: (q) => api.history({ ...q, scope: self.scope }),
                pageSize: 50,
                pageSizes: [20, 50, 100],
                sort: { key: 'created_at', order: 'desc' },
                // Kullanıcılar > Son işlemler > "Bütün işlemleri": /panel/islem-gecmisi/tumu?user_id=4
                values: route.query?.user_id ? { user_id: String(route.query.user_id) } : {},
                live: { topics: ['events'], auto: true },
                exportName: 'islem-gecmisi',
                columnsVersion: 1,
                groupBy: { label: (row) => dayLabel(row.at) },
                empty: { title: 'Henüz işlem yok', text: 'Panelde yapılan her kayıt, değişiklik ve silme burada görünür.', icon: 'history' },
                columns: [
                    { key: 'at', label: 'Saat', format: 'time', sortable: true, sortKey: 'created_at', card: 'meta', exportValue: (r) => fmt.dateTime(r.at) },
                    { key: 'title', label: 'İşlem', strong: true, card: 'title' },
                    { key: 'subject', label: 'Kayıt', cell: (r) => r.subject?.label || '—', card: 'subtitle', exportValue: (r) => r.subject?.label || '' },
                    { key: 'text', label: 'Ayrıntı', muted: true, hideBelow: 'lg', card: 'meta', class: 'max-w-md truncate' },
                    { key: 'user_name', label: 'Kullanıcı', filterBy: 'user_id', card: 'spec' },
                    { key: 'group_label', label: 'Bölüm', hideBelow: 'xl', muted: true },
                    { key: 'ip', label: 'IP', mono: true, hidden: true, hideBelow: 'xl' },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'İşlem, kayıt adı ya da ayrıntı' },
                    { key: 'group', type: 'tabs', label: 'Bölüm', options: [], loadOptions: async () => (await api.historyOptions()).groups },
                    { key: 'user_id', type: 'select', label: 'Kullanıcı', options: [], loadOptions: async () => (await api.historyOptions()).users },
                    { key: 'created_at', type: 'date-range', label: 'Tarih' },
                ],
                rowActions: [
                    { key: 'detail', label: 'Detay', primary: true, open: true },
                    { key: 'subject', label: 'Kayda git', icon: 'external', when: (r) => !!r.subject?.url && canOpen(r.subject.url) },
                ],
            };
        },

        onAction({ action, row }) {
            if (action === 'subject' && row.subject?.url) navigate(row.subject.url);
        },

        async show(row) {
            this.detail = { id: row.id };
            this.detailLoading = true;
            try {
                this.detail = await api.historyItem(row.id);
            } catch (error) {
                toast.error(error);
                this.detail = null;
            } finally {
                this.detailLoading = false;
            }
        },

        /** Ayrıntı tablosu: değişen alanlar (önce → sonra) ya da kayıttaki değerler. */
        get changes() {
            const changes = this.detail?.payload?.changes;
            if (!changes) return [];
            return Object.values(changes).map((c) => ({ label: c.label, from: this.display(c.from), to: this.display(c.to) }));
        },

        get fields() {
            return this.detail?.payload?.fields || [];
        },

        canOpen,

        display(value) {
            if (value === true) return 'açık';
            if (value === false) return 'kapalı';
            return value === '' || value === null || value === undefined ? '—' : String(value);
        },
    };
}
