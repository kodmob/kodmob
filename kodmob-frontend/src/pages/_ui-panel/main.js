/**
 * Panel bileşen vitrini (/_ui/panel/). Yalnızca geliştirme derlemesinde vardır (routes.config.js
 * → dev: true). Panelin stil paketiyle ve bileşenleriyle çalışır; sunucuya istek gitmez:
 * oturum deposu bütün yetkilerle sahte doldurulur, tablo bellek içi kaynaktan okur, genel Sil
 * ve Silinenler yerel kayıtla (grid `records`) denenir, canlı güncelleme düğmeyle tetiklenir.
 */
import { applyStoredTheme } from '@/ui/theme/theme.js';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import '@/styles/panel/index.css';

import { createToastStore } from '@/stores/toast.js';
import { createSessionStore } from '@/stores/session.js';
import { registerUi } from '@/ui/index.js';
import { kmGrid } from '@/ui/grid/grid.js';
import { localSource } from '@/ui/grid/local-source.js';
import { STATUS } from '@/domain/status.js';
import { fmt } from '@/domain/format.js';
import { confirmAction } from '@/ui/confirm/confirm.js';
import { PAGES, GROUPS } from '@/panel/meta.js';
import { generatePassword } from '@/panel/users.js';

applyStoredTheme();

const ALL_CAPS = [
    'km_access_panel', 'km_view_overview', 'km_manage_projects', 'km_manage_posts', 'km_publish_content', 'km_delete_content',
    'km_view_users', 'km_manage_users', 'km_restore_records', 'km_view_all_history', 'km_manage_security',
];

const TOC = [
    ['dugmeler', 'Düğmeler'], ['rozetler', 'Rozetler ve durumlar'], ['form', 'Form'], ['sekmeler', 'Sekmeler ve araç çubuğu'],
    ['gostergeler', 'Göstergeler'], ['kartlar', 'Kart, liste, boş durum'], ['tablo', 'Tablo (dfGrid)'], ['menu', 'Menü ve sayfalar'],
    ['katmanlar', 'Yan panel, onay, bildirim'],
];

const NAMES = ['Ayşe Demir', 'Mehmet Kaya', 'Zeynep Arslan', 'Can Yıldız', 'Elif Şahin', 'Burak Çelik', 'Selin Aydın', 'Emre Koç'];
const ROLES = [['admin', 'Yönetici'], ['editor', 'Editör'], ['author', 'Yazar'], ['sales', 'Satış']];

function makeRows() {
    return Array.from({ length: 48 }, (_, i) => {
        const [role, roleLabel] = ROLES[i % ROLES.length];
        return {
            id: i + 1,
            display_name: `${NAMES[i % NAMES.length]}${i >= NAMES.length ? ` ${Math.floor(i / NAMES.length) + 1}` : ''}`,
            username: `kullanici${i + 1}`,
            role,
            role_label: roleLabel,
            status: i % 7 === 3 ? 'passive' : 'active',
            sessions: i % 4,
            score: 40 + ((i * 37) % 60),
            last_login_at: new Date(Date.now() - i * 5.5 * 3600 * 1000).toISOString(),
            order: i,
        };
    });
}

