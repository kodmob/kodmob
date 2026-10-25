/**
 * Arama eşleştirmesi (panel araması, Diğer İşlemler süzgeci). Türkçe karakterlerden ve büyük
 * küçük harften bağımsız: "sifre" → "Şifre", "IP" → "ip", "yonlendirme" → "Yönlendirmeler".
 * Sorgu kelimelere bölünür; her kelime başlıkta ya da ek metinde (yol, açıklama, eş anlamlılar)
 * geçmelidir. Başlıkta baştan eşleşme en üstte.
 */
const MAP = {
    ı: 'i', İ: 'i', I: 'i', ş: 's', Ş: 's', ğ: 'g', Ğ: 'g', ç: 'c', Ç: 'c', ö: 'o', Ö: 'o', ü: 'u', Ü: 'u',
    â: 'a', Â: 'a', î: 'i', Î: 'i', û: 'u', Û: 'u',
};

/** Küçük harf ve sade karakter; uzunluk değişmez (eşleşen yerler özgün metinde işaretlenebilsin). */
export function fold(text) {
    let out = '';
    for (const ch of String(text ?? '').split('')) {
        const mapped = MAP[ch];
        if (mapped) {
            out += mapped;
            continue;
        }
        const lower = ch.toLowerCase();
        out += lower.length === 1 ? lower : ch;
    }
    return out;
}

export const terms = (query) => fold(query).split(/[\s,]+/).filter(Boolean);

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const wordStart = (text, term) => new RegExp(`(^|[^a-z0-9])${escapeRe(term)}`).test(text);

/**
 * Puan: 0 ise eşleşmez. `entry`: { title, text } (text: yol, açıklama, eş anlamlılar).
 * Kelime başına: başlık tam 120, başlık başı 90, başlıkta kelime başı 70, başlıkta 45,
 * ek metinde kelime başı 25, ek metinde 12. Bütün sorgu başlıkta geçiyorsa +30.
 */
export function score(entry, query) {
    const list = Array.isArray(query) ? query : terms(query);
    if (!list.length) return 0;
    const title = fold(entry.title);
    const text = fold(entry.text || '');
    let total = 0;
    for (const term of list) {
        if (title === term) total += 120;
        else if (title.startsWith(term)) total += 90;
        else if (wordStart(title, term)) total += 70;
        else if (title.includes(term)) total += 45;
        else if (wordStart(text, term)) total += 25;
        else if (text.includes(term)) total += 12;
        else return 0;
    }
    if (list.length > 1 && title.includes(list.join(' '))) total += 30;
    return total;
}

/** En iyi eşleşenler, puana göre (eşitlikte özgün sıra). */
export function rank(entries, query, limit = Infinity) {
    const list = terms(query);
    if (!list.length) return [];
    return entries
        .map((entry, index) => ({ entry, index, s: score(entry, list) }))
        .filter((r) => r.s > 0)
        .sort((a, b) => b.s - a.s || a.index - b.index)
        .slice(0, limit)
        .map((r) => r.entry);
}

const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** Metni HTML'e çevirir, sorgudaki kelimelerin geçtiği yerleri <mark> ile sarar (x-html için güvenli). */
export function mark(text, query) {
    const value = String(text ?? '');
    const folded = fold(value);
    const ranges = [];
    for (const term of terms(query)) {
        let at = folded.indexOf(term);
        while (at !== -1) {
            ranges.push([at, at + term.length]);
            at = folded.indexOf(term, at + term.length);
        }
    }
    if (!ranges.length) return escapeHtml(value);
    ranges.sort((a, b) => a[0] - b[0]);
    const merged = [ranges[0]];
    for (const [start, end] of ranges.slice(1)) {
        const last = merged[merged.length - 1];
        if (start <= last[1]) last[1] = Math.max(last[1], end);
        else merged.push([start, end]);
    }
    let html = '';
    let pos = 0;
    for (const [start, end] of merged) {
        html += escapeHtml(value.slice(pos, start)) + '<mark>' + escapeHtml(value.slice(start, end)) + '</mark>';
        pos = end;
    }
    return html + escapeHtml(value.slice(pos));
}
