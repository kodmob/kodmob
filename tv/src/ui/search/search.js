/**
 * Site içi arama ($store.search): Ctrl+K / ⌘K ya da header'daki düğmeyle açılır.
 *
 * Arama dizini ilk açılışta bir kez kurulur: sayfalar derlemeden (#km-i18n
 * searchPages), kayıtlar (yazılar ve projenin türleri, ör. hizmetler, projeler) adresleri ve
 * türleriyle `GET /search-index`'ten. Arama tamamen tarayıcıda yapılır. Türkçe/Almanca harfler sadeleştirilir
 * ("yazilim" → "yazılım" da bulunur). Başlık eşleşmesi metinden ağır basar.
 *
 * Klavye: ↑ ↓ gezinir, Enter açar, Esc kapatır.
 */
import { lang, pageInfo } from '../../i18n/client.js';
import { API } from '../../services/config.js';

const FOLD = { ı: 'i', İ: 'i', ş: 's', ğ: 'g', ü: 'u', ö: 'o', ç: 'c', ä: 'a', ß: 'ss' };
const fold = (text) => String(text || '').toLocaleLowerCase('tr').replace(/[ıİşğüöçäß]/g, (c) => FOLD[c] || c).normalize('NFD').replace(/[̀-ͯ]/g, '');

const MAX = 12;

export function createSearchStore() {
    return {
        open: false,
        query: '',
        items: [],
        results: [],
        active: 0,
        loading: false,
        loaded: false,
        returnFocus: null,

        async show() {
            this.returnFocus = document.activeElement;
            this.open = true;
            document.documentElement.classList.add('overflow-hidden');
            if (!this.loaded) await this.load();
            this.run();
        },

        hide() {
            this.open = false;
            document.documentElement.classList.remove('overflow-hidden');
            this.returnFocus?.focus?.();
        },

        async load() {
            this.loading = true;
            const info = pageInfo();
            const pages = (info.searchPages || []).map((p) => ({ type: 'page', title: p.title, text: '', url: p.url }));
            try {
                const response = await fetch(`${API.base}${API.app}/search-index?locale=${lang()}`, { headers: { Accept: 'application/json' } });
                const data = response.ok ? await response.json() : {};
                // Her kayıt adresini ve türünü (sonuç etiketi: search.types.<tür>) sunucudan getirir.
                const records = Object.values(data).flat().filter((r) => r?.url).map((r) => ({ type: r.type, title: r.title, text: r.text, url: r.url }));
                const items = [...pages, ...records];
                this.items = items.map((item) => ({ ...item, _title: fold(item.title), _text: fold(item.text) }));
                this.loaded = true;
            } catch {
                this.items = pages.map((item) => ({ ...item, _title: fold(item.title), _text: '' }));
            } finally {
                this.loading = false;
            }
        },

        run() {
            const tokens = fold(this.query).split(/\s+/).filter(Boolean);
            if (!tokens.length) {
                // Boş sorgu: sayfaları ve son projeleri öner.
                this.results = this.items.filter((i) => i.type === 'page').slice(0, 8);
                this.active = 0;
                return;
            }
            this.results = this.items
                .map((item) => {
                    let score = 0;
                    for (const token of tokens) {
                        const inTitle = item._title.includes(token);
                        const inText = item._text.includes(token);
                        if (!inTitle && !inText) return null;
                        score += (inTitle ? 3 : 0) + (inText ? 1 : 0) + (item._title.startsWith(token) ? 2 : 0);
                    }
                    return { item, score };
                })
                .filter(Boolean)
                .sort((a, b) => b.score - a.score)
                .slice(0, MAX)
                .map((r) => r.item);
            this.active = 0;
        },

        move(step) {
            if (!this.results.length) return;
            this.active = (this.active + step + this.results.length) % this.results.length;
        },

        go(index = this.active) {
            const item = this.results[index];
            if (item) window.location.assign(item.url);
        },
    };
}

/** Ctrl+K / ⌘K kısayolu ve "/" (yazı alanında değilken). */
export function bindSearchShortcut(Alpine) {
    window.addEventListener('keydown', (event) => {
        const store = Alpine.store('search');
        const typing = event.target.closest?.('input, textarea, select, [contenteditable]');
        if ((event.key === 'k' && (event.ctrlKey || event.metaKey)) || (event.key === '/' && !typing && !store.open)) {
            event.preventDefault();
            store.open ? store.hide() : store.show();
        }
    });
}
