/**
 * Panelin metin kataloğu (çok dilli panel; kodmob-core Modules\System\PanelLanguage): panel şablonlarındaki ve
 * betiklerindeki Türkçe arayüz metinlerini toplar, derlemede `km-panel-strings.json` olarak yazar. Çeviri dosyası
 * (frontend-lang.json) bu listeden üretilir; anahtar metnin kendisidir (gettext gibi), şablonlara anahtar yazılmaz.
 *
 * Toplanan: etiketler arasındaki yazı, `placeholder` / `title` / `aria-label` / `label` öznitelikleri, Alpine
 * ifadelerindeki ve betiklerdeki tırnaklı metinler. Bir metin sayılır: harfle başlar, en az iki harf, içinde boşluk ya
 * da Türkçe harf ya da büyük harfle başlıyor; adres, sınıf adı, anahtar gibi görünenler atlanır. Fazladan toplanan
 * metin zararsızdır (çevrilmezse kendisi kalır).
 */
import fs from 'node:fs';
import path from 'node:path';

const TURKISH = /[çğıöşüÇĞİÖŞÜ]/;

/** Arayüz metni gibi mi görünüyor. */
export function looksLikeText(raw) {
    const text = raw.replace(/\s+/g, ' ').trim();
    if (text.length < 2 || text.length > 300) return false;
    if (!/^[\p{L}"(«0-9]/u.test(text) || !/\p{L}{2}/u.test(text)) return false;
    if (/[{}<>]|=>|\$\{|^\w+\(|^[a-z0-9_.:#/-]+$|^https?:|^\/|\.(js|css|svg|png|json|html|njk)$|^km_|^[a-z]+(-[a-z0-9]+)+$/.test(text)) return false;
    // Tek sözcük: büyük harfle başlıyor ya da Türkçe harf içeriyor (ör. "Kaydet", "Sil", "aç").
    if (!text.includes(' ')) return /^\p{Lu}/u.test(text) || TURKISH.test(text);
    // Birden çok sözcük: sınıf listesi değil (çoğu küçük harf ve tireli).
    return !/^[a-z0-9:/_[\]().!-]+( [a-z0-9:/_[\]().!-]+)+$/.test(text) || TURKISH.test(text);
}

const add = (set, raw) => {
    const text = String(raw).replace(/\s+/g, ' ').trim();
    if (looksLikeText(text)) set.add(text);
};

/** Şablondan (njk / html) metinler. */
export function fromTemplate(source, set = new Set()) {
    const clean = source.replace(/\{#[\s\S]*?#\}/g, ' ').replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<style[\s\S]*?<\/style>/gi, ' ');
    for (const m of clean.matchAll(/>([^<>]+)</g)) {
        // Nunjucks ifadeleri ayrılır, aradaki düz yazılar alınır.
        for (const part of m[1].split(/\{\{[\s\S]*?\}\}|\{%[\s\S]*?%\}/)) add(set, part);
    }
    for (const m of clean.matchAll(/\s(?:placeholder|title|aria-label|label|alt)="([^"{]+)"/g)) add(set, m[1]);
    // Alpine / Nunjucks ifadelerindeki tırnaklı metinler (x-text, :title, t('…') hariç).
    for (const m of clean.matchAll(/(?:x-text|x-html|:title|:placeholder|:aria-label|x-show|@click|:class|:label)="([^"]*)"/g)) fromScript(m[1], set);
    return set;
}

/** Betikten (js) tırnaklı metinler; yorumlar ve içe aktarmalar atlanır. */
export function fromScript(source, set = new Set()) {
    const clean = source.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/^\s*\/\/.*$/gm, ' ').replace(/^\s*import .*$/gm, ' ');
    for (const m of clean.matchAll(/'((?:[^'\\\n]|\\.)*)'|"((?:[^"\\\n]|\\.)*)"|`([^`$]*)`/g)) {
        const text = (m[1] ?? m[2] ?? m[3] ?? '').replace(/\\(.)/g, '$1');
        add(set, text);
    }
    return set;
}

function walk(dir, exts, out = []) {
    if (!fs.existsSync(dir)) return out;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full, exts, out);
        else if (exts.some((e) => entry.name.endsWith(e))) out.push(full);
    }
    return out;
}

/** Katalog: sıralı, tekil metinler. `dirs`: panelin şablon ve betik klasörleri. */
export function collectPanelStrings(dirs) {
    const set = new Set();
    for (const dir of dirs) {
        // Rehberler (panel/guides) uzun belgedir: cümle parçası olarak çevrilmez.
        for (const file of walk(dir, ['.njk', '.html', '.js']).filter((f) => !/[\\/]guides[\\/]/.test(f))) {
            const source = fs.readFileSync(file, 'utf-8');
            if (file.endsWith('.js')) fromScript(source, set);
            else fromTemplate(source, set);
        }
    }
    return [...set].sort((a, b) => a.localeCompare(b, 'tr'));
}

/** Vite eklentisi: derlemede km-panel-strings.json. */
export function panelStrings({ dirs }) {
    return {
        name: 'km-panel-strings',
        apply: 'build',
        generateBundle() {
            this.emitFile({ type: 'asset', fileName: 'km-panel-strings.json', source: JSON.stringify({ strings: collectPanelStrings(dirs) }) });
        },
    };
}
