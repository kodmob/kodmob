/**
 * Projenin panel uzantısı (profilin panel.js'i; tarayıcıda `@profile/panel.js`, Node testlerinde
 * tests/profile-hook.mjs) ve araç paketlerinin sayfaları (`virtual:kodmob-extensions`; vite/extensions.js).
 * Yüklenince içerik türleri ve özel sayfalar adres tablosuna (routes.js) verilir; panelin giriş dosyası onu
 * erkenden yükler. Biçim: extension.js.
 */
import * as panel from '@profile/panel.js';
import tools from 'virtual:kodmob-extensions';
import { panelTypes, projectPages, toolPages } from './extension.js';
import { usePages, useTypes } from './routes.js';

/** Panelin bütün içerik türleri: projeninkiler + blog. */
export const TYPES = panelTypes(panel.TYPES);

/** Projenin ve araç paketlerinin özel sayfaları (menü, Tüm İşlemler, tur, arama, tembel yükleme). */
export const PROJECT_PAGES = [...projectPages(panel.PAGES), ...toolPages(tools)];

useTypes(TYPES);
usePages(PROJECT_PAGES);
