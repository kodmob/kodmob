/**
 * Hafif parallax: öğe, kapsayıcısı ekranda kaydıkça farklı hızda kayar.
 *
 *     <div class="relative">
 *       <div data-parallax="0.12">…</div>   ← 0.05 (az) … 0.3 (çok)
 *     </div>
 *
 * Ölçüm öğenin kendisinden değil kapsayıcısından alınır (kendi kaydırması
 * ölçümü etkilemesin). Kaydırma rAF ile tek karede toplanır. Dar ekranda ve
 * "hareketi azalt" tercihinde çalışmaz. Eğim (.fx-tilt) ile aynı öğeye
 * verilmez: ikisi de transform kullanır, kapsayıcıya ayrı verilir.
 */
const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
const WIDE = '(min-width: 768px)';

export function initParallax() {
    const items = [...document.querySelectorAll('[data-parallax]')];
    if (!items.length || window.matchMedia(REDUCED_MOTION).matches || !window.matchMedia(WIDE).matches) return;

    let queued = false;
    const update = () => {
        queued = false;
        const middle = window.innerHeight / 2;
        for (const el of items) {
            const box = (el.parentElement || el).getBoundingClientRect();
            if (box.bottom < -200 || box.top > window.innerHeight + 200) continue;
            const speed = parseFloat(el.dataset.parallax) || 0.1;
            const offset = (box.top + box.height / 2 - middle) * -speed;
            el.style.transform = `translate3d(0, ${offset.toFixed(1)}px, 0)`;
        }
    };
    const queue = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(update);
    };

    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue);
    update();
}
