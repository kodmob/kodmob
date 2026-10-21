/**
 * Paylaşılan öğe geçişi: tıklanan kartın görseli, açılan sayfanın hero
 * görseline "akar" (belgeler arası View Transitions).
 *
 *     <a href="/projeler/harman/">
 *       <div data-vt-media>…kart görseli…</div>
 *     </a>
 *     … detay sayfasında:
 *     <div data-vt-hero>…hero görseli…</div>
 *
 * Aynı `view-transition-name` bir sayfada yalnızca bir öğede olabilir. Detay
 * sayfasında tek hero olduğu için onun adı CSS'te sabittir (effects.css). Çok
 * kartlı sayfada ad, gidilen adrese bağlanan karta `pageswap` anında JS ile
 * verilir (stil CSSOM ile; CSP satır içi stili etkilemez).
 */
const NAME = 'km-media';

function samePath(a, b) {
    try {
        return new URL(a, location.href).pathname === new URL(b, location.href).pathname;
    } catch {
        return false;
    }
}

export function initPageTransitions() {
    if (!('onpageswap' in window)) return;

    window.addEventListener('pageswap', (event) => {
        const destination = event.activation?.entry?.url;
        if (!event.viewTransition || !destination) return;
        const link = [...document.querySelectorAll('a[href]')].find((a) => a.querySelector('[data-vt-media]') && samePath(a.href, destination));
        const media = link?.querySelector('[data-vt-media]');
        if (!media) return;
        media.style.viewTransitionName = NAME;
        event.viewTransition.finished.finally(() => {
            media.style.viewTransitionName = '';
        });
    });
}
