/**
 * Tüm İşlemler (/panel/diger-islemler; üst bardaki ızgara simgesi): paneldeki bütün işler tek yerde, gruplu kartlarda
 * Kartlar ve bağlantılar işlem kataloğundan (operations.js), yetkiye göre.
 *
 * Hızlı İşlemler (üstte, kişiye özel): en çok kullanılan işler. Ekleme: satırdaki yıldız ya da
 * satırı alana sürükleme. Sıra: alanda sürükle bırak ya da Düzenle'de ← → (dokunmatik ve
 * klavye için). Seçim sunucuda (/me/quick-actions), her cihazda aynı. Hiç düzenlemeyen kişiye
 * DEFAULT_QUICK gösterilir; "Varsayılana dön" seçimi siler.
 */
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { DEFAULT_QUICK, ITEMS, QUICK_MAX, allowed, cardsFor, itemFor } from './operations.js';
import { score, terms } from '../domain/search.js';

const fullText = () => `Hızlı İşlemler'e en fazla ${QUICK_MAX} işlem eklenir. Önce birini çıkarın.`;

export function kmOther() {
    return {
        quickKeys: null,
        editing: false,
        dragKey: null,
        dropIndex: null,
        saveTimer: null,
        saving: Promise.resolve(),
        q: '',

        async init() {
            try {
                const { keys } = await api.quickActions();
                this.quickKeys = keys ?? [...DEFAULT_QUICK];
            } catch {
                this.quickKeys = [...DEFAULT_QUICK];
            }
        },

        destroy() {
            clearTimeout(this.saveTimer);
        },

        can(cap) {
            return allowed(cap, (c) => this.$store.session.can(c));
        },

        /** Yetkiye göre süzülmüş kartlar; aramada yalnızca eşleşen satırlar. */
        get cards() {
            const cards = cardsFor((c) => this.$store.session.can(c));
            const list = terms(this.q);
            if (!list.length) return cards;
            return cards
                .map((c) => ({ ...c, links: c.links.filter((l) => score({ title: l.label, text: `${l.hint || ''} ${c.title}` }, list) > 0) }))
                .filter((c) => c.links.length);
        },

        // ------------------------------------------------------------ hızlı işlemler
        /** Görünen hızlı işlemler: katalogda olan ve yetkisi yeten anahtarlar, kişinin sırasıyla. */
        get quick() {
            return (this.quickKeys || []).map((k) => itemFor(k)).filter((i) => i && this.can(i.can));
        },

        get quickFull() {
            return this.quick.length >= QUICK_MAX;
        },

        isQuick(key) {
            return this.quick.some((i) => i.key === key);
        },

        toggleQuick(key) {
            const item = ITEMS.get(key);
            if (this.isQuick(key)) {
                this.removeQuick(key);
                toast.success(`${item.label} Hızlı İşlemler'den çıkarıldı.`);
                return;
            }
            if (this.quickFull) return toast.warning(fullText());
            this.setQuick([...this.quick.map((i) => i.key), key]);
            toast.success(`${item.label} Hızlı İşlemler'e eklendi.`);
        },

        removeQuick(key) {
            this.setQuick(this.quick.map((i) => i.key).filter((k) => k !== key));
        },

        moveQuick(key, step) {
            const keys = this.quick.map((i) => i.key);
            const from = keys.indexOf(key);
            const to = from + step;
            if (from < 0 || to < 0 || to >= keys.length) return;
            keys.splice(to, 0, keys.splice(from, 1)[0]);
            this.setQuick(keys);
            this.$nextTick(() => this.$refs.quick?.querySelector(`[data-quick="${key}"] [data-move="${step < 0 ? 'left' : 'right'}"]`)?.focus());
        },

        async resetQuick() {
            clearTimeout(this.saveTimer);
            this.quickKeys = [...DEFAULT_QUICK];
            await this.send(() => api.resetQuickActions(), 'Hızlı İşlemler varsayılana döndü.');
        },

        /** Sunucuya yazımlar sırayla gider: yolda olan kayıt, sonraki sıfırlamanın üstüne yazmaz. */
        send(call, success = '') {
            this.saving = this.saving.then(call).then(() => success && toast.success(success)).catch((error) => toast.error(error));
            return this.saving;
        },

        /** Yerelde hemen değişir, sunucuya kısa gecikmeyle (art arda değişikliklerde tek istek) yazılır. */
        setQuick(keys) {
            this.quickKeys = keys;
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.send(() => api.saveQuickActions(keys)), 400);
        },

        // ------------------------------------------------------------ sürükle bırak (masaüstü)
        dragStart(event, key) {
            this.dragKey = key;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', key);
        },

        dragEnd() {
            this.dragKey = null;
            this.dropIndex = null;
        },

        /** Alanın üstünde: bırakılınca hangi kutunun önüne gireceği. */
        dragOver(event) {
            if (!this.dragKey) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const tiles = [...this.$refs.quick.querySelectorAll('[data-quick]')].filter((t) => t.dataset.quick !== this.dragKey);
            let index = tiles.length;
            for (let i = 0; i < tiles.length; i++) {
                const r = tiles[i].getBoundingClientRect();
                const sameRow = event.clientY >= r.top && event.clientY <= r.bottom;
                if ((sameRow && event.clientX < r.left + r.width / 2) || event.clientY < r.top) {
                    index = i;
                    break;
                }
            }
            this.dropIndex = index;
        },

        drop() {
            const key = this.dragKey;
            if (!key || !ITEMS.has(key)) return this.dragEnd();
            const added = !this.isQuick(key);
            if (added && this.quickFull) {
                toast.warning(fullText());
                return this.dragEnd();
            }
            const keys = this.quick.map((i) => i.key).filter((k) => k !== key);
            keys.splice(Math.min(this.dropIndex ?? keys.length, keys.length), 0, key);
            this.setQuick(keys);
            if (added) toast.success(`${ITEMS.get(key).label} Hızlı İşlemler'e eklendi.`);
            this.dragEnd();
        },
    };
}
