/**
 * km-lang-switch — dil seçici (ui/lang/lang-menu.html, lang-inline.html).
 *
 * Bağlantılar gerçek `<a href hreflang>` öğeleridir: arama motoru her dilin
 * karşılığını izleyebilir, JavaScript olmadan da çalışır. Tıklanan dil
 * `km_lang` çerezine yazılır (dil önerisi bandı bir daha önermez). Yayında olmayan
 * dil (dil kaydında pasif) gizlenir: `on(code)`.
 */
import { LOCALES } from '@profile/locales.js';
import { isLive } from '../../i18n/client.js';

const COOKIE = 'km_lang';
const ONE_YEAR = 60 * 60 * 24 * 365;

export function rememberLang(lang) {
    if (!LOCALES[lang]) return;
    document.cookie = `${COOKIE}=${lang}; Max-Age=${ONE_YEAR}; Path=/; SameSite=Lax`;
}

export function kmLangSwitch() {
    return {
        open: false,

        toggle() {
            this.open = !this.open;
        },

        close() {
            this.open = false;
        },
        on(code) {
            return isLive(code);
        },

        /** `@click="choose($event)"` — dil tercihini hatırlar; gezinmeyi bağlantı yapar. */
        choose(event) {
            rememberLang(event.currentTarget.getAttribute('hreflang') || '');
        },
    };
}
