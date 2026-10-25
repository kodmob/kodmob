/**
 * Çoklu Dil > Metinler (/panel/diller/metinler; done-coklu-dil-raporu.md Faz 5): ana sayfa
 * başlıkları, süreç adımları, SSS, hesaplayıcı seçenekleri… Hangi metinlerin değiştirilebileceğini
 * derleme belirler (şablonda `tx()`); liste ve sözlükteki halleri sunucudan gelir.
 *
 * Kutularda sayfadaki hali durur. Sözlükteki haline dönen (ya da boşaltılan) metin kayıttan
 * silinir. Kayıt sayfa önbelleğini temizler: site hemen günceldir.
 *
 * Süzgeç: Tümü · Değiştirilenler · Güncellenmeli (varsayılan dildeki metin değiştirildi ama bu
 * dildeki karşılığı sözlükteki halinde). Dil ve süzgeç adreste (`?dil=en&filtre=guncellenmeli`).
 * Jarvis ile çeviri (Claude anahtarı varsa): tek metin ya da bütün "güncellenmeli"ler; kaydetmez.
 * JSON: seçili dilin metinleri dışa aktarılır (başka yerde çevrilip geri alınır); içe aktarma
 * yalnızca bilinen anahtarları forma doldurur, kaydetmez.
 */
import { href, replaceUrl } from './routes.js';
import { api } from './api.js';
import { localeLabels, localeCodes, defaultLocale } from './meta.js';
import { toast } from '../ui/toast.js';
import { downloadJson, readJson } from './files.js';

/** Süzgeç anahtarı → adresteki değer. */
const FILTERS = { all: '', edited: 'degisen', outdated: 'guncellenmeli' };


const PAGES = {
    home: 'Ana sayfa',
    about: 'Hakkımızda',
    services: 'Hizmetler',
    projects: 'Projeler',
    references: 'Referanslar',
    blog: 'Blog',
    contact: 'İletişim',
    estimate: 'Teklif hesaplayıcı',
};

const SECTIONS = {
    hero: 'Sayfa başı',
    brands: 'Markalar',
    featured: 'Öne çıkan projeler',
    services: 'Hizmetler',
    why: 'Neden Kodmob',
    process: 'Süreç',
    blog: 'Blog',
    cta: 'Teklif çağrısı',
    story: 'Hikayemiz',
    values: 'Değerlerimiz',
    office: 'Ofis',
    faq: 'Sıkça sorulan sorular',
    checks: 'Maddeler',
    types: 'Proje türleri',
    designs: 'Tasarım seçenekleri',
    features: 'Özellikler',
};

const FIELDS = {
    eyebrow: 'Üst etiket',
    title: 'Başlık',
    titleAccent: 'Başlık (renkli kısım)',
    lead: 'Açıklama',
    text: 'Metin',
    cta: 'Bağlantı',
    ctaPrimary: 'Birincil buton',
    ctaSecondary: 'İkincil buton',
    primary: 'Birincil buton',
    secondary: 'İkincil buton',
    viewProject: 'Proje bağlantısı',
    more: 'Logoların ardından',
    officeCity: 'Ofis şehri',
    officeLabel: 'Ofis etiketi',
    q: 'Soru metni',
    a: 'Cevap',
    value: 'Sayı',
    label: 'Etiket',
    all: '"Tümü" filtresi',
    empty: 'Liste boşken',
    rss: 'RSS bağlantısı',
    testimonials: 'Yorumlar başlığı',
    typeLabel: 'Proje türü başlığı',
    designLabel: 'Tasarım başlığı',
    featuresLabel: 'Özellikler başlığı',
    resultTitle: 'Sonuç başlığı',
    disclaimer: 'Uyarı notu',
};

/** Dizi öğelerinin adı: stats.2 → "İstatistik 3" */
const ITEMS = { checks: 'Madde', stats: 'İstatistik', points: 'Madde', steps: 'Adım', paragraphs: 'Paragraf', items: 'Öğe', 'faq.items': 'Soru', 'values.items': 'Değer' };

const ROUTE_LABELS = { ...PAGES, post: 'Blog yazısı', project: 'Proje sayfası', notFound: '404' };

