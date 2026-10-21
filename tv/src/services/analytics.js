/**
 * Gizlilik dostu analitik (çerezsiz): Plausible ya da Umami.
 *
 * Varsayılan kapalı. Açmak için .env.production:
 *   VITE_ANALYTICS=plausible            (ya da umami)
 *   VITE_ANALYTICS_SRC=https://plausible.io/js/script.js   (Umami: …/script.js)
 *   VITE_ANALYTICS_SITE=kodmob.com      (Umami'de website id)
 * Betik başka alan adındaysa public/.htaccess CSP'sine (script-src ve
 * connect-src) o alan adı eklenmelidir; kendi sunucumuzdaysa gerek yok.
 *
 * Çerez kullanmadıkları için KVKK/GDPR açısından onay bandı gerekmez.
 * Arka planda önceden yüklenen (prerender) sayfa, ziyaretçi gerçekten açana
 * kadar sayılmaz.
 */
const PROVIDER = import.meta.env.VITE_ANALYTICS || '';
const SRC = import.meta.env.VITE_ANALYTICS_SRC || '';
const SITE = import.meta.env.VITE_ANALYTICS_SITE || '';

function inject() {
    const script = document.createElement('script');
    script.defer = true;
    script.src = SRC;
    if (PROVIDER === 'plausible') script.dataset.domain = SITE;
    if (PROVIDER === 'umami') script.dataset.websiteId = SITE;
    document.head.append(script);
}

export function initAnalytics() {
    if (!PROVIDER || !SRC || !SITE || import.meta.env.DEV) return;
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', inject, { once: true });
    } else {
        inject();
    }
}
