/**
 * Ortak bileşenlerin kaydı. `app.js` her sayfada bir kez çağırır.
 * Her bileşenin canlı örneği /_ui/ sayfasındadır.
 *
 * | Bileşen        | Davranış                     | İşaretleme                           |
 * |----------------|------------------------------|--------------------------------------|
 * | kmHeader       | ui/site/header.js            | shell/site-header.njk                |
 * | kmLangSwitch   | ui/lang/lang-switch.js       | ui/lang/lang-menu.njk, lang-inline   |
 * | kmAccordion    | ui/accordion/accordion.js    | .faq / .faq-item (sections.css)      |
 * | kmFilter       | ui/filter/filter.js          | .pills / .pill                       |
 * | kmCarousel     | ui/carousel/carousel.js      | .carousel-track                      |
 * | kmLightbox     | ui/gallery/lightbox.js       | ui/gallery/lightbox.html             |
 * | kmCountUp      | ui/count-up/count-up.js      | .stat-value                          |
 * | kmSheet        | ui/sheet/sheet.js            | ui/sheet/sheet-open/close.html       |
 * | kmTheme        | ui/theme/theme.js            | tema düğmesi (site-header.njk)       |
 * | kmWhatsapp     | ui/whatsapp/whatsapp.js      | shell/contact-dock.njk               |
 * | kmLangBanner   | ui/lang/lang-banner.js       | shell/contact-dock.njk               |
 * | $store.search  | ui/search/search.js          | ui/search/search.njk (Ctrl+K)        |
 * | $store.confirm | ui/confirm/confirm.js        | ui/confirm/confirm.html (overlays)   |
 * | $store.toast   | stores/toast.js              | ui/toast/toasts.html (overlays)      |
 */
import { kmHeader } from './site/header.js';
import { kmLangSwitch } from './lang/lang-switch.js';
import { kmAccordion } from './accordion/accordion.js';
import { kmFilter } from './filter/filter.js';
import { kmCarousel } from './carousel/carousel.js';
import { kmLightbox } from './gallery/lightbox.js';
import { kmCountUp } from './count-up/count-up.js';
import { kmSheet } from './sheet/sheet.js';
import { kmTheme } from './theme/theme.js';
import { kmWhatsapp } from './whatsapp/whatsapp.js';
import { kmLangBanner } from './lang/lang-banner.js';
import { createSearchStore, bindSearchShortcut } from './search/search.js';
import { createConfirmStore } from './confirm/confirm.js';

export function registerUi(Alpine) {
    Alpine.store('confirm', createConfirmStore());
    Alpine.store('search', createSearchStore());
    bindSearchShortcut(Alpine);
    Alpine.data('kmHeader', kmHeader);
    Alpine.data('kmLangSwitch', kmLangSwitch);
    Alpine.data('kmAccordion', kmAccordion);
    Alpine.data('kmFilter', kmFilter);
    Alpine.data('kmCarousel', kmCarousel);
    Alpine.data('kmLightbox', kmLightbox);
    Alpine.data('kmCountUp', kmCountUp);
    Alpine.data('kmSheet', kmSheet);
    Alpine.data('kmTheme', kmTheme);
    Alpine.data('kmWhatsapp', kmWhatsapp);
    Alpine.data('kmLangBanner', kmLangBanner);
}
