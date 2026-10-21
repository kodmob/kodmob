/**
 * Sayfa düzenleyicinin verisi (tarayıcıdan bağımsız; tests/panel-builder.test.mjs). Blok örneği:
 *
 *   { id: 'k3f9a2', type: 'marquee', hidden: false, data: { ortak alanlar }, i18n: { tr: { dil alanları }, en: {…} } }
 *
 * Sunucudaki karşılıkları kodmob-api Modules\Site\Blocks (BlockData, BlockRenderer); burada yalnızca düzenleme:
 * çalışma kopyası, değer okuma / yazma, kopyalama, geri al.
 */

export const deepClone = (value) => (value === undefined ? undefined : JSON.parse(JSON.stringify(value)));

/** Sunucunun boş nesnesi `[]` gelebilir (PHP); düzenleyicide hep nesne. */
const asObject = (value) => (value && typeof value === 'object' && !Array.isArray(value) ? value : {});

export function normalizeBlock(block) {
    const i18n = asObject(block.i18n);
    return {
        id: String(block.id || newId()),
        type: String(block.type),
        hidden: !!block.hidden,
        data: { ...asObject(block.data) },
        i18n: Object.fromEntries(Object.entries(i18n).map(([code, values]) => [code, { ...asObject(values) }])),
    };
}

export function newId() {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let id = '';
    for (let i = 0; i < 8; i++) id += chars[Math.floor(Math.random() * chars.length)];
    return id;
}

/** Bölgenin düzenlenen hali: taslak, yoksa yayındaki, yoksa derlemenin varsayılan blokları. */
export function workingCopy(zone) {
    const source = zone.draft ?? zone.published ?? zone.defaults ?? [];
    return deepClone(source).map(normalizeBlock);
}

/** Kitaplıktaki tanımdan yeni blok: varsayılan değerler her dilde kopyalanır (düzenleyicide görünsün diye). */
export function blockFromDefaults(def, type) {
    return normalizeBlock({ id: newId(), type, hidden: false, data: deepClone(def.defaults?.data || {}), i18n: deepClone(def.defaults?.i18n || {}) });
}

/** Aynı içerikle yeni kimlik (çoğalt, yapıştır). */
export function copyBlock(block) {
    return { ...deepClone(block), id: newId() };
}

const BLANK = { bool: false, int: 0, rows: [], image: 0 };
export const blank = (field) => (field.type in BLANK ? deepClone(BLANK[field.type]) : '');

/**
 * Alanın görünen değeri: dil alanında o dilin metni, yoksa varsayılan dilinki, yoksa bloğun varsayılanı (sunucudaki
 * BlockRenderer::view ile aynı sıra).
 */
export function valueOf(block, def, field, locale, defaultLocale) {
    const defaults = def?.defaults || {};
    if (field.localized) {
        return block.i18n?.[locale]?.[field.name] ?? block.i18n?.[defaultLocale]?.[field.name]
            ?? defaults.i18n?.[locale]?.[field.name] ?? defaults.i18n?.[defaultLocale]?.[field.name] ?? blank(field);
    }
    return block.data?.[field.name] ?? defaults.data?.[field.name] ?? blank(field);
}

/** Alanı yazar (dil alanı o dile). Yeni nesne döner; liste alanında satırlar da kopyalanır. */
export function setValue(block, field, locale, value) {
    const next = deepClone(block);
    if (field.localized) {
        next.i18n[locale] = { ...(next.i18n[locale] || {}), [field.name]: value };
    } else {
        next.data = { ...next.data, [field.name]: value };
    }
    return next;
}

/**
 * Önizlemedeki `data-km-field` yolu ("title", "items.2.text") → { field, row, column }. Bilinmeyen yol null.
 */
export function resolvePath(def, path) {
    const [name, index, column] = String(path || '').split('.');
    const field = (def?.fields || []).find((f) => f.name === name);
    if (!field) return null;
    if (field.type !== 'rows') return index === undefined ? { field, row: null, column: null } : null;
    const col = (field.columns || []).find((c) => c.name === column);
    return col && /^\d+$/.test(index || '') ? { field, row: Number(index), column: col } : null;
}

/** Yerinde düzenlenen metni bloğa yazar. */
export function setPath(block, def, path, locale, defaultLocale, text) {
    const target = resolvePath(def, path);
    if (!target) return block;
    if (target.row === null) return setValue(block, target.field, locale, text);
    const rows = deepClone(valueOf(block, def, target.field, locale, defaultLocale)) || [];
    if (!rows[target.row]) return block;
    rows[target.row] = { ...rows[target.row], [target.column.name]: text };
    return setValue(block, target.field, locale, rows);
}

/** Satır alanında yeni satır: sütunların varsayılanı ya da boş değeri. */
export function blankRow(field) {
    return Object.fromEntries((field.columns || []).map((c) => [c.name, c.default ?? blank(c)]));
}

const TEXT_TYPES = ['text', 'textarea', 'html'];

/** Bloğun bir dildeki metinleri (Jarvis'e giden; kodmob-api BlockAssist::texts ile aynı biçim). */
export function textsOf(block, def, locale, defaultLocale) {
    const out = {};
    for (const field of def?.fields || []) {
        if (!field.localized) continue;
        const value = valueOf(block, def, field, locale, defaultLocale);
        if (TEXT_TYPES.includes(field.type)) out[field.name] = typeof value === 'string' ? value : '';
        else if (field.type === 'rows') {
            const columns = (field.columns || []).filter((c) => TEXT_TYPES.includes(c.type));
            out[field.name] = (Array.isArray(value) ? value : []).map((row) => Object.fromEntries(columns.map((c) => [c.name, typeof row?.[c.name] === 'string' ? row[c.name] : ''])));
        }
    }
    return out;
}

