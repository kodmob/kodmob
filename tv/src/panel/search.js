/**
 * Panel araması (üst çubuk; mobilde büyüteçle açılan katman). Kısayol: Ctrl+K / ⌘K ya da `/`.
 *
 * Sonuç grupları:
 * - Sayfalar ve işlemler, Ayarlar: tarayıcıda, yazarken (search-index.js; ayar tanımları ilk
 *   açılışta GET /settings ile bir kez alınır). "hangi ayar nerede": ayar sonucu ilgili sayfa ve
 *   sekmeyi açar, alanı ortalayıp vurgular (`#ayar=` bağlantısı → app.js → ui/spotlight.js).
 * - Kayıtlar (içerik, talepler, kullanıcılar, yönlendirmeler, sohbetler): sunucuda (GET /search),
 *   yazmaya ara verilince; geç gelen eski yanıt yok sayılır.
 *
 * Klavye: ↑ ↓ seçer, Enter açar (tek sonuç varsa doğrudan), Esc kapatır. Boşken son açılanlar
 * görünür (bu tarayıcıda, localStorage).
 */
import { api } from './api.js';
import { TYPES } from './meta.js';
import { buildIndex } from './search-index.js';
import { mark, rank, terms } from '../domain/search.js';
import { storage } from '../services/storage.js';

const RECENT_KEY = 'km_search_recent';
const RECENT_MAX = 6;
const LIMITS = { pages: 6, settings: 8 };
const MIN_REMOTE = 2;

const isTyping = (el) => el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));

