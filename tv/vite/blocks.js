/**
 * Bloklar (sayfa düzenleyicinin yapı taşları; docs/done-onyuz-raporu.md §3). Derleme blokları toplar, varsayılan
 * metinlerini sözlükten çözer, bölgelerin varsayılan bloklarını kabuğa yazar ve sunucuya `km-blocks.json`'u verir
 * (kodmob-api Modules\Site\Blocks\BlockLibrary).
 *
 * Blok klasörü (`tv/src/blocks/<ad>/`, profilin `blocks/<ad>/`, araç paketinin `blocks/<ad>/`):
 *   block.json   etiket, açıklama, kategori, ikon, alanlar, `library` (false: Keşfet'ten eklenir), `needs`, `module`,
 *                `scope: "entry"` (içeriği sayfadaki kayıttan: yalnızca kayıt sayfalarında eklenir; ör. tur programı)
 *   block.html   şablon (src/blocks/template.js: {{ alan }}, {{#liste}}…{{/liste}}); satır içi stil ve betik yok
 *   block.css    (isteğe bağlı) src/blocks/blocks.css içe aktarır
 *   block.js     (isteğe bağlı) `init(el)`: sitede yalnızca blok sayfadaysa iner (src/blocks/index.js)
 *   preview.svg  (isteğe bağlı) Keşfet ve düzenleyicideki küçük resim
 *
 * Alan: `{ name, type, label, localized?, default?, max?, options?, columns?, help? }`. Tür: text, textarea, html,
 * link (`route:<rota>`, `/yol`, `#çapa`, `https://…`, `mailto:`, `tel:`), bool, int, select, icon, image, rows.
 * Varsayılan `@sözlük.anahtarı` ise her dilde sözlükten (dizi de olabilir). Bölge: şablonda
 * `{{ zone('home.main', 'Etiket') }}`; varsayılan blokları profilin site.config.js `zones`'unda.
 */
import fs from 'node:fs';
import path from 'node:path';
import { parse, render } from '../src/blocks/template.js';

export const CATEGORIES = { section: 'Bölümler', content: 'İçerik', animation: 'Animasyon', commerce: 'Mağaza', entry: 'Kayıt sayfası' };
export const FIELD_TYPES = ['text', 'textarea', 'html', 'link', 'bool', 'int', 'select', 'icon', 'image', 'rows'];
const COLUMN_TYPES = ['text', 'textarea', 'link', 'bool', 'int', 'select', 'icon', 'image'];
const NAME = /^[a-z][a-z0-9-]{1,40}$/;
const FIELD_NAME = /^[a-zA-Z][a-zA-Z0-9]{0,40}$/;
export const ZONE_KEY = /^[a-zA-Z][a-zA-Z0-9-]*(\.[a-zA-Z0-9-]+)+$/;

/** Bir klasördeki bloklar: ad → { def, template, dir, source }. Hatalı tanım derlemeyi durdurur. */
export function readBlocks(dir, source) {
    if (!dir || !fs.existsSync(dir)) return {};
    const out = {};
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const folder = path.join(dir, entry.name);
        if (!entry.isDirectory() || !fs.existsSync(path.join(folder, 'block.json'))) continue;
        const where = `${source}: ${entry.name}`;
        if (!NAME.test(entry.name)) throw new Error(`[blok] ${where}: ad küçük harf, rakam ve tire olmalı`);
        let def;
        try {
            def = JSON.parse(fs.readFileSync(path.join(folder, 'block.json'), 'utf-8'));
        } catch (error) {
            throw new Error(`[blok] ${where}: block.json okunamadı (${error.message})`);
        }
        const problems = blockProblems(def);
        if (problems.length) throw new Error(`[blok] ${where}:\n  ${problems.join('\n  ')}`);
        const file = path.join(folder, 'block.html');
        if (!fs.existsSync(file)) throw new Error(`[blok] ${where}: block.html yok`);
        const template = fs.readFileSync(file, 'utf-8').trim();
        try {
            parse(template);
        } catch (error) {
            throw new Error(`[blok] ${where}: ${error.message}`);
        }
        if (/\sstyle\s*=|<script\b/i.test(template)) throw new Error(`[blok] ${where}: şablonda satır içi stil ya da betik olamaz (CSP)`);
        const preview = path.join(folder, 'preview.svg');
        out[entry.name] = {
            def,
            template,
            dir: folder,
            source,
            script: fs.existsSync(path.join(folder, 'block.js')),
            preview: fs.existsSync(preview) ? `data:image/svg+xml;base64,${fs.readFileSync(preview).toString('base64')}` : '',
        };
    }
    return out;
}