function describe(key, def) {
    const [page, ...rest] = key.split('.');
    const section = rest.length > 1 ? rest[0] : '';
    const path = section ? rest.slice(1) : rest;
    const isIndex = (part) => /^\d+$/.test(part || '');
    const itemName = (list) => ITEMS[`${section}.${list}`] || ITEMS[list] || 'Öğe';
    const parts = [];
    // contact.checks.0: bölümün kendisi dizi
    if (path.length === 1 && isIndex(path[0])) parts.push(`${itemName(section)} ${Number(path[0]) + 1}`);
    else {
        for (let i = 0; i < path.length; i++) {
            if (isIndex(path[i])) continue;
            parts.push(isIndex(path[i + 1]) ? `${itemName(path[i])} ${Number(path[i + 1]) + 1}` : FIELDS[path[i]] || null);
        }
    }
    // Bilinmeyen ad (ör. hesaplayıcı seçeneği): Türkçe metnin kendisi.
    const label = parts.includes(null) || !parts.length ? `“${def[defaultLocale()] ?? ''}”` : parts.join(' · ');
    return {
        key,
        page,
        section: section ? SECTIONS[section] || section : 'Diğer metinler',
        label: label.length > 60 ? `${label.slice(0, 57)}…”` : label,
        long: localeCodes().some((code) => String(def[code] || '').length > 90),
        shared: (def.on || []).filter((route) => route !== page).map((route) => ROUTE_LABELS[route] || route),
    };
}

