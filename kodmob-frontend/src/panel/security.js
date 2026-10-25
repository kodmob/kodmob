/**
 * Güvenlik (/panel/guvenlik, `km_manage_security`) — WordPress'teki kodmob-security ve jwt-kodmob
 * yönetim sayfalarının karşılığı (kodmob-core Modules/Security). Sekmeler adreste (`/panel/guvenlik/oturumlar`):
 *
 * - Genel bakış: göstergeler (açık oturum, son 24 saatte hatalı giriş / engellenen istek, IP
 *   engeli, kilitli giriş), denetim maddeleri (ne yapılacağıyla) ve imza anahtarı (parmak izi,
 *   "Anahtarı yenile": herkesin oturumu kapanır).
 * - Oturumlar: herkesin açık oturumları (cihaz, IP, son etkinlik); tek tek ya da hepsi kapatılır.
 * - Güvenlik kayıtları: hatalı giriş, kilit, saldırı kalıbı, engelli IP… (ortak tablo).
 * - IP engelleri: elle (süreli / süresiz, aralık da olur) ve otomatik engeller.
 * - Kilitli girişler: hatalı denemeyle kilitlenen kullanıcı adı + IP çiftleri; kilit açılır.
 * - Ayarlar: oturum süreleri, deneme hakkı, kilit süresi, IP sınırı, WAF, otomatik engel, HSTS…
 */
import { href, replaceUrl } from './routes.js';
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { localSource } from '../ui/grid/local-source.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const TABS = [
    { key: 'overview', label: 'Genel bakış', icon: 'shield-check' },
    { key: 'sessions', label: 'Oturumlar', icon: 'monitor' },
    { key: 'events', label: 'Güvenlik kayıtları', icon: 'activity' },
    { key: 'blocks', label: 'IP engelleri', icon: 'ban' },
    { key: 'lockouts', label: 'Kilitli girişler', icon: 'lock' },
    { key: 'settings', label: 'Ayarlar', icon: 'cog' },
];

const AUDIT = {
    ok: { label: 'Tamam', cls: 'badge-success', icon: 'check-circle' },
    warning: { label: 'Dikkat', cls: 'badge-warning', icon: 'alert-circle' },
    danger: { label: 'Riskli', cls: 'badge-danger', icon: 'alert' },
};