/**
 * Jarvis'in metinlerini bloğa yazar (bir dil): metin alanları değişir; satırlarda metin sütunları, diğer sütunlar
 * (ikon, bağlantı) aynı sıradaki satırdan, yeni satırda varsayılandan.
 */
export function applyTexts(block, def, locale, defaultLocale, texts) {
    let next = block;
    for (const field of def?.fields || []) {
        if (!field.localized || !(field.name in (texts || {}))) continue;
        const value = texts[field.name];
        if (TEXT_TYPES.includes(field.type) && typeof value === 'string') next = setValue(next, field, locale, value);
        else if (field.type === 'rows' && Array.isArray(value)) {
            const current = valueOf(next, def, field, locale, defaultLocale) || [];
            const columns = (field.columns || []).filter((c) => TEXT_TYPES.includes(c.type)).map((c) => c.name);
            const rows = value.slice(0, field.max || 30).map((row, i) => ({ ...(current[i] || blankRow(field)), ...Object.fromEntries(columns.filter((c) => typeof row?.[c] === 'string').map((c) => [c, row[c]])) }));
            next = setValue(next, field, locale, rows);
        }
    }
    return next;
}

/** Katmanlar listesinde bloğun kısa adı: ilk dolu metin alanı. */
export function summary(block, def, locale, defaultLocale) {
    for (const field of def?.fields || []) {
        if (!['text', 'textarea'].includes(field.type)) continue;
        const text = String(valueOf(block, def, field, locale, defaultLocale) || '').trim();
        if (text) return text.length > 48 ? `${text.slice(0, 46)}…` : text;
    }
    for (const field of def?.fields || []) {
        if (field.type !== 'rows') continue;
        const first = (valueOf(block, def, field, locale, defaultLocale) || [])[0];
        const text = first && Object.values(first).find((v) => typeof v === 'string' && v.trim() && !v.startsWith('route:'));
        if (text) return text.length > 48 ? `${text.slice(0, 46)}…` : text;
    }
    return '';
}

/**
 * Bölgenin adı: `site.*` bütün sayfalar, `<sayfa>.top|bottom` bu sayfanın üstü / altı, diğerleri derlemedeki etiket.
 */
export function zoneLabel(key, zone, page) {
    const [route, part] = key.split('.');
    if (route === 'site') return part === 'top' ? 'Bütün sayfalar · üstte' : part === 'bottom' ? 'Bütün sayfalar · altta' : zone?.label || key;
    if (page && route === page.name && (part === 'top' || part === 'bottom')) return part === 'top' ? 'Sayfanın üstü' : 'Sayfanın altı';
    return zone?.label || key;
}

/** Bütün sayfalarda ortak bölge mi (düzenleyicide uyarı rozeti). */
export const isGlobal = (key) => key.startsWith('site.');

/**
 * Yeni blok nereye: seçili bloğun ardına; seçim yoksa sayfanın kendi bölgesinin (ör. home.main) sonuna, o yoksa
 * sayfanın altına.
 */
export function insertTarget(page, layout, selected) {
    if (selected && layout[selected.zone]) {
        const index = layout[selected.zone].findIndex((b) => b.id === selected.id);
        return { zone: selected.zone, index: index === -1 ? layout[selected.zone].length : index + 1 };
    }
    const zones = page?.zones || [];
    const own = zones.find((k) => !isGlobal(k) && !/\.(top|bottom)$/.test(k)) || zones.find((k) => k.endsWith('.bottom') && !isGlobal(k)) || zones[0];
    return own ? { zone: own, index: (layout[own] || []).length } : null;
}

/** Bloğu bölgeler arasında taşır (aynı bölgede sıra değişir). Yeni düzen döner. */
export function moveBlock(layout, from, to) {
    const next = { ...layout, [from.zone]: [...(layout[from.zone] || [])] };
    const index = next[from.zone].findIndex((b) => b.id === from.id);
    if (index === -1) return layout;
    const [block] = next[from.zone].splice(index, 1);
    next[to.zone] = from.zone === to.zone ? next[from.zone] : [...(layout[to.zone] || [])];
    let at = to.index;
    if (from.zone === to.zone && index < at) at -= 1;
    next[to.zone].splice(Math.max(0, Math.min(at, next[to.zone].length)), 0, block);
    return next;
}

/** Geri al / yinele: düzenin anlık görüntüleri (bölge → bloklar), en çok `limit`. */
export function createHistory(limit = 60) {
    const past = [];
    const future = [];
    return {
        push(snapshot) {
            past.push(JSON.stringify(snapshot));
            if (past.length > limit) past.shift();
            future.length = 0;
        },
        undo(current) {
            if (!past.length) return null;
            future.push(JSON.stringify(current));
            return JSON.parse(past.pop());
        },
        redo(current) {
            if (!future.length) return null;
            past.push(JSON.stringify(current));
            return JSON.parse(future.pop());
        },
        get canUndo() {
            return past.length > 0;
        },
        get canRedo() {
            return future.length > 0;
        },
        clear() {
            past.length = 0;
            future.length = 0;
        },
    };
}

const TR_CHARS = { ç: 'c', ğ: 'g', ı: 'i', ö: 'o', ş: 's', ü: 'u', â: 'a', î: 'i', û: 'u' };

/** Başlık → adres parçası ("Yaz Kampanyası" → "yaz-kampanyasi"); sunucudaki Text::slug ile aynı kural (önizleme). */
export function slugify(value) {
    return String(value || '')
        .toLocaleLowerCase('tr')
        .replace(/[çğıöşüâîû]/g, (c) => TR_CHARS[c])
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
