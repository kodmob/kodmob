/**
 * Özel imleç: nokta + gecikmeli halka + imleci izleyip sönen yaprak/tohum
 * parçacıkları (canvas).
 *
 * - Yalnızca fareyle kullanılan cihazlarda açılır; dokunmatikte hiç kurulmaz.
 * - "Hareketi azalt" tercihinde parçacıklar ve halka gecikmesi kapalıdır.
 * - Bağlantı ve düğme üstünde büyür; yazı alanlarında gizlenir ve tarayıcının
 *   kendi metin imleci görünür.
 * - Animasyon döngüsü yalnızca imleç hareket ederken ya da parçacık varken
 *   çalışır; sekme arka plandayken durur.
 * - Sayfa bazında kapatmak için: <body data-cursor="off">. Çerçevedeki sayfada (Sayfa düzenleyicinin önizlemesi)
 *   kurulmaz: düzenleyicinin seçme / sürükleme imleçleriyle karışır.
 */
const FINE_POINTER = '(hover: hover) and (pointer: fine)';
const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
const INTERACTIVE = 'a, button, [role="button"], label, summary, .choice, .pill, .stack-card';
const TEXT_FIELD = 'input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="button"]):not([type="submit"]), textarea, select, [contenteditable="true"], [contenteditable=""]';

const MAX_PARTICLES = 90;
// Marka tonları: canlı mavi, açık mavi, buz mavisi.
const COLORS = [
    [31, 94, 255],
    [110, 155, 255],
    [185, 206, 255],
];

function createLayer(className, tag = 'div') {
    const el = document.createElement(tag);
    el.className = className;
    el.setAttribute('aria-hidden', 'true');
    document.body.append(el);
    return el;
}

class Particle {
    constructor(x, y) {
        this.x = x;
        this.y = y;
        this.life = 1;
        this.decay = 0.014 + Math.random() * 0.018;
        this.size = 1.4 + Math.random() * 2.4;
        this.vx = (Math.random() - 0.5) * 0.7;
        this.vy = -0.25 - Math.random() * 0.55;
        this.angle = Math.random() * Math.PI * 2;
        this.spin = (Math.random() - 0.5) * 0.08;
        this.leaf = Math.random() > 0.45;
        this.color = COLORS[Math.floor(Math.random() * COLORS.length)];
    }

    update() {
        this.x += this.vx;
        this.y += this.vy;
        this.vy *= 0.99;
        this.angle += this.spin;
        this.life -= this.decay;
    }

    draw(ctx) {
        const [r, g, b] = this.color;
        ctx.fillStyle = `rgba(${r}, ${g}, ${b}, ${Math.max(0, this.life) * 0.6})`;
        ctx.beginPath();
        if (this.leaf) {
            // Yaprak: dönen, uzun elips
            ctx.ellipse(this.x, this.y, this.size * 1.7, this.size * 0.7, this.angle, 0, Math.PI * 2);
        } else {
            ctx.arc(this.x, this.y, this.size * 0.8, 0, Math.PI * 2);
        }
        ctx.fill();
    }
}

export function initCursor() {
    if (!window.matchMedia(FINE_POINTER).matches || document.body.dataset.cursor === 'off' || window.self !== window.top) return;
    const reduced = window.matchMedia(REDUCED_MOTION).matches;

    const dot = createLayer('km-cursor-dot is-hidden');
    const ring = createLayer('km-cursor-ring is-hidden');
    const canvas = reduced ? null : createLayer('km-cursor-canvas', 'canvas');
    const ctx = canvas?.getContext('2d');
    document.documentElement.classList.add('has-custom-cursor');

    let mx = -100;
    let my = -100;
    let rx = -100;
    let ry = -100;
    let lastX = -100;
    let lastY = -100;
    let particles = [];
    let running = false;
    let entered = false;

    // Konum bağımsız `translate` özelliğiyle verilir: CSS'teki `scale` (düğme
    // üstünde büyüme) ondan önce, öğenin merkezinde uygulanır ve konumu
    // büyütmez. (`transform` ile verilseydi scale ötelemeyi de çarpardı.)
    const place = (el, x, y) => {
        const half = el.offsetWidth / 2;
        el.style.translate = `${x - half}px ${y - half}px`;
    };

    function resize() {
        if (!canvas) return;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = window.innerWidth * dpr;
        canvas.height = window.innerHeight * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function frame() {
        // Halka noktayı yumuşakça izler.
        rx += (mx - rx) * (reduced ? 1 : 0.16);
        ry += (my - ry) * (reduced ? 1 : 0.16);
        place(ring, rx, ry);

        if (ctx) {
            ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
            particles = particles.filter((p) => p.life > 0);
            for (const p of particles) {
                p.update();
                p.draw(ctx);
            }
        }

        const settled = Math.abs(mx - rx) < 0.1 && Math.abs(my - ry) < 0.1;
        if (settled && particles.length === 0) {
            running = false;
            return;
        }
        requestAnimationFrame(frame);
    }

    function wake() {
        if (running || document.hidden) return;
        running = true;
        requestAnimationFrame(frame);
    }

    function setHidden(hidden) {
        dot.classList.toggle('is-hidden', hidden);
        ring.classList.toggle('is-hidden', hidden);
    }

    document.addEventListener('pointermove', (event) => {
        if (event.pointerType !== 'mouse') return;
        mx = event.clientX;
        my = event.clientY;
        place(dot, mx, my);
        // İlk harekette halka doğrudan imlecin yerinden başlar (köşeden uçup gelmesin).
        if (!entered) {
            entered = true;
            rx = mx;
            ry = my;
            place(ring, rx, ry);
        }
        if (dot.classList.contains('is-hidden') && !event.target.closest?.(TEXT_FIELD)) setHidden(false);
        // Hareket ettikçe parçacık bırak (durunca bırakmaz).
        if (ctx && Math.hypot(mx - lastX, my - lastY) > 3 && Math.random() < 0.5) {
            particles.push(new Particle(mx, my));
            if (particles.length > MAX_PARTICLES) particles.shift();
        }
        lastX = mx;
        lastY = my;
        wake();
    }, { passive: true });

    document.addEventListener('pointerover', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const onText = !!target.closest(TEXT_FIELD);
        setHidden(onText);
        const active = !onText && !!target.closest(INTERACTIVE);
        dot.classList.toggle('is-active', active);
        ring.classList.toggle('is-active', active);
    });

    document.addEventListener('pointerdown', () => ring.classList.add('is-pressed'));
    document.addEventListener('pointerup', () => ring.classList.remove('is-pressed'));
    document.documentElement.addEventListener('mouseleave', () => setHidden(true));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) wake();
    });
    window.addEventListener('resize', resize);
    resize();
}
