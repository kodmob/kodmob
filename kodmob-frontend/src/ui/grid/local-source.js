/**
 * Küçük listeler (blog yazıları, oturumlar) ve vitrin sayfası (/_ui/) için bellek içi veri
 * kaynağı. Sunucu sözleşmesinin aynısını üretir (`{ items, total, totals,
 * facets }`); böylece bir liste sonradan sunucuya taşınınca yalnızca `source`
 * değişir.
 *
 *     source: localSource(rows, { search: ['title', 'slug'], facets: ['status'], sums: ['amount'] })
 *
 * Filtre anlamı sunucudaki `TableRepository` ile aynıdır: değer dizi ya da
 * virgüllü metinse IN, `<alan>_from` / `<alan>_to` aralık.
 *
 * `group`: grup alanı (ya da (row) => anahtar). Sıralamadan sonra aynı gruptaki satırlar ilk
 * görüldükleri yerde bir araya alınır; böylece gruplu tabloda bir grup sayfalara dağılmaz.
 */
const lower = (v) => String(v ?? '').toLocaleLowerCase('tr-TR');

function matches(row, key, value) {
    if (key.endsWith('_from')) return String(row[key.slice(0, -5)] ?? '') >= String(value);
    if (key.endsWith('_to')) {
        const to = /^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value} 23:59:59` : value;
        return String(row[key.slice(0, -3)] ?? '') <= to;
    }
    const list = Array.isArray(value) ? value : String(value).split(',');
    return list.map(String).includes(String(row[key]));
}

export function localSource(rows, { search = [], facets = [], sums = [], delay = 0, group = null } = {}) {
    return async (query) => {
        if (delay) await new Promise((r) => setTimeout(r, delay));
        const { page = 1, per_page: perPage = 20, sort, order = 'desc', q, ...filters } = query;
        const apply = (skipKey) => rows.filter((row) => {
            for (const [key, value] of Object.entries(filters)) {
                if (key !== skipKey && !matches(row, key, value)) return false;
            }
            if (q && search.length) {
                const needle = lower(q);
                return search.some((k) => lower(row[k]).includes(needle));
            }
            return true;
        });

        let list = apply(null);
        if (sort) {
            const dir = order === 'asc' ? 1 : -1;
            list = [...list].sort((a, b) => {
                const x = a[sort];
                const y = b[sort];
                if (typeof x === 'number' && typeof y === 'number') return (x - y) * dir;
                return String(x ?? '').localeCompare(String(y ?? ''), 'tr', { numeric: true }) * dir;
            });
        }

        if (group) {
            const keyOf = typeof group === 'function' ? group : (r) => r[group];
            const order = [];
            const members = new Map();
            for (const row of list) {
                const key = String(keyOf(row) ?? '');
                if (!members.has(key)) {
                    members.set(key, []);
                    order.push(key);
                }
                members.get(key).push(row);
            }
            list = order.flatMap((key) => members.get(key));
        }

        const totals = Object.fromEntries(sums.map((k) => [k, list.reduce((acc, r) => acc + (Number(r[k]) || 0), 0)]));
        const facetCounts = Object.fromEntries(facets.map((k) => {
            const counts = {};
            apply(k).forEach((r) => {
                counts[r[k]] = (counts[r[k]] || 0) + 1;
            });
            return [k, counts];
        }));
        const start = (page - 1) * perPage;
        return { items: list.slice(start, start + perPage), total: list.length, totals, facets: facetCounts };
    };
}
