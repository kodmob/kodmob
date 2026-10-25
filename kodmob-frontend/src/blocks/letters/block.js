/**
 * Harf animasyonlu başlık: her satır harflerine ayrılır, sıra numarası CSS değişkeninde (`--i`; CSSOM, CSP satır
 * içi stile kapalı). Ekran okuyucu başlığın tamamını okur (harfler gizli). Animasyon görünür olunca başlar,
 * ekrandan çıkınca durur.
 */
export function init(block) {
    const root = block.querySelector('.km-letters');
    const title = root?.querySelector('.km-letters-title');
    if (!title) return;
    const lines = [...title.querySelectorAll('.km-letters-line')];
    title.setAttribute('aria-label', lines.map((line) => line.textContent.trim()).join(' '));
    for (const line of lines) {
        // Harfler kelime kutularında: dar ekranda satır kelime arasında kırılır, kelimenin ortasında değil.
        const words = line.textContent.trim().split(/\s+/).filter(Boolean);
        const fragment = document.createDocumentFragment();
        let index = 0;
        words.forEach((word, w) => {
            const box = document.createElement('span');
            box.className = 'km-letters-word';
            for (const char of word) {
                const span = document.createElement('span');
                span.className = 'km-letter';
                span.textContent = char;
                span.style.setProperty('--i', String(index++));
                box.append(span);
            }
            fragment.append(box);
            if (w < words.length - 1) {
                fragment.append(' ');
                index++;
            }
        });
        line.setAttribute('aria-hidden', 'true');
        line.replaceChildren(fragment);
    }
    if (!('IntersectionObserver' in window)) {
        root.classList.add('is-in');
        return;
    }
    const observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) root.classList.add('is-in');
        root.classList.toggle('is-paused', !entry.isIntersecting);
    }, { threshold: 0.2 });
    observer.observe(root);
    return () => observer.disconnect();
}
