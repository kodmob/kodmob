/**
 * TEST PROFİLİ (birim testleri; tests/profile-hook.mjs): kodmob-api'nin test sitesiyle (tests/fixtures/site) aynı
 * diller. Çatının testleri etkin profile (tv/profile.js) bağlı değildir; portföy sitesi örneği (projeler, hizmetler,
 * referanslar). Sitenin gerçek profili tv/src/profiles/<ad>/'dadır.
 */
export const LOCALES = {
    tr: { prefix: '', label: 'Türkçe', short: 'TR', intl: 'tr-TR', og: 'tr_TR', dir: 'ltr', flag: '/assets/img/flags/tr.svg' },
    en: { prefix: 'en', label: 'English', short: 'EN', intl: 'en-GB', og: 'en_GB', dir: 'ltr', flag: '/assets/img/flags/en.svg' },
    de: { prefix: 'de', label: 'Deutsch', short: 'DE', intl: 'de-DE', og: 'de_DE', dir: 'ltr', flag: '/assets/img/flags/de.svg' },
};

export const LOCALE_CODES = Object.keys(LOCALES);
export const DEFAULT_LOCALE = 'tr';

/** Ziyaretçinin seçtiği dil (dil seçici yazar; dil önerisi bandı okur). Sunucu bununla yönlendirme yapmaz. */
export const LANG_COOKIE = 'km_lang';