/** block.json denetimi: eksik etiket, bilinmeyen kategori ya da alan türü, yinelenen alan. */
export function blockProblems(def) {
    const problems = [];
    if (!def || typeof def !== 'object') return ['block.json bir nesne olmalı'];
    if (!def.label) problems.push('label (etiket) yok');
    if (!CATEGORIES[def.category]) problems.push(`category bilinmiyor: ${def.category} (${Object.keys(CATEGORIES).join(', ')})`);
    const fields = Array.isArray(def.fields) ? def.fields : [];
    if (!Array.isArray(def.fields)) problems.push('fields bir liste olmalı');
    const names = new Set();
    for (const field of fields) {
        if (!FIELD_NAME.test(field?.name || '')) problems.push(`alan adı geçersiz: ${field?.name}`);
        if (names.has(field.name)) problems.push(`alan iki kez: ${field.name}`);
        names.add(field.name);
        if (!FIELD_TYPES.includes(field.type)) problems.push(`${field.name}: tür bilinmiyor (${field.type})`);
        if (field.type === 'select' && (!field.options || typeof field.options !== 'object' || !Object.keys(field.options).length)) problems.push(`${field.name}: select için options gerekir`);
        if (field.type === 'rows') {
            if (!Array.isArray(field.columns) || !field.columns.length) problems.push(`${field.name}: rows için columns gerekir`);
            for (const column of field.columns || []) {
                if (!FIELD_NAME.test(column?.name || '') || !COLUMN_TYPES.includes(column.type)) problems.push(`${field.name}.${column?.name}: sütun adı ya da türü geçersiz (${column?.type})`);
            }
        }
    }
    return problems;
}

/** `@anahtar` metinleri sözlükten (iç içe dizi ve nesnelerde de); `@@` düz `@`. */
export function resolveRefs(value, t) {
    if (typeof value === 'string') {
        if (value.startsWith('@@')) return value.slice(1);
        return value.startsWith('@') ? resolveRefs(t(value.slice(1)), t) : value;
    }
    if (Array.isArray(value)) return value.map((v) => resolveRefs(v, t));
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, resolveRefs(v, t)]));
    return value;
}

const blank = (field) => {
    switch (field.type) {
        case 'bool': return false;
        case 'int': return 0;
        case 'rows': return [];
        case 'image': return 0;
        default: return '';
    }
};

const TEXT_TYPES = ['text', 'textarea', 'html'];
/** Satırın düz metin olarak verildiği listede (sözlükte `["a", "b"]`) metnin gittiği sütun: ilk metin sütunu. */
const textColumn = (field) => (field.columns || []).find((c) => TEXT_TYPES.includes(c.type))?.name;

/** Satırın sütunları: eksik sütun boş değerle (şablonda yok sayılır); düz metin satır ilk metin sütununa. */
function rowsOf(field, value) {
    const column = textColumn(field);
    return (Array.isArray(value) ? value : [])
        .map((row) => (typeof row === 'string' && column ? { [column]: row } : row))
        .filter((row) => row && typeof row === 'object')
        .map((row) => Object.fromEntries(field.columns.map((c) => [c.name, row[c.name] ?? c.default ?? blank(c)])));
}

/**
 * Dil alanlarının sözlükteki anahtarları (`@home.hero.title`): alan yolu → anahtar (`checks.0.text` →
 * `home.hero.checks.0`). Sunucu panelden değiştirilmiş sayfa metnini (Content\PageTexts) bölgenin ilk bloklarına
 * bununla taşır: `tx()` ile yazılmış bir sayfa bloklara geçince metinleri kaybolmaz.
 */
function textRefs(fields, given, t) {
    const out = {};
    for (const field of fields.filter((f) => f.localized)) {
        const raw = Object.hasOwn(given, field.name) ? given[field.name] : field.default;
        if (typeof raw !== 'string' || !raw.startsWith('@') || raw.startsWith('@@')) continue;
        const key = raw.slice(1);
        if (TEXT_TYPES.includes(field.type)) {
            out[field.name] = key;
        } else if (field.type === 'rows') {
            const column = textColumn(field);
            (Array.isArray(t(key)) ? t(key) : []).forEach((row, i) => {
                if (typeof row === 'string' && column) out[`${field.name}.${i}.${column}`] = `${key}.${i}`;
                else if (row && typeof row === 'object') {
                    for (const c of field.columns) if (TEXT_TYPES.includes(c.type) && typeof row[c.name] === 'string') out[`${field.name}.${i}.${c.name}`] = `${key}.${i}.${c.name}`;
                }
            });
        }
    }
    return out;
}