function uiPanel() {
    let rows = makeRows();
    let hidden = [];
    const records = {
        async remove({ id, label }) {
            if (!(await confirmAction({ title: `${label} silinsin mi?`, message: 'Vitrin: kayıt yerel listeden kalkar, Silinenler\'den geri yüklenir.', confirmText: 'Sil', tone: 'danger' }))) return null;
            const row = rows.find((r) => r.id === id);
            rows = rows.filter((r) => r.id !== id);
            hidden.unshift({ id, object_id: id, object_type: 'demo', label, hidden_at: new Date().toISOString(), hidden_by_name: 'Vitrin', row });
            Alpine.store('toast').success(`${label} silindi.`);
            return { permanent: false };
        },
        async removeMany(items) {
            if (!(await confirmAction({ title: `${items.length} kayıt silinsin mi?`, message: 'Vitrin: kayıtlar yerel listeden kalkar.', confirmText: 'Hepsini Sil', tone: 'danger' }))) return null;
            for (const item of items) {
                const row = rows.find((r) => r.id === item.id);
                rows = rows.filter((r) => r.id !== item.id);
                hidden.unshift({ id: item.id, object_id: item.id, object_type: 'demo', label: item.label, hidden_at: new Date().toISOString(), hidden_by_name: 'Vitrin', row });
            }
            Alpine.store('toast').success(`${items.length} kayıt silindi.`);
            return { done: items.length, failed: [] };
        },
        async restore({ id, label }) {
            const h = hidden.find((x) => x.object_id === id);
            hidden = hidden.filter((x) => x.object_id !== id);
            if (h?.row) rows = [...rows, h.row].sort((a, b) => a.id - b.id);
            Alpine.store('toast').success(`${label} geri yüklendi.`);
            return true;
        },
        async hidden() {
            return hidden;
        },
    };

    return {
        toc: TOC.map(([id, label]) => ({ id, label })),
        status: STATUS,
        fmt,
        pages: PAGES,
        groups: GROUPS,
        tab: 'all',
        segment: 'pages',
        chips: ['web', 'ai'],
        toggle: true,
        drawer: false,
        password: generatePassword(),
        grouped: false,

        grid: {
            id: 'ui-users',
            source: (q) => localSource(rows, { search: ['display_name', 'username', 'role_label'], facets: ['status', 'role'], sums: ['sessions'] })(q),
            records,
            recordType: 'demo',
            pageSize: 10,
            sort: { key: 'display_name', order: 'asc' },
            exportName: 'vitrin-kullanicilar',
            live: { topics: ['demo'], auto: false },
            // Sürükle-bırak sıralama: "Sıra"ya göre sıralı ve bütün kayıtlar ekrandayken (ipucundaki bağlantı ayarlar).
            reorder: {
                sortKey: 'order',
                save: async (keys) => {
                    const position = new Map(keys.map((key, i) => [key, i]));
                    rows = rows.map((r) => ({ ...r, order: position.get(r.id) ?? r.order }));
                },
            },
            totals: [{ key: 'sessions', label: 'Açık oturum', format: 'number' }],
            groupBy: { key: 'role_label', totals: ['sessions'], when: () => false },
            empty: { title: 'Kullanıcı yok', icon: 'users' },
            columns: [
                { key: 'display_name', label: 'Ad', sortable: true, strong: true, card: 'title' },
                { key: 'username', label: 'Kullanıcı adı', mono: true, card: 'subtitle' },
                { key: 'role_label', label: 'Rol', sortable: true, filterBy: 'role', filterValue: 'role', card: 'spec' },
                { key: 'tags', label: 'Etiketler', chips: (r) => [{ label: r.role.toUpperCase(), tone: { admin: 'primary', editor: 'success', author: 'neutral', sales: 'warning' }[r.role] }, ...(r.sessions ? [] : [{ label: 'çevrimdışı', tone: 'muted' }])], hideBelow: 'xl' },
                { key: 'status', label: 'Durum', badge: 'user', card: 'badge' },
                { key: 'score', label: 'Puan', format: 'percent', sortable: true, card: 'spec', hideBelow: 'lg' },
                { key: 'sessions', label: 'Oturum', format: 'number', sortable: true, card: 'spec' },
                { key: 'last_login_at', label: 'Son giriş', format: 'relative', sortable: true, card: 'meta' },
                { key: 'order', label: 'Sıra', format: 'number', sortable: true, hidden: true, cell: (r) => String(r.order + 1) },
            ],
            filters: [
                { key: 'q', type: 'search', placeholder: 'Ad, kullanıcı adı ya da rol' },
                { key: 'status', type: 'tabs', label: 'Durum', statusKind: 'user' },
                { key: 'role', type: 'select', label: 'Rol', options: ROLES.map(([value, label]) => ({ value, label })) },
                { key: 'last_login_at', type: 'date-range', label: 'Son giriş' },
            ],
            rowActions: [
                { key: 'edit', label: 'Düzenle', primary: true, open: true },
                { key: 'deactivate', label: 'Pasife al', icon: 'ban', tone: 'danger', can: 'km_manage_users', when: (r) => r.status === 'active' },
                { key: 'activate', label: 'Aktifleştir', icon: 'check-circle', can: 'km_manage_users', when: (r) => r.status !== 'active' },
            ],
            bulkActions: [{ key: 'activate', label: 'Aktifleştir', icon: 'check-circle', can: 'km_manage_users' }],
        },

        newPassword() {
            this.password = generatePassword();
        },

        bumpLive() {
            const pulse = Alpine.store('pulse');
            pulse.topics = { ...pulse.topics, demo: (pulse.topics.demo || 0) + 1 };
            rows = [{ ...makeRows()[0], id: 1000 + rows.length, display_name: 'Yeni Kullanıcı', username: `yeni${rows.length}` }, ...rows];
        },

        toggleGroup() {
            this.grouped = !this.grouped;
            window.dispatchEvent(new CustomEvent('grid-group', { detail: { id: 'ui-users', on: this.grouped } }));
        },

        onGrid(event, bulk = false) {
            const { action, row, rows: selected } = event.detail;
            const list = bulk ? selected : [row];
            list.forEach((r) => (r.status = action === 'deactivate' ? 'passive' : 'active'));
            Alpine.store('toast').info(`${list.length} kullanıcı: ${action === 'deactivate' ? 'pasife alındı' : 'aktifleştirildi'} (vitrin).`);
            window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id: 'ui-users' } }));
        },

        async confirmDemo() {
            const ok = await confirmAction({ title: 'Kullanıcı pasife alınsın mı?', message: 'Panele giremez; açık oturumları hemen kapanır.', summary: [{ label: 'Kullanıcı', value: 'Ayşe Demir' }], confirmText: 'Pasife al', tone: 'danger' });
            Alpine.store('toast').info(ok ? 'Onaylandı (vitrin).' : 'Vazgeçildi.');
        },

        toastDemo(type) {
            const toast = Alpine.store('toast');
            if (type === 'action') return toast.success('Yazı silindi.', { action: { label: 'Geri Al', run: () => toast.info('Geri alındı (vitrin).') } });
            toast[type]({ success: 'Kaydedildi.', info: 'Liste güncellendi.', warning: 'Kaydedilmemiş değişiklik var.', error: 'Sunucuya ulaşılamadı.' }[type]);
        },
    };
}

Alpine.plugin(collapse);
Alpine.store('toast', createToastStore());
const session = createSessionStore();
// Sahte oturum: depoya yazılmaz (gerçek panel oturumunu bozmasın).
session.me = { display_name: 'Vitrin', email: 'vitrin@kodmob.test', role: { slug: 'admin', label: 'Yönetici' }, capabilities: ALL_CAPS, is_admin: true, menu: null };
Alpine.store('session', session);
Alpine.store('pulse', { topics: {}, version(topic) { return this.topics[topic] || 0; } });
registerUi(Alpine);
Alpine.data('kmGrid', kmGrid);
Alpine.data('uiPanel', uiPanel);
window.Alpine = Alpine;
Alpine.start();
