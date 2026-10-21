/**
 * Kayan etiketler: satırın yarısı ekranı dolduracak kadar kopya (gizli, `inert`) eklenir; -50% kayma kesintisiz
 * döner. Süre genişliğe göre ayarlanır, kısa ve uzun satır aynı hızda akar. Hareket azaltmada dokunulmaz.
 */
const PX_PER_SECOND = { slow: 45, normal: 75, fast: 120 };

function fill(row, speed) {
    const track = row.querySelector('.km-marquee-track');
    const sets = track ? [...track.querySelectorAll(':scope > .km-marquee-set')] : [];
    if (!track || !sets.length) return;
    const width = sets[0].getBoundingClientRect().width;
    if (!width) return;
    // Yarım: en az satır genişliği kadar özdeş kopya; iz iki özdeş yarıdan oluşur.
    const half = Math.max(1, Math.ceil(row.clientWidth / width));
    sets.slice(1).forEach((set) => set.remove());
    for (let i = 1; i < half * 2; i++) {
        const copy = sets[0].cloneNode(true);
        copy.setAttribute('aria-hidden', 'true');
        copy.inert = true;
        copy.querySelectorAll('[data-km-field]').forEach((node) => node.removeAttribute('data-km-field'));
        track.append(copy);
    }
    const factor = row.classList.contains('km-marquee-row-3') ? 1.3 : row.classList.contains('km-marquee-row-2') ? 0.85 : 1;
    // CSSOM (CSP satır içi stile kapalı; style özelliğine atama serbest).
    track.style.animationDuration = `${((width * half) / speed) * factor}s`;
}

export function init(block) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const root = block.querySelector('.km-marquee');
    if (!root) return;
    const speed = PX_PER_SECOND[['slow', 'fast'].find((s) => root.classList.contains(`is-${s}`)) || 'normal'];
    const rows = [...root.querySelectorAll('.km-marquee-row')];
    const run = () => rows.forEach((row) => fill(row, speed));
    run();
    // Yazı tipi gelince ve ekran genişleyince yeniden ölç.
    document.fonts?.ready.then(run);
    let width = window.innerWidth;
    const onResize = () => {
        if (Math.abs(window.innerWidth - width) < 40) return;
        width = window.innerWidth;
        run();
    };
    window.addEventListener('resize', onResize, { passive: true });
    return () => window.removeEventListener('resize', onResize);
}
