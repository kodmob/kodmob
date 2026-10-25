/**
 * Blok şablonu: Mustache'ın küçük bir alt kümesi (mantıksız; ifade, işlev, kısmi şablon yok). Aynı kurallar
 * sunucuda da var (kodmob-core Modules\Site\Blocks\Template); iki taraf aynı örneklerle sınanır
 * (kodmob-core/tests/fixtures/blocks/template.json). Derleme bölgelerin varsayılan bloklarını bununla yazar.
 *
 *   {{ yol }}            kaçışlı değer (metin ve öznitelikte güvenli)
 *   {{{ yol }}}          kaçışsız (yalnızca sunucuda temizlenmiş HTML alanı için)
 *   {{# yol }}…{{/ yol }}   liste: her öğe için; nesne: bir kez, onun içinde; doğru değer: bir kez
 *   {{^ yol }}…{{/ yol }}   boşsa (yok, false, '', 0, boş liste)
 *   {{! not }}           yorum
 *
 * Yol: `title`, `cover.src`, `.` (döngüdeki öğenin kendisi), `items.length` (listenin uzunluğu: bir kez saran
 * öğe için `{{#items.length}}<ul>{{#items}}…{{/items}}</ul>{{/items.length}}`), döngüde `@index` (0'dan),
 * `@number` (1'den), `@first`, `@last`. Ad önce en içteki bağlamda, yoksa dıştakilerde aranır.
 */

const TOKEN = /\{\{(\{|#|\^|\/|!)?\s*([^}]*?)\s*\}?\}\}/g;

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' };

/** PHP htmlspecialchars(ENT_QUOTES | ENT_HTML5) ile aynı. */
export const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (c) => ESCAPES[c]);

/** Şablonu ağaca çevirir; kapanmayan ya da yanlış kapanan bölüm hata verir. */
export function parse(template) {
    const root = { children: [] };
    const stack = [root];
    let last = 0;
    for (const m of String(template).matchAll(TOKEN)) {
        const [whole, sigil = '', name] = m;
        const node = stack[stack.length - 1];
        if (m.index > last) node.children.push(template.slice(last, m.index));
        last = m.index + whole.length;
        if (sigil === '!') continue;
        if (sigil === '#' || sigil === '^') {
            const section = { section: name, inverted: sigil === '^', children: [] };
            node.children.push(section);
            stack.push(section);
        } else if (sigil === '/') {
            if (stack.length < 2 || node.section !== name) throw new Error(`Şablon: "{{/${name}}}" beklenmedik (açık bölüm: ${node.section || 'yok'})`);
            stack.pop();
        } else {
            node.children.push({ name, raw: sigil === '{' });
        }
    }
    if (stack.length > 1) throw new Error(`Şablon: "{{#${stack[stack.length - 1].section}}}" kapanmadı`);
    if (last < template.length) root.children.push(template.slice(last));
    return root.children;
}

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

/** Bağlam yığınında yol (en içten dışa). */
function lookup(stack, path) {
    if (path === '.') return stack[stack.length - 1];
    const [head, ...rest] = path.split('.');
    for (let i = stack.length - 1; i >= 0; i--) {
        const ctx = stack[i];
        if (isObject(ctx) && Object.hasOwn(ctx, head)) {
            return rest.reduce((v, key) => {
                if (Array.isArray(v)) return key === 'length' ? v.length : undefined;
                return isObject(v) && Object.hasOwn(v, key) ? v[key] : undefined;
            }, ctx[head]);
        }
    }
    return undefined;
}

const truthy = (v) => !(v === undefined || v === null || v === false || v === '' || v === 0 || (Array.isArray(v) && !v.length));

const text = (v) => (typeof v === 'string' ? v : typeof v === 'number' && Number.isFinite(v) ? String(v) : '');

function renderNodes(nodes, stack) {
    let out = '';
    for (const node of nodes) {
        if (typeof node === 'string') {
            out += node;
        } else if (node.section === undefined) {
            const value = text(lookup(stack, node.name));
            out += node.raw ? value : escapeHtml(value);
        } else {
            const value = lookup(stack, node.section);
            if (node.inverted) {
                if (!truthy(value)) out += renderNodes(node.children, stack);
            } else if (Array.isArray(value)) {
                value.forEach((item, i) => {
                    out += renderNodes(node.children, [...stack, { '@index': i, '@number': i + 1, '@first': i === 0, '@last': i === value.length - 1 }, item]);
                });
            } else if (isObject(value)) {
                if (Object.keys(value).length) out += renderNodes(node.children, [...stack, value]);
            } else if (truthy(value)) {
                out += renderNodes(node.children, stack);
            }
        }
    }
    return out;
}

/** `template`: metin ya da parse() ağacı. */
export function render(template, data = {}) {
    return renderNodes(typeof template === 'string' ? parse(template) : template, [data]);
}