/**
 * Blok örneği (bölgedeki bir blok): `data` ortak alanlar, `i18n[dil]` dile bağlı alanlar. `raw.data` iki türü de
 * taşıyabilir (profilin zones listesi); `@` başvuruları her dilde çözülür, verilmeyen alan bloğun varsayılanı.
 */
export function instanceOf(block, raw, { id, codes, defaultLocale, translators }) {
    const fields = block.def.fields || [];
    const given = raw.data || {};
    const pick = (field, locale) => {
        const t = translators[locale];
        const value = Object.hasOwn(given, field.name) ? given[field.name] : field.default ?? blank(field);
        const resolved = resolveRefs(value, t);
        return field.type === 'rows' ? rowsOf(field, resolved) : resolved;
    };
    const texts = textRefs(fields, given, translators[defaultLocale]);
    return {
        id,
        type: raw.type,
        hidden: false,
        data: Object.fromEntries(fields.filter((f) => !f.localized).map((f) => [f.name, pick(f, defaultLocale)])),
        i18n: Object.fromEntries(codes.map((code) => [code, Object.fromEntries(fields.filter((f) => f.localized).map((f) => [f.name, pick(f, code)]))])),
        ...(Object.keys(texts).length ? { texts } : {}),
    };
}

/** Bağlantı alanı → adres (`route:contact` dilin adresine; yoksa boş). */
export function linkHref(value, url) {
    const v = String(value || '').trim();
    if (v.startsWith('route:')) return url(v.slice(6)) || '';
    return v;
}

/** Şablonun göreceği değerler: bağlantılar çözülmüş, görseller (derlemede medya yok) boş. */
export function viewData(block, instance, locale, url) {
    const values = { ...(instance.data || {}), ...(instance.i18n?.[locale] || {}) };
    const out = {};
    for (const field of block.def.fields || []) {
        const value = values[field.name] ?? blank(field);
        if (field.type === 'link') out[field.name] = linkHref(value, url);
        else if (field.type === 'image') out[field.name] = value && typeof value === 'object' ? value : null;
        else if (field.type === 'rows') {
            out[field.name] = rowsOf(field, value).map((row) => Object.fromEntries(field.columns.map((c) => [c.name, c.type === 'link' ? linkHref(row[c.name], url) : c.type === 'image' ? (row[c.name] && typeof row[c.name] === 'object' ? row[c.name] : null) : row[c.name]])));
        } else out[field.name] = value;
    }
    return out;
}

/** Bloğun sayfadaki hali (sunucudaki BlockRenderer ile aynı sarmalayıcı). `preview`: düzenleyici işaretleri kalır. */
export function renderInstance(block, instance, locale, url, { preview = false } = {}) {
    let html = render(block.template, viewData(block, instance, locale, url));
    if (!preview) html = html.replace(/\sdata-km-field="[^"]*"/g, '');
    return `<div class="km-block" data-km-block="${instance.type}" data-km-id="${instance.id}">${html}</div>`;
}

/** Sprite'taki ikon adları (src/shell/icons.html `id="i-…"`): ikon alanının seçenekleri. */
export function spriteIcons(file) {
    if (!file || !fs.existsSync(file)) return [];
    return [...fs.readFileSync(file, 'utf-8').matchAll(/<symbol[^>]*\sid="i-([a-z0-9-]+)"/g)].map((m) => m[1]);
}

/** Sunucuya giden blok tanımı: alanlar, şablon ve her dilde varsayılan örnek (eklenince düzenleyici bunu kopyalar). */
export function blockEntry(name, block, context) {
    const { def } = block;
    return {
        label: def.label,
        description: def.description || '',
        category: def.category,
        icon: def.icon || 'layers',
        library: def.library !== false,
        needs: def.needs || [],
        module: def.module || null,
        scope: def.scope || null,
        source: block.source,
        script: block.script,
        preview: block.preview,
        fields: def.fields || [],
        template: block.template,
        defaults: (({ data, i18n }) => ({ data, i18n }))(instanceOf(block, { type: name }, { id: '', ...context })),
    };
}
