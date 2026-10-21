/**
 * Sayfa efektleri: kaydırınca belirme, parallax, özel imleç, sayfa geçişleri.
 * `app.js` Alpine başladıktan sonra bir kez çağırır (x-for içerikleri hazır olur).
 */
import { initReveal } from './reveal.js';
import { initParallax } from './parallax.js';
import { initCursor } from './cursor.js';
import { initPageTransitions } from './page-transitions.js';

export function initEffects() {
    initReveal();
    initParallax();
    initCursor();
    initPageTransitions();
}
