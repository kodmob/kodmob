/**
 * Sayfanın içeriği ($store.km): site bilgileri, hizmetler, projeler, yazılar,
 * referanslar ve kayıt sayfasında `entry`.
 *
 * Veri sayfaya gömülü gelir (`<script id="km-data">`, render.php doldurur).
 * Gömülü değilse (geliştirme sunucusu, önbellek dışı yedek) `GET /page-data`
 * ile istenir; rota ve ihtiyaçlar `<body data-route data-needs>`'ten okunur.
 *
 * Kayıtlara bu dildeki adres (`url`) eklenir; kayıt sayfasında dil seçicinin
 * ve dil önerisi bandının hedefleri kaydın çevirilerine göre ayarlanır.
 *
 *     <template x-for="p in $store.km.featured">…<a :href="p.url" x-text="p.title"></a></template>
 */
import { API } from '../services/config.js';
import { lang, intlLocale, pageInfo, setAlternates } from '../i18n/client.js';

const EMPTY = { site: { social: {}, hours: {}, legal: {} }, services: [], projects: [], posts: [], references: [], products: [], tours: [], hotels: [], entry: null };

function embedded() {
    try {
        return JSON.parse(document.getElementById('km-data')?.textContent || 'null');
    } catch {
        return null;
    }
}

async function fetchData() {
    const { route = '', needs = '' } = document.body.dataset;
    const dynamic = document.body.dataset.dynamic;
    const slug = dynamic ? decodeURIComponent(window.location.pathname.replace(/\/$/, '').split('/').pop()) : '';
    const query = new URLSearchParams({ route, locale: lang(), slug, needs });
    try {
        const response = await fetch(`${API.base}${API.app}/page-data?${query}`, { headers: { Accept: 'application/json' } });
        return response.ok ? response.json() : null;
    } catch {
        return null;
    }
}

/** Sayfanın verisi; sayfa içerik istemiyorsa (panel) null. */
export async function loadPageData() {
    if (!document.body.dataset.route) return null;
    return embedded() || (await fetchData()) || { ...EMPTY };
}

export function createContentStore(raw) {
    const info = pageInfo();
    const locale = lang();
    const patterns = info.patterns || {};
    const fill = (name, code, slug) => (patterns[name]?.[code] || '').replace(':slug', encodeURIComponent(slug));
    const withUrl = (name) => (item) => ({ ...item, url: fill(name, locale, item.slug) });

    const data = { ...EMPTY, ...(raw || {}) };
    const projects = (data.projects || []).map(withUrl('project'));
    const posts = (data.posts || []).map(withUrl('post'));
    // Mağaza (commerce): ürünler kendi sayfalarına (rota `product`).
    const products = (data.products || []).map(withUrl('product'));
    const tours = (data.tours || []).map(withUrl('tour'));
    // Oteller (Rezervasyon'a takılı araç): rota `hotel`.
    const hotels = (data.hotels || []).map(withUrl('hotel'));
    const dynamic = document.body.dataset.dynamic;
    const entry = data.entry ? withUrl(dynamic)(data.entry) : null;

    // Kayıt sayfası: dil karşılıkları kaydın çevirileri; olmayan dil rotanın listesine düşer.
    const alternates = {};
    if (entry) {
        for (const [code, slug] of Object.entries(entry.slugs || {})) alternates[code] = fill(dynamic, code, slug);
        setAlternates(alternates);
        document.title = document.title.replace(/^[^·]*/, `${entry.title} `);
    }
    // 404: kayıt başka dillerde var ("şu dillerde mevcut"); dil seçici ve öneri bandı oraya gider.
    if (!entry && data.available?.length) {
        for (const a of data.available) alternates[a.code] = a.url;
        setAlternates(alternates);
    }

    const labels = info.labels || {};
    const dateFormat = new Intl.DateTimeFormat(intlLocale(), { day: 'numeric', month: 'long', year: 'numeric' });

    return {
        ...data,
        projects,
        posts,
        products,
        tours,
        hotels,
        entry,
        routes: info.routes || {},

        /** Ana sayfa vitrini: öne çıkanlar, yoksa bütün projeler. */
        get featured() {
            const featured = this.projects.filter((p) => p.featured);
            return featured.length ? featured : this.projects;
        },

        project(id) {
            return this.projects.find((p) => p.id === id) || null;
        },

        /** Hizmet bölümündeki örnek projeler (kategorilerinden biri eşleşen). */
        projectsFor(categories = []) {
            return this.projects.filter((p) => p.categories.some((c) => categories.includes(c)));
        },

        /** Proje sayfası: aynı kategoriden iki proje, yoksa diğerleri. */
        get similar() {
            if (!this.entry) return [];
            const others = this.projects.filter((p) => p.id !== this.entry.id);
            const close = others.filter((p) => p.categories.some((c) => this.entry.categories?.includes(c)));
            return [...close, ...others.filter((p) => !close.includes(p))].slice(0, 2);
        },

        /** Yazı sayfası: önce aynı kategoriden üç yazı. */
        get related() {
            if (!this.entry) return [];
            const others = this.posts.filter((p) => p.id !== this.entry.id);
            return [...others.filter((p) => p.category === this.entry.category), ...others.filter((p) => p.category !== this.entry.category)].slice(0, 3);
        },

        /** Referans yorumları kart biçiminde ({ quote, name, role }), bağlı projesiyle. */
        get testimonials() {
            return this.references.filter((r) => r.quote).map((r) => ({ ...r, name: r.person, linked: this.project(r.project) }));
        },

        /** Kullanılan kategoriler (filtre hapları): [{ key, label }]. */
        categoriesOf(items, field, group) {
            const used = new Set(items.flatMap((item) => [].concat(item[field] || [])));
            return Object.entries(labels[group] || {}).filter(([key]) => used.has(key)).map(([key, label]) => ({ key, label }));
        },

        /** Paragraf listesi → kaçışlı <p> HTML'i (x-html; sunucu aynısını EntrySsr ile yazar). */
        paragraphs(list) {
            const escape = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
            return (Array.isArray(list) ? list : String(list || '').split(/\n\s*\n/)).filter((p) => String(p).trim()).map((p) => `<p>${escape(p)}</p>`).join('');
        },

        label(group, key) {
            return labels[group]?.[key] ?? key;
        },

        readTime(min) {
            return String(labels.readTime || '{min}').replace('{min}', min);
        },

        date(iso) {
            return iso ? dateFormat.format(new Date(iso)) : '';
        },

        /** Kayıt sayfasında dil seçicinin hedefi (yoksa derlemedeki yedek). */
        alt(code, fallback) {
            return alternates[code] || fallback;
        },

        /** Adının baş harfleri (yorum kartı). */
        initials(name = '') {
            const parts = String(name).trim().split(/\s+/);
            return ((parts[0]?.[0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toLocaleUpperCase(locale);
        },
    };
}
