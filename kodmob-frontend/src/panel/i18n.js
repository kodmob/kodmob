/**
 * Çok dilli panel: panelin metinleri Türkçe yazılır, kişinin seçtiği dil (Profilim) yüklenince ekrandaki metinler o
 * dile çevrilir. Anahtar metnin kendisidir (gettext gibi): şablona anahtar yazılmaz. Sözlük sunucudan gelir
 * (`/panel-lang/<dil>`; frontend-lang.json ile içe aktarılır, kodmob-core Modules\System\PanelLanguage):
 *
 *   { strings: { "Kaydet": "Save", … }, messages: { "km_brief_not_found": "Request not found", … } }
 *
 * - Ekran: yazı düğümleri ve `placeholder` / `title` / `aria-label` öznitelikleri; Alpine'ın sonradan yazdıkları da
 *   (MutationObserver). Kişinin girdiği içerik (form alanları, `[data-no-translate]`, düzenlenebilir alan) çevrilmez.
 * - Sunucu mesajları: hata kodu (`km_…`) ya da metnin kendisiyle (services/http.js `useMessageTranslator`).
 * - Betikten gelen metin: `pt('Türkçe metin')` (toast ve onay pencereleri ekranda zaten çevrilir).
 *
 * Türkçe (varsayılan) seçiliyken hiçbir şey yapılmaz. Çevrilmeyen metin Türkçe kalır.
 */
import { http, useMessageTranslator } from '../services/http.js';

const ATTRS = ['placeholder', 'title', 'aria-label'];
const SKIP = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'SELECT', 'CODE', 'PRE']);

let strings = null;
let messages = null;
let observer = null;
const originals = new WeakMap();

const norm = (text) => text.replace(/\s+/g, ' ').trim();

/** Metnin çevirisi (yoksa kendisi). */
export function pt(text) {
    if (!strings || typeof text !== 'string') return text;
    return strings.get(norm(text)) || text;
}

function skipped(el) {
    return !el || SKIP.has(el.tagName) || el.isContentEditable || el.closest?.('[data-no-translate], .ProseMirror, [contenteditable="true"]');
}

function translateText(node) {
    const raw = node.nodeValue;
    if (!raw || !raw.trim() || skipped(node.parentElement)) return;
    const hit = strings.get(norm(raw));
    if (hit && hit !== raw.trim()) {
        if (!originals.has(node)) originals.set(node, raw);
        const lead = raw.match(/^\s*/)[0];
        const tail = raw.match(/\s*$/)[0];
        node.nodeValue = lead + hit + tail;
    }
}

function translateAttrs(el) {
    if (skipped(el) && !(el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')) return;
    for (const name of ATTRS) {
        const value = el.getAttribute?.(name);
        if (!value) continue;
        const hit = strings.get(norm(value));
        if (hit && hit !== value) el.setAttribute(name, hit);
    }
}

/** Bir ağacın tamamını çevirir. */
export function translateTree(root) {
    if (!strings || !root) return;
    if (root.nodeType === Node.TEXT_NODE) return translateText(root);
    if (root.nodeType !== Node.ELEMENT_NODE) return;
    translateAttrs(root);
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (node.nodeType === Node.TEXT_NODE) translateText(node);
        else translateAttrs(node);
    }
}

function watch() {
    observer?.disconnect();
    observer = new MutationObserver((records) => {
        for (const r of records) {
            if (r.type === 'characterData') translateText(r.target);
            else if (r.type === 'attributes') translateAttrs(r.target);
            else r.addedNodes.forEach((n) => translateTree(n));
        }
    });
    observer.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTRS });
}

/**
 * Panelin dilini kurar: Türkçe dışındaysa sözlüğü indirir, ekranı çevirir ve izler; sunucu mesajlarını çevirir.
 * Sözlük yoksa ya da inmezse Türkçe kalır.
 */
export async function usePanelLanguage(code) {
    if (!code || code === 'tr') return false;
    try {
        const dict = await http.get(`/panel-lang/${encodeURIComponent(code)}`);
        strings = new Map(Object.entries(dict.strings || {}).filter(([, v]) => typeof v === 'string' && v.trim()).map(([k, v]) => [norm(k), v]));
        messages = new Map(Object.entries(dict.messages || {}).filter(([, v]) => typeof v === 'string' && v.trim()));
    } catch {
        return false;
    }
    document.documentElement.lang = code;
    useMessageTranslator((messageCode, text) => (messageCode && messages.get(messageCode)) || strings.get(norm(text)) || text);
    translateTree(document.body);
    watch();
    return true;
}
