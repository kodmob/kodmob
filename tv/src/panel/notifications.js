/**
 * Üst bardaki bildirim zili. Liste nabzın `notification`
 * konusu değişince yenilenir; yeni bildirim gelince kısa bir toast da çıkar.
 * Bildirime tıklamak okundu sayar ve bağlantısına (/panel/talepler/12 …) gider.
 */
import { navigate } from './routes.js';
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';

export function kmNotifications() {
    return {
        open: false,
        items: [],
        count: 0,
        loading: false,
        loadedOnce: false,
        lastTopId: 0,
        fmt,

        init() {
            this.load(true);
            this.$watch(() => this.$store.pulse.version('notification'), () => this.load(false));
        },

        async load(silent) {
            if (this.loading) return;
            this.loading = true;
            try {
                const res = await api.notifications();
                const items = res.items || [];
                const top = items[0]?.id || 0;
                if (!silent && this.loadedOnce && top > this.lastTopId) {
                    const fresh = items.filter((n) => n.id > this.lastTopId && !n.is_read).slice(0, 2);
                    fresh.forEach((n) => toast[n.tone === 'danger' ? 'error' : n.tone === 'warning' ? 'warning' : 'info'](n.message ? `${n.title} — ${n.message}` : n.title));
                }
                this.items = items;
                this.count = res.unread || 0;
                this.lastTopId = Math.max(this.lastTopId, top);
                this.loadedOnce = true;
                this.$store.panel.unreadNotifications = this.count;
            } catch {
                /* zil önemsiz; sonraki nabızda yeniden */
            } finally {
                this.loading = false;
            }
        },

        toggle() {
            this.open = !this.open;
            if (this.open) this.load(true);
        },

        async openItem(n) {
            this.open = false;
            if (!n.is_read) {
                n.is_read = true;
                this.count = Math.max(0, this.count - 1);
                api.notificationsRead({ ids: [n.id] }).catch(() => {});
            }
            if (n.url) navigate(n.url);
        },

        async markAll() {
            this.items = this.items.map((n) => ({ ...n, is_read: true }));
            this.count = 0;
            try {
                await api.notificationsRead({ all: true });
            } catch (error) {
                toast.error(error);
            }
        },
    };
}
