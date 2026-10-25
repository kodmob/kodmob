/**
 * Kaydırınca belirme: `.reveal` öğesi görünür alana girince aşağıdan yukarı
 * süzülerek belirir. Sıra için `.reveal-delay-1…4`.
 *
 *     <div class="reveal">…</div>
 *     <article class="reveal reveal-delay-2">…</article>
 *
 * - Sayfa açıldığında zaten ekranda olan öğeler hemen görünür (yanıp sönme
 *   olmaz); ilk ekran için CSS'teki `.fade-up` yükleme animasyonu kullanılır.
 * - JavaScript yoksa ya da "hareketi azalt" açıksa içerik doğrudan görünür:
 *   gizleme yalnızca `html.reveal-ready` varken uygulanır.
 * - Sonradan eklenen `.reveal` öğeleri de izlenir.
 */
const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';

export function initReveal() {
    const pending = () => document.querySelectorAll('.reveal:not(.is-visible)');
    if (window.matchMedia(REDUCED_MOTION).matches || !('IntersectionObserver' in window)) {
        pending().forEach((el) => el.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        }
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    // Önce bütün konumlar okunur, sonra sınıflar yazılır: okuma ile yazma karışırsa her okuma tarayıcıyı yeniden
    // yerleşim hesaplamaya zorlar (açılışta onlarca kez).
    const height = window.innerHeight;
    const items = [...pending()].map((el) => {
        const rect = el.getBoundingClientRect();
        return [el, rect.top < height && rect.bottom > 0];
    });
    for (const [el, inView] of items) {
        if (inView) el.classList.add('is-visible');
        else observer.observe(el);
    }
    document.documentElement.classList.add('reveal-ready');

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (!(node instanceof Element)) continue;
                if (node.matches('.reveal:not(.is-visible)')) observer.observe(node);
                node.querySelectorAll?.('.reveal:not(.is-visible)').forEach((el) => observer.observe(el));
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
}
