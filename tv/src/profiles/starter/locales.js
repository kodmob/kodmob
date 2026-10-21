/**
 * Sitenin dilleri — tek kaynak. Derleme (vite/i18n-pages.js) ve istemci (@profile/locales.js) buradan okur.
 *
 * Yeni dil: buraya bir satır, profilin `i18n/<kod>.json` sözlüğü (çatının `tv/src/i18n/<kod>.json`'ı da
 * olmalı), routes.config.js'teki her rotaya o dilin yolu. Eksik çeviri derlemeyi durdurur. Derlemeden sonra
 * dil panelde (Çoklu Dil) pasif görünür; oradan yayına alınır.
 *
 * `prefix`: adres öneki; varsayılan dil öneksizdir. `dir`: yazı yönü (`ltr` | `rtl`).
 */
export const LOCALES = {
    tr: { prefix: '', label: 'Türkçe', short: 'TR', intl: 'tr-TR', og: 'tr_TR', dir: 'ltr', flag: '/assets/img/flags/tr.svg' },
    en: { prefix: 'en', label: 'English', short: 'EN', intl: 'en-GB', og: 'en_GB', dir: 'ltr', flag: '/assets/img/flags/en.svg' },
};

export const LOCALE_CODES = Object.keys(LOCALES);
export const DEFAULT_LOCALE = 'tr';

/** Ziyaretçinin seçtiği dil (dil seçici yazar; dil önerisi bandı okur). */
export const LANG_COOKIE = 'km_lang';
