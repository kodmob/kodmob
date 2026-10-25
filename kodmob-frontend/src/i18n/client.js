/**
 * JavaScript tarafındaki metinler ve sayfa bilgisi.
 *
 * Derleme her sayfaya `<script type="application/json" id="km-i18n">` gömer:
 *   { locale, strings (o dilin client.* sözlüğü), routes (rota → bu dildeki adres),
 *     patterns (kayıt rotası → dil → `/projeler/:slug/`, `/en/projects/:slug/`), labels (kategori adları),
 *     alternates (dil → bu sayfanın adresi), banner (dil önerisi bandı metinleri),
 *     searchPages (arama dizininin sayfa kısmı) }
 * Böylece JS paketi dilden bağımsız kalır, her dil ayrı derlenmez.
 *
 *     import { t, lang, alternates } from '@/i18n/client.js';
 *     toast.success(t('brief.sent'));
 *     t('errors.httpFailed', { status: 500 });
 */
import { LOCALES, DEFAULT_LOCALE } from '@profile/locales.js';

let payload = null;

function data() {
    if (payload) return payload;
    try {
        payload = JSON.parse(document.getElementById('km-i18n')?.textContent || '{}');
    } catch {
        payload = {};
    }
    return payload;
}

/** Sayfanın dili (`<html lang>`), tanımsızsa varsayılan dil. */
export function lang() {
    const code = document.documentElement.lang;
    return LOCALES[code] ? code : DEFAULT_LOCALE;
}

/** Intl biçimlendirme dili: tr-TR, en-GB, de-DE. */
export function intlLocale() {
    return LOCALES[lang()].intl;
}

let live;

/** Sitede yayında olan diller (render.php `#km-data` `languages`; dil kaydı). Bilinmiyorsa null: hepsi. */
function liveLanguages() {
    if (live !== undefined) return live;
    try {
        live = JSON.parse(document.getElementById('km-data')?.textContent || 'null')?.languages || null;
    } catch {
        live = null;
    }
    return live;
}

/** Dil sitede yayında mı (dil seçici ve öneri bandı pasif dili göstermez). */
export function isLive(code) {
    const list = liveLanguages();
    return !list || list.includes(code);
}

/** Dil önerisi bandı açık mı (Çoklu Dil > Genel bakış; render.php `#km-data` `langBanner`). Bilinmiyorsa açık. */
export function bannerEnabled() {
    try {
        return JSON.parse(document.getElementById('km-data')?.textContent || 'null')?.langBanner !== false;
    } catch {
        return true;
    }
}

/** Bu sayfanın yayındaki diğer dillerdeki adresleri: { tr: '/…', en: '/en/…' }. */
export function alternates() {
    return Object.fromEntries(Object.entries(data().alternates || {}).filter(([code]) => isLive(code)));
}

/** Kayıt sayfasında dil karşılıkları içerikten gelir (stores/content.js yazar). */
export function setAlternates(map) {
    data().alternates = { ...map };
}

/** Derlemenin gömdüğü sayfa bilgisinin tamamı (rotalar, etiketler, arama sayfaları). */
export function pageInfo() {
    return data();
}

/**
 * Kayıt sayfasının dil başına adres kalıbı (`post` → { tr: '/blog/:slug/', … }); sitede o kaydın kendi
 * sayfası yoksa null. Kayıt adı → rota adı derlemeden (`records`).
 */
export function recordPatterns(kind) {
    const info = data();
    return info.patterns?.[info.records?.[kind] ?? kind] || null;
}

/** Dil önerisi bandının metinleri (her dil kendi dilinde). */
export function bannerText(code) {
    return data().banner?.[code] || null;
}

export function t(key, params = {}) {
    const value = key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), data().strings || {});
    if (typeof value !== 'string') return value ?? key;
    return value.replace(/\{(\w+)\}/g, (match, name) => (params[name] ?? match));
}
