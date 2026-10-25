/**
 * Dil önerisi bandı: tarayıcı dili sayfanın dilinden farklıysa ve bu sayfanın
 * o dilde karşılığı varsa, alt köşede o dilde kısa bir öneri gösterir.
 *
 * Sunucu dile göre hiç yönlendirmez (kök adres varsayılan dilin sayfası); bu bant
 * başka dilde gelen ziyaretçi içindir (ör. Google sonucundan). Kayıt sayfasında
 * karşılıklar içerikten gelir: çevirisi olmayan dil önerilmez. Zorla yönlendirmez. Gösterilmez: ziyaretçi dili kendisi seçtiyse (km_lang çerezi)
 * ya da bandı kapattıysa.
 */
import { LOCALES, LANG_COOKIE } from '@profile/locales.js';
import { lang, alternates, bannerText, bannerEnabled } from '../../i18n/client.js';
import { rememberLang } from './lang-switch.js';

const DISMISS_KEY = 'km_lang_banner';

function dismissed() {
    try {
        return localStorage.getItem(DISMISS_KEY) === '1';
    } catch {
        return false;
    }
}

function suggestedLocale() {
    if (!bannerEnabled() || document.cookie.split(/;\s*/).some((c) => c.startsWith(`${LANG_COOKIE}=`)) || dismissed()) return null;
    const current = lang();
    const links = alternates();
    for (const tag of navigator.languages || [navigator.language]) {
        const code = String(tag).slice(0, 2).toLowerCase();
        if (code === current) return null; // tarayıcı zaten sayfanın dilini tercih ediyor
        if (LOCALES[code] && links[code]) return code;
    }
    return null;
}

export function kmLangBanner() {
    return {
        show: false,
        code: '',
        href: '',
        text: '',
        switchLabel: '',
        dismissLabel: '',

        init() {
            const code = suggestedLocale();
            const texts = code ? bannerText(code) : null;
            if (!texts) return;
            Object.assign(this, { code, href: alternates()[code], text: texts.text, switchLabel: texts.switch, dismissLabel: texts.dismiss });
            setTimeout(() => (this.show = true), 1200);
        },

        go() {
            rememberLang(this.code);
        },

        dismiss() {
            this.show = false;
            try {
                localStorage.setItem(DISMISS_KEY, '1');
            } catch {
                /* depolama kapalı */
            }
        },
    };
}
