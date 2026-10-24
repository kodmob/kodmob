/**
 * Genel bakış: "Sıradaki adımlar" (siteyi yayına hazırlarken sırayla; kodmob-api Core\Setup\Onboarding, adımlar
 * gerçek veriden işaretlenir), içerik sayıları, son değişenler, yeni talepler, Jarvis (süren taramalar), kısayollar.
 * Adımlar bitince kart kısalır; "Gizle" bu tarayıcıda, projeye göre hatırlanır.
 */
import { api } from './api.js';
import { TYPES, STATUS, relative } from './meta.js';
import { href } from './routes.js';
import { toast } from '../ui/toast.js';
import { storage } from '../services/storage.js';

const HIDDEN = 'km_steps_hidden';

export function kmOverview() {
    return {
        loading: true,
        data: null,
        status: STATUS,
        relative,
        api,
        stepsHidden: false,

        async init() {
            this.stepsHidden = (storage.getJSON(HIDDEN, []) || []).includes(this.projectKey);
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

        stepHref(step) {
            return href(step.route, { ...step.params, ...(step.tab ? { tab: step.tab } : {}) });
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
