/**
 * Bildirimler sayfası (/panel/bildirimler): kişinin bütün bildirimleri, sayfa sayfa. Üstte Tümü / Okunmamış /
 * Okunmuş ve türe göre süzgeç (adreste `?durum=okunmamis&tur=brief`); tek tek ya da seçerek okundu / okunmadı say,
 * sil; okunmuşların hepsini temizle. Zil (notifications.js) aynı veriyi kullanır: nabzın `notification` konusu
 * değişince liste yenilenir. Bildirimler 90 gün saklanır (NotificationService::KEEP_DAYS).
 */
import { href, navigate, replaceUrl } from './routes.js';
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

/** Adresteki durum adı ↔ sunucunun süzgeci. */
const STATUS = { okunmamis: 'unread', okunmus: 'read' };
const STATUS_SLUG = Object.fromEntries(Object.entries(STATUS).map(([slug, key]) => [key, slug]));

export const STATUS_TABS = [
    { key: '', label: 'Tümü' },
    { key: 'unread', label: 'Okunmamış' },
    { key: 'read', label: 'Okunmuş' },
];

export function kmNotificationsPage(route = {}) {
    return {
        fmt,
        statusTabs: STATUS_TABS,
        status: STATUS[route.query?.durum] || '',
        kind: /^[a-z0-9_]+$/.test(route.query?.tur || '') ? route.query.tur : '',
        page: Math.max(1, Number(route.query?.sayfa) || 1),
        items: [],
        kinds: [],
        total: 0,
        pages: 0,
        unread: 0,
        selected: [],
        loading: false,
        loaded: false,
        busy: false,

        init() {
            this.load();
            this.$watch(() => this.$store.pulse.version('notification'), () => this.load(true));
        },

        get allSelected() {
            return this.items.length > 0 && this.items.every((n) => this.selected.includes(n.id));
        },

        get emptyText() {
            if (this.status === 'unread') return 'Okunmamış bildiriminiz yok.';
            if (this.status || this.kind) return 'Bu süzgece uyan bildirim yok.';
            return 'Henüz bildiriminiz yok. Yeni talep, sipariş ya da biten bir iş olunca burada görünür.';
        },

        async load(quiet = false) {
            if (this.loading) return;
            this.loading = !quiet;
            try {
                const params = { page: this.page, per_page: 20 };
                if (this.status) params.status = this.status;
                if (this.kind) params.kind = this.kind;
                const res = await api.notificationsPage(params);
                // Sayfa boşaldıysa (son öğeler silindi) bir önceki sayfaya.
                if (!res.items.length && this.page > 1 && res.pages < this.page) {
                    this.page = Math.max(1, res.pages);
                    this.loading = false;
                    return this.load(quiet);
                }
                this.items = res.items || [];
                this.kinds = res.kinds || [];
                this.total = res.total || 0;
                this.pages = res.pages || 0;
                this.unread = res.unread || 0;
                this.selected = this.selected.filter((id) => this.items.some((n) => n.id === id));
                this.$store.panel.unreadNotifications = this.unread;
                this.loaded = true;
            } catch (error) {
                if (!quiet) toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        /** Süzgeç ya da sayfa değişti: adres güncellenir, liste yeniden gelir. */
        go(changes) {
            Object.assign(this, changes);
            if (!('page' in changes)) this.page = 1;
            this.selected = [];
            const query = {};
            if (this.status) query.durum = STATUS_SLUG[this.status];
            if (this.kind) query.tur = this.kind;
            if (this.page > 1) query.sayfa = this.page;
            replaceUrl(href('notifications', {}, query));
            this.load();
        },

        toggleAll() {
            this.selected = this.allSelected ? [] : this.items.map((n) => n.id);
        },

        async open(n) {
            if (!n.is_read) await this.mark([n.id], true, true);
            if (n.url) navigate(n.url);
        },

        /** Okundu (`read` true) ya da okunmadı say. */
        async mark(ids, read, quiet = false) {
            if (!ids.length) return;
            for (const n of this.items) if (ids.includes(n.id)) n.is_read = read;
            try {
                const res = read ? await api.notificationsRead({ ids }) : await api.notificationsUnread({ ids });
                this.unread = res.unread;
                this.$store.panel.unreadNotifications = res.unread;
                if (!quiet) this.selected = [];
                if (this.status) this.load(true);
            } catch (error) {
                toast.error(error);
                this.load(true);
            }
        },

        async markAll() {
            this.busy = true;
            try {
                const res = await api.notificationsRead({ all: true });
                this.unread = res.unread;
                this.$store.panel.unreadNotifications = res.unread;
                toast.success('Bütün bildirimler okundu sayıldı.');
                await this.load(true);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = false;
            }
        },

        /** Seçilenleri ya da (`ids` null) okunmuşların hepsini siler. */
        async remove(ids) {
            if (ids === null && !(await confirmAction({ title: 'Okunmuş bildirimler silinsin mi?', message: 'Okunmamışlar kalır. Silinen bildirim geri gelmez.', confirmText: 'Sil', tone: 'danger' }))) return;
            this.busy = true;
            try {
                const res = await api.notificationsDelete(ids === null ? { read: true } : { ids });
                this.unread = res.unread;
                this.$store.panel.unreadNotifications = res.unread;
                toast.success(res.deleted ? `${res.deleted} bildirim silindi.` : 'Silinecek bildirim yok.');
                this.selected = [];
                await this.load(true);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = false;
            }
        },
    };
}