export function kmTexts(route = {}) {
    // Dil kaydından (sunucu): görünüm her açılışta güncel listeyle kurulur.
    const LOCALE_LABELS = localeLabels();
    const LOCALES = localeCodes();
    const DEFAULT = defaultLocale();
    const query = route.query || {};
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        destroyed: false,
        defs: {},
        fields: [],
        values: {},
        original: {},
        page: 'home',
        tab: LOCALES.includes(query.dil) ? query.dil : DEFAULT,
        filter: Object.keys(FILTERS).find((k) => FILTERS[k] && FILTERS[k] === query.filtre) || 'all',
        source: DEFAULT,
        translating: '',
        query: '',
        loading: true,
        saving: false,
        locales: LOCALE_LABELS,
        pages: PAGES,

        async init() {
            try {
                const { texts, values } = await api.texts();
                this.defs = texts;
                this.fields = Object.entries(texts).map(([key, def]) => describe(key, def));
                this.apply(values);
                this.page = Object.keys(PAGES).find((p) => this.fields.some((f) => f.page === p)) || 'home';
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
            this.$watch(() => this.changed, (list) => (this.$store.panel.dirty = list.length > 0));
            this.$watch(() => this.tab + '|' + this.filter, () => this.syncUrl());
        },

        /** Dil ve süzgeç adreste (varsayılanlar yazılmaz). */
        syncUrl() {
            if (this.destroyed) return;
            replaceUrl(href('texts', {}, { dil: this.tab !== DEFAULT ? this.tab : '', filtre: FILTERS[this.filter] }));
        },

        /** Güncellenmeli: kaynak dildeki (varsayılan) metin değiştirildi, bu dildeki sözlükteki halinde. */
        isOutdated(key, code = this.tab) {
            return code !== DEFAULT && (this.original[DEFAULT]?.[key] ?? '') !== (this.defs[key]?.[DEFAULT] ?? '') && (this.values[code]?.[key] ?? '') === (this.defs[key]?.[code] ?? '');
        },

        passes(f) {
            if (this.filter === 'edited') return this.isEdited(f.key, this.tab);
            if (this.filter === 'outdated') return this.isOutdated(f.key);
            return true;
        },

        get filterCounts() {
            return {
                all: this.fields.length,
                edited: this.fields.filter((f) => this.isEdited(f.key, this.tab)).length,
                outdated: this.fields.filter((f) => this.isOutdated(f.key)).length,
            };
        },

        translatorReady() {
            return !!this.$store.session.me?.translator;
        },

        /** Jarvis ile çeviri: varsayılan dildeki metinlerden bu dile (forma doldurur; kaydetmez). */
        async translate(keys) {
            const texts = Object.fromEntries(keys.map((k) => [k, this.values[DEFAULT][k]]).filter(([, v]) => String(v || '').trim()));
            if (!Object.keys(texts).length) return;
            this.translating = keys.length === 1 ? keys[0] : '*';
            try {
                const result = (await api.translateTexts({ from: DEFAULT, to: this.tab, texts })).texts || {};
                for (const [key, value] of Object.entries(result)) this.values[this.tab][key] = value;
                toast.success(`${Object.keys(result).length} metin çevrildi; gözden geçirip kaydedin.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.translating = '';
            }
        },

        /** Seçili dilin metinleri (kaynak dildeki karşılıklarıyla): çeviri için dışa aktarma. */
        exportJson() {
            const texts = Object.fromEntries(this.fields.map((f) => [f.key, this.values[this.tab][f.key]]));
            const source = this.tab !== DEFAULT ? Object.fromEntries(this.fields.map((f) => [f.key, this.values[DEFAULT][f.key]])) : undefined;
            downloadJson({ format: 'kodmob-texts', version: 1, locale: this.tab, source: source && DEFAULT, exported: new Date().toISOString(), note: 'texts değerlerini çevirip panelde "JSON içe aktar" ile ekleyin. Anahtarları değiştirmeyin.', texts, original: source }, `sayfa-metinleri-${this.tab}.json`);
            toast.success(`${LOCALE_LABELS[this.tab]} metinleri JSON olarak indirildi.`);
        },

        /** JSON'daki metinler seçili dile (bilinen anahtarlar); kaydetmez. */
        async importJson(files) {
            if (!files?.[0]) return;
            try {
                const data = await readJson(files[0]);
                if (data?.format !== 'kodmob-texts' || typeof data.texts !== 'object') throw new Error("Bu bir Kodmob metin JSON'u değil (format: kodmob-texts bekleniyor).");
                let filled = 0;
                for (const [key, value] of Object.entries(data.texts)) {
                    if (key in this.defs && typeof value === 'string' && value.trim() && this.values[this.tab][key] !== value) {
                        this.values[this.tab][key] = value.slice(0, 2000);
                        filled++;
                    }
                }
                toast.success(filled ? `${filled} metin dolduruldu; Kaydet'e basınca yazılır.` : 'Değişen metin yok.');
            } catch (error) {
                toast.error(error);
            }
        },

        translateOutdated() {
            return this.translate(this.fields.filter((f) => this.isOutdated(f.key)).map((f) => f.key));
        },

        /** Kutulardaki hal: kayıtlı metin, yoksa sözlükteki. */
        apply(saved) {
            const values = {};
            for (const code of LOCALES) {
                values[code] = {};
                for (const key of Object.keys(this.defs)) values[code][key] = saved?.[code]?.[key] ?? this.defs[key][code] ?? '';
            }
            this.values = values;
            this.original = JSON.parse(JSON.stringify(values));
        },

        get changed() {
            const list = [];
            for (const code of LOCALES) {
                for (const key of Object.keys(this.defs)) {
                    if (this.values[code][key] !== this.original[code][key]) list.push([code, key]);
                }
            }
            return list;
        },

        get pageList() {
            return Object.entries(PAGES)
                .map(([key, label]) => ({ key, label, count: this.fields.filter((f) => f.page === key && this.passes(f)).length, edited: this.fields.filter((f) => f.page === key && this.isEdited(f.key)).length }))
                .filter((p) => p.count);
        },

        /** Arama varken bütün sayfalarda arar. */
        get groups() {
            const q = this.query.trim().toLocaleLowerCase('tr');
            const match = (f) => !q || [f.label, f.key, this.values[this.tab][f.key], this.defs[f.key][DEFAULT]].some((s) => String(s).toLocaleLowerCase('tr').includes(q));
            // Arama ya da süzgeç varken bütün sayfalarda.
            const wide = q || this.filter !== 'all';
            const list = this.fields.filter((f) => this.passes(f) && (q ? match(f) : wide || f.page === this.page));
            const groups = [];
            for (const f of list) {
                const name = wide ? `${PAGES[f.page] || f.page} · ${f.section}` : f.section;
                let group = groups.find((g) => g.name === name);
                if (!group) groups.push((group = { name, fields: [] }));
                group.fields.push(f);
            }
            return groups;
        },

        /** Sözlükteki halinden farklı mı (herhangi bir dilde). */
        isEdited(key, code = null) {
            const codes = code ? [code] : LOCALES;
            return codes.some((c) => (this.values[c]?.[key] ?? '') !== (this.defs[key]?.[c] ?? ''));
        },

        localeEdited(code) {
            return this.fields.some((f) => this.isEdited(f.key, code));
        },

        reset(key) {
            this.values[this.tab][key] = this.defs[key][this.tab] ?? '';
        },

        undo() {
            this.values = JSON.parse(JSON.stringify(this.original));
        },

        async save() {
            if (!this.changed.length || this.saving) return;
            this.saving = true;
            try {
                const body = {};
                for (const [code, key] of this.changed) (body[code] ||= {})[key] = this.values[code][key];
                const { values } = await api.saveTexts(body);
                this.apply(values);
                this.$store.panel.dirty = false;
                toast.success('Sayfa metinleri kaydedildi; site güncellendi.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        destroy() {
            this.destroyed = true;
            this.$store.panel.dirty = false;
        },
    };
}