export function kmSearch() {
    // Tepkisel olmayan önbellek: şablon gruplara birkaç kez bakar, hesap bir kez yapılır.
    let indexMemo = null;
    let groupsMemo = null;
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _keys: null,
        q: '',
        open: false,
        mobile: false,
        cursor: 0,
        remote: [],
        loading: false,
        definitions: null,
        recent: [],
        seq: 0,
        timer: null,

        init() {
            this.recent = storage.getJSON(RECENT_KEY, []) || [];
            this.$watch('q', () => this.schedule());
            this._keys = (event) => {
                const combo = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';
                const slash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !isTyping(event.target);
                if (!combo && !slash) return;
                event.preventDefault();
                this.focus();
            };
            window.addEventListener('keydown', this._keys);
            // Sonradan eklenen <use> ikonları (ayrı ikon dosyası) Chrome'da ilk boyamada boş kalabiliyor: liste bir kare yeniden boyanır.
            this.$watch(() => this.groups, () => this.$nextTick(() => this.repaint()));
            // Rol değişince yetkili ayarlar da değişir.
            this.$watch(() => this.$store.pulse.version('role'), () => (this.definitions = null));
        },

        destroy() {
            window.removeEventListener('keydown', this._keys);
        },

        /** Masaüstünde üst çubuktaki kutu, dar ekranda tam ekran katman. */
        focus() {
            this.mobile = !window.matchMedia('(min-width: 1024px)').matches;
            this.open = true;
            this.loadDefinitions();
            // Mobil katman body'ye taşınır (x-teleport): kutusu öznitelikle bulunur.
            this.$nextTick(() => (this.mobile ? document.querySelector('[data-search-input="mobile"]') : this.$refs.input)?.focus());
        },

        close() {
            this.open = false;
            this.mobile = false;
            this.cursor = 0;
        },

        async loadDefinitions() {
            if (this.definitions) return;
            this.definitions = [];
            try {
                this.definitions = (await api.settings()).items || [];
            } catch {
                // Ayar yetkisi yok (403) ya da ağ: yalnızca sayfalar ve panel formları aranır.
            }
        },

        get index() {
            const me = this.$store.session.me;
            const defs = this.definitions;
            if (!indexMemo || indexMemo.me !== me || indexMemo.defs !== defs) {
                indexMemo = { me, defs, value: buildIndex((cap) => this.$store.session.can(cap), defs || []) };
            }
            return indexMemo.value;
        },

        repaint() {
            const box = this.list();
            if (!box) return;
            box.style.transform = 'translateZ(0)';
            requestAnimationFrame(() => requestAnimationFrame(() => (box.style.transform = '')));
        },

        /** Açık olan sonuç listesi (masaüstü ya da mobil). */
        list() {
            return document.querySelector(`[data-search-list="${this.mobile ? 'mobile' : 'desktop'}"]`);
        },

        get shortcut() {
            return /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? '⌘K' : 'Ctrl K';
        },

        get hasQuery() {
            return terms(this.q).length > 0;
        },

        /** Gruplar: yerel (sayfalar, ayarlar) hemen; sunucu grupları geldikçe. */
        get groups() {
            const deps = [this.q, this.remote, this.recent, this.index];
            if (groupsMemo && deps.every((d, i) => d === groupsMemo.deps[i])) return groupsMemo.value;
            groupsMemo = { deps, value: this.computeGroups() };
            return groupsMemo.value;
        },

        computeGroups() {
            if (!this.hasQuery) {
                return this.recent.length ? [{ key: 'recent', label: 'Son açılanlar', items: this.recent.map((r) => ({ ...r, html: escapeText(r.title) })) }] : [];
            }
            const { pages, settings } = this.index;
            const local = [
                { key: 'pages', label: 'Sayfalar ve işlemler', items: rank(pages, this.q, LIMITS.pages) },
                { key: 'settings', label: 'Ayarlar', items: rank(settings, this.q, LIMITS.settings) },
            ];
            const server = this.remote.map((g) => ({
                key: g.key,
                label: g.label,
                items: g.items.map((i) => ({ key: `${g.key}:${i.id}`, title: i.title, path: i.subtitle, href: i.url, badge: i.badge, icon: TYPES[g.key]?.icon || g.icon })),
            }));
            return [...local, ...server]
                .filter((g) => g.items.length)
                .map((g) => ({ ...g, items: g.items.map((i) => ({ ...i, html: mark(i.title, this.q) })) }));
        },

        get flat() {
            return this.groups.flatMap((g) => g.items);
        },

        get activeKey() {
            return this.flat[this.cursor]?.key || null;
        },

        hover(item) {
            this.cursor = Math.max(0, this.flat.findIndex((i) => i.key === item.key));
        },

        get empty() {
            return this.hasQuery && !this.flat.length && !this.loading;
        },

        link(item) {
            return item.anchor ? `${item.href}#ayar=${encodeURIComponent(item.anchor)}` : item.href;
        },

        schedule() {
            clearTimeout(this.timer);
            this.cursor = 0;
            if (this.q.trim().length < MIN_REMOTE) {
                this.remote = [];
                this.loading = false;
                return;
            }
            this.loading = true;
            this.timer = setTimeout(() => this.fetch(), 220);
        },

        async fetch() {
            const current = ++this.seq;
            try {
                const result = await api.search(this.q.trim());
                if (current === this.seq) this.remote = result.groups || [];
            } catch {
                if (current === this.seq) this.remote = [];
            } finally {
                if (current === this.seq) this.loading = false;
            }
        },

        move(delta) {
            const count = this.flat.length;
            if (!count) return;
            this.cursor = (this.cursor + delta + count) % count;
            this.$nextTick(() => this.list()?.querySelector('[data-search-active="true"]')?.scrollIntoView({ block: 'nearest' }));
        },

        /** Enter: seçili sonuç (yoksa ilk) açılır; bağlantı panelin kendi gezinmesinden geçer. */
        submit() {
            const item = this.flat[this.cursor] || this.flat[0];
            if (!item) return;
            this.list()?.querySelector(`[data-search-key="${CSS.escape(item.key)}"]`)?.click();
        },

        /** Sonuç açıldı: son açılanlara yazılır, arama kapanır. */
        picked(item) {
            const entry = { key: item.key, title: item.title, path: item.path || '', href: item.href, anchor: item.anchor || '', icon: item.icon || 'search', badge: '' };
            this.recent = [entry, ...this.recent.filter((r) => r.key !== item.key)].slice(0, RECENT_MAX);
            storage.setJSON(RECENT_KEY, this.recent);
            this.q = '';
            this.close();
        },

        clearRecent() {
            this.recent = [];
            storage.setJSON(RECENT_KEY, []);
        },

    };
}

function escapeText(text) {
    return mark(text, '');
}