export function kmSecurity(route = {}) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _onRoute: null,
        tabs: TABS,
        tab: TABS.some((t) => t.key === route.tab) ? route.tab : 'overview',
        audit: AUDIT,
        overview: null,
        loading: true,
        sessions: [],
        blocks: [],
        lockouts: [],
        blockForm: { ip: '', reason: '', hours: '' },
        blockErrors: {},
        settings: { items: [], values: {}, original: {}, errors: {}, saving: false },
        fmt,

        sessionsReady: null,
        blocksReady: null,
        sessionGrid: null,
        eventGrid: null,
        blockGrid: null,

        async init() {
            const self = this;
            this.sessionGrid = {
                id: 'security-sessions',
                source: async (q) => {
                    await self.sessionsReady;
                    return localSource(self.sessions, { search: ['user_name', 'device', 'ip'], facets: ['user_name'] })(q);
                },
                pageSize: 20,
                sort: { key: 'last_seen_at', order: 'desc' },
                exportName: 'oturumlar',
                empty: { title: 'Açık oturum yok', icon: 'monitor' },
                columns: [
                    { key: 'user_name', label: 'Kullanıcı', strong: true, sortable: true, filterBy: 'user_name', card: 'title' },
                    { key: 'device', label: 'Cihaz', cell: (r) => r.device + (r.current ? ' (bu cihaz)' : ''), card: 'subtitle' },
                    { key: 'ip', label: 'IP', mono: true, card: 'spec' },
                    { key: 'last_seen_at', label: 'Son etkinlik', format: 'relative', sortable: true, card: 'spec' },
                    { key: 'started_at', label: 'Giriş', format: 'dateTime', sortable: true, hideBelow: 'lg', card: 'meta' },
                    { key: 'expires_at', label: 'Bitiş', format: 'date', hideBelow: 'xl', hidden: true },
                ],
                filters: [{ key: 'q', type: 'search', placeholder: 'Kullanıcı, cihaz ya da IP' }],
                rowActions: [{ key: 'end', label: 'Kapat', tone: 'danger', when: (r) => !r.current }],
                bulkActions: [{ key: 'end', label: 'Seçilen oturumları kapat', icon: 'logout', tone: 'danger' }],
            };
            this.eventGrid = {
                id: 'security-events',
                source: (q) => api.securityEvents(q),
                pageSize: 50,
                pageSizes: [20, 50, 100],
                sort: { key: 'created_at', order: 'desc' },
                exportName: 'guvenlik-kayitlari',
                empty: { title: 'Güvenlik kaydı yok', text: 'Hatalı giriş, kilit ve engellenen istekler burada görünür.', icon: 'shield-check' },
                columns: [
                    { key: 'at', label: 'Zaman', format: 'dateTime', sortable: true, sortKey: 'created_at', card: 'meta' },
                    { key: 'type', label: 'Olay', badge: 'security', sortable: true, card: 'badge' },
                    { key: 'ip', label: 'IP', mono: true, sortable: true, filterBy: 'ip', card: 'title' },
                    { key: 'username', label: 'Kullanıcı adı', cell: (r) => r.user_name || r.username || '—', card: 'subtitle' },
                    { key: 'details', label: 'Ayrıntı', muted: true, class: 'max-w-md truncate', card: 'meta' },
                    { key: 'device', label: 'Cihaz', hideBelow: 'xl', muted: true },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'IP, kullanıcı adı ya da ayrıntı' },
                    { key: 'type', type: 'tabs', label: 'Olay', statusKind: 'security' },
                    { key: 'created_at', type: 'date-range', label: 'Tarih' },
                ],
                rowActions: [{ key: 'block', label: 'IP\'yi engelle', icon: 'ban', tone: 'danger', when: (r) => !!r.ip }],
            };
            this.blockGrid = {
                id: 'security-blocks',
                source: async (q) => {
                    await self.blocksReady;
                    return localSource(self.blocks, { search: ['ip', 'reason'], facets: ['source'] })(q);
                },
                pageSize: 20,
                sort: { key: 'created_at', order: 'desc' },
                exportName: 'ip-engelleri',
                empty: { title: 'IP engeli yok', text: 'Saldırı kalıbı ya da hatalı giriş eşiği aşılırsa IP otomatik engellenir.', icon: 'ban' },
                columns: [
                    { key: 'ip', label: 'IP / aralık', mono: true, strong: true, sortable: true, card: 'title' },
                    { key: 'source', label: 'Kaynak', badge: 'block', card: 'badge' },
                    { key: 'reason', label: 'Neden', muted: true, card: 'subtitle' },
                    { key: 'expires_at', label: 'Bitiş', cell: (r) => (r.expires_at ? fmt.dateTime(r.expires_at) : 'Süresiz'), sortable: true, card: 'spec' },
                    { key: 'hits', label: 'Engellenen istek', format: 'number', sortable: true, card: 'spec' },
                    { key: 'created_at', label: 'Eklenme', format: 'dateTime', sortable: true, hideBelow: 'lg', card: 'meta' },
                    { key: 'created_by_name', label: 'Ekleyen', hideBelow: 'xl', muted: true },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'IP ya da neden' },
                    { key: 'source', type: 'tabs', label: 'Kaynak', statusKind: 'block' },
                ],
                rowActions: [{ key: 'unblock', label: 'Engeli kaldır', tone: 'secondary' }],
                bulkActions: [{ key: 'unblock', label: 'Engelleri kaldır', icon: 'check-circle' }],
            };
            this._onRoute = (e) => e.detail?.tab && this.show(e.detail.tab);
            window.addEventListener('km-route', this._onRoute);
            await this.show(this.tab, true);
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
        },

        async show(tab, force = false) {
            if (!force && tab === this.tab) return;
            // Bellek içi tablolar ilk yüklemede veriyi bekler (sekme açılınca tablo kurulur).
            if (tab === 'sessions') this.sessionsReady = this.loadSessions();
            if (tab === 'blocks') this.blocksReady = this.loadBlocks();
            this.tab = tab;
            replaceUrl(href('security', { tab }));
            if (tab === 'overview') await this.loadOverview();
            if (tab === 'sessions') await this.sessionsReady;
            if (tab === 'blocks') await this.blocksReady;
            if (tab === 'lockouts') await this.loadLockouts();
            if (tab === 'settings') await this.loadSettings();
        },

        reloadGrid(id) {
            window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id } }));
        },

        // ------------------------------------------------------------ genel bakış
        async loadOverview() {
            this.loading = !this.overview;
            try {
                this.overview = await api.security();
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        get auditCounts() {
            const list = this.overview?.audit || [];
            return { ok: list.filter((a) => a.status === 'ok').length, issues: list.filter((a) => a.status !== 'ok').length };
        },

        async rotateKey() {
            const ok = await confirmAction({
                title: 'İmza anahtarı yenilensin mi?',
                message: 'Herkesin (sizin de) oturumu hemen kapanır ve yeniden giriş yapması gerekir. Anahtarın ya da bir oturumun ele geçtiğinden şüpheleniyorsanız kullanın.',
                confirmText: 'Anahtarı yenile',
                tone: 'danger',
            });
            if (!ok) return;
            try {
                await api.rotateKey();
                toast.success('Anahtar yenilendi; yeniden giriş yapın.');
                setTimeout(() => window.location.reload(), 1200);
            } catch (error) {
                toast.error(error);
            }
        },

        // ------------------------------------------------------------ oturumlar
        async loadSessions() {
            try {
                this.sessions = (await api.securitySessions()).items;
                this.reloadGrid('security-sessions');
            } catch (error) {
                toast.error(error);
            }
        },

        async endSession(row) {
            const ok = await confirmAction({ title: 'Oturum kapatılsın mı?', message: `${row.user_name} · ${row.device} (${row.ip}) panelden çıkar.`, confirmText: 'Oturumu kapat', tone: 'danger' });
            if (!ok) return;
            try {
                await api.endSession(row.id);
                toast.success('Oturum kapatıldı.');
                await this.loadSessions();
            } catch (error) {
                toast.error(error);
            }
        },

        async endSessions(rows) {
            const list = rows.filter((r) => !r.current);
            if (!list.length) return toast.info('Bu cihazın oturumu buradan kapatılmaz; Çıkış yapın.');
            const ok = await confirmAction({ title: `${list.length} oturum kapatılsın mı?`, message: 'Seçilen cihazlar panelden çıkar.', confirmText: 'Oturumları kapat', tone: 'danger' });
            if (!ok) return;
            for (const row of list) await api.endSession(row.id).catch(() => null);
            toast.success(`${list.length} oturum kapatıldı.`);
            await this.loadSessions();
        },

        async endAll() {
            const ok = await confirmAction({ title: 'Bütün oturumlar kapatılsın mı?', message: 'Bu cihaz dışındaki bütün cihazlarda herkes panelden çıkar.', confirmText: 'Hepsini kapat', tone: 'danger' });
            if (!ok) return;
            try {
                const res = await api.endAllSessions();
                toast.success(`${res.ended} oturum kapatıldı.`);
                await this.loadSessions();
            } catch (error) {
                toast.error(error);
            }
        },

        onSessionGrid({ action, row, rows }) {
            if (action === 'end') return rows ? this.endSessions(rows) : this.endSession(row);
        },

        // ------------------------------------------------------------ IP engelleri
        async loadBlocks() {
            try {
                this.blocks = (await api.ipBlocks()).items;
                this.reloadGrid('security-blocks');
            } catch (error) {
                toast.error(error);
            }
        },

        async block() {
            this.blockErrors = {};
            try {
                const body = { ip: this.blockForm.ip.trim(), reason: this.blockForm.reason, hours: Number(this.blockForm.hours) || 0 };
                await api.blockIp(body);
                toast.success(`${body.ip} engellendi.`);
                this.blockForm = { ip: '', reason: '', hours: '' };
                await this.loadBlocks();
            } catch (error) {
                this.blockErrors = error.details?.fields || {};
                toast.error(error);
            }
        },

        async blockFromEvent(row) {
            this.blockForm = { ip: row.ip, reason: row.type_label || 'Güvenlik kaydından', hours: '24' };
            await this.show('blocks');
            this.$nextTick(() => this.$root.querySelector('[data-block-ip]')?.focus());
        },

        async unblock(rows) {
            const ok = await confirmAction({
                title: rows.length > 1 ? `${rows.length} engel kaldırılsın mı?` : `${rows[0].ip} engeli kaldırılsın mı?`,
                message: 'Bu adreslerden gelen istekler yeniden kabul edilir.',
                confirmText: 'Engeli kaldır',
            });
            if (!ok) return;
            for (const row of rows) await api.unblockIp(row.id).catch((e) => toast.error(e));
            toast.success('Engel kaldırıldı.');
            await this.loadBlocks();
        },

        onBlockGrid({ action, row, rows }) {
            if (action === 'unblock') return this.unblock(rows || [row]);
        },

        onEventGrid({ action, row }) {
            if (action === 'block') return this.blockFromEvent(row);
        },

        // ------------------------------------------------------------ kilitli girişler
        async loadLockouts() {
            try {
                this.lockouts = (await api.lockouts()).items;
            } catch (error) {
                toast.error(error);
            }
        },

        async unlock(l) {
            try {
                await api.unlock({ username: l.username, ip: l.ip });
                toast.success('Kilit açıldı.');
                await this.loadLockouts();
            } catch (error) {
                toast.error(error);
            }
        },

        minutes(seconds) {
            return `${Math.max(1, Math.ceil(seconds / 60))} dk`;
        },

        // ------------------------------------------------------------ ayarlar
        async loadSettings() {
            try {
                const items = (await api.settingsGroup('Güvenlik')).items;
                const values = Object.fromEntries(items.map((i) => [i.key, i.value]));
                this.settings = { items, values, original: { ...values }, errors: {}, saving: false };
            } catch (error) {
                toast.error(error);
            }
        },

        hasOptions(item) {
            return item.options && Object.keys(item.options).length > 0;
        },

        get changedSettings() {
            return Object.keys(this.settings.values).filter((k) => this.settings.values[k] !== this.settings.original[k]);
        },

        async saveSettings() {
            const st = this.settings;
            if (!this.changedSettings.length || st.saving) return;
            st.saving = true;
            st.errors = {};
            try {
                const result = await api.saveSettings(Object.fromEntries(this.changedSettings.map((k) => [k, st.values[k]])));
                st.errors = result.errors || {};
                const failed = Object.keys(st.errors);
                const keep = Object.fromEntries(failed.map((k) => [k, st.values[k]]));
                const items = result.items.filter((i) => i.group === 'Güvenlik');
                st.items = items;
                st.values = { ...Object.fromEntries(items.map((i) => [i.key, i.value])), ...keep };
                st.original = Object.fromEntries(items.map((i) => [i.key, i.value]));
                failed.length ? toast.error('Bazı ayarlar kaydedilemedi; işaretli alanları düzeltin.') : toast.success('Güvenlik ayarları kaydedildi.');
            } catch (error) {
                toast.error(error);
            } finally {
                st.saving = false;
            }
        },

        async resetSettings() {
            const ok = await confirmAction({ title: 'Güvenlik ayarları varsayılana dönsün mü?', message: 'Oturum süreleri, deneme hakları ve engel ayarları ilk değerlerine döner.', confirmText: 'Varsayılana dön', tone: 'danger' });
            if (!ok) return;
            try {
                await api.settingsDefaults({ group: 'Güvenlik' });
                await this.loadSettings();
                toast.success('Güvenlik ayarları varsayılana döndü.');
            } catch (error) {
                toast.error(error);
            }
        },
    };
}
