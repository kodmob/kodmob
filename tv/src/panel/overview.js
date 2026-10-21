/**
 * Genel bakış: içerik sayıları, son değişenler, yeni talepler, Jarvis (süren
 * taramalar), kısayollar.
 */
import { api } from './api.js';
import { TYPES, STATUS, relative } from './meta.js';
import { toast } from '../ui/toast.js';

export function kmOverview() {
    return {
        loading: true,
        data: null,
        status: STATUS,
        relative,
        api,

        async init() {
            try {
                this.data = await api.overview();
                if (this.data.briefs) this.$store.panel.newBriefs = this.data.briefs.new;
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        /** İçerik türleri: sunucunun saydıkları (kapalı modülün türü yok; kodmob-api Core\Modules). */
        get types() {
            return Object.fromEntries(Object.entries(TYPES).filter(([key]) => !this.data || this.data.counts?.[key]));
        },

        /** Üstteki "Yeni …" düğmesi: projenin yetkisi olan ilk içerik türü (kodmob.com: proje); blog ayrı düğme. */
        get firstType() {
            const entry = Object.entries(TYPES).find(([key, t]) => key !== 'posts' && this.$store.session.can(t.can));
            return entry ? { key: entry[0], ...entry[1] } : null;
        },

        get greeting() {
            const hour = new Date().getHours();
            const name = (this.$store.panel.user?.name || '').split(' ')[0];
            const hello = hour < 6 ? 'İyi geceler' : hour < 12 ? 'Günaydın' : hour < 18 ? 'İyi günler' : 'İyi akşamlar';
            return name ? `${hello}, ${name}` : hello;
        },
    };
}
