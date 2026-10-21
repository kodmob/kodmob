/**
 * Ekran turu: arka plan bulanık, hedef öğe açıkta, yanında açıklama kartı.
 *
 *     kmGuideTour() { return tour({ steps: () => [...], autoStart: true }); }
 *
 * Adım: `{ id, title, text, target?, area?, eyebrow?, flow? }`
 * - `target`: `[data-tour="…"]` değeri; görünen ilk eşleşme seçilir (masaüstü menü ya da mobil
 *   çekmece). Hedefsiz adım ekranın ortasında açılır. Hedefi görünmeyen adım (ör. dar ekranda
 *   gizli düğme) atlanır.
 * - `area: 'nav'`: menü öğesi. Mobilde çekmece açılır, masaüstünde kapalı gruplar açık görünür
 *   (kmPanel `km-tour-nav`; kişinin kayıtlı menü tercihi değişmez).
 *
 * Başarım: hedef yalnızca adım değişirken (kısa bir rAF döngüsüyle; çekmece ve grup animasyonunu
 * izler), pencere boyutu değişince ve kaydırınca ölçülür. Dört gölge paneli ve halka konumla
 * taşınır (`:style` nesnesi: CSP satır içi stile kapalı, CSSOM serbest). Tur kapalıyken dinleyici yok.
 *
 * Olaylar: `km-tour-start` (pencere) turu açar; tur bitince `km-tour-end` yayınlanır.
 * Otomatik oynatma: açıkken adımlar metnin uzunluğuna göre 5–12 sn'de ilerler, son adımda durur;
 * Boşluk tuşu oynatır / duraklatır. Tercih tarayıcıda saklanır.
 */
import { storage } from '../services/storage.js';

const AUTOPLAY_KEY = 'km_tour_autoplay';
const PAD = 6;
const GAP = 14;
const EDGE = 12;
const FOLLOW_MS = 480;
const WIDE = '(min-width: 1024px)';

export function tour({ steps, autoStart = false } = {}) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        reduced: false,
        onViewport: null,
        onKey: null,
        onStart: null,
        autoTimer: null,
        frame: null,
        open: false,
        index: 0,
        list: [],
        rect: null,
        card: { top: 0, left: 0 },
        vw: window.innerWidth,
        vh: window.innerHeight,
        placed: false,
        autoplay: false,
        autoMs: 0,
        autoKey: 0,
        direction: 1,

        init() {
            this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.onViewport = () => this.schedule();
            this.onKey = (e) => this.key(e);
            this.onStart = () => this.start();
            window.addEventListener('km-tour-start', this.onStart);
            this.list = steps();
            this.autoplay = storage.getJSON(AUTOPLAY_KEY, false) === true;
            if (autoStart) this.$nextTick(() => this.start());
        },

        destroy() {
            window.removeEventListener('km-tour-start', this.onStart);
            if (this.open) this.close();
        },

        get step() {
            return this.list[this.index] || null;
        },
        get total() {
            return this.list.length;
        },
        get isFirst() {
            return this.index === 0;
        },
        get isLast() {
            return this.index === this.total - 1;
        },
        get progress() {
            return { width: `${Math.round(((this.index + 1) / Math.max(this.total, 1)) * 100)}%` };
        },

        /* Gölge panelleri: hedefin dört yanı. Hedef yoksa üst panel bütün ekranı örter. */
        get shadeTop() {
            const r = this.rect;
            return px({ top: 0, left: 0, width: this.vw, height: r ? Math.max(r.top, 0) : this.vh });
        },
        get shadeBottom() {
            const r = this.rect;
            const top = r ? r.top + r.height : this.vh;
            return px({ top, left: 0, width: this.vw, height: Math.max(this.vh - top, 0) });
        },
        get shadeLeft() {
            const r = this.rect;
            return px(r ? { top: r.top, left: 0, width: Math.max(r.left, 0), height: r.height } : { top: 0, left: 0, width: 0, height: 0 });
        },
        get shadeRight() {
            const r = this.rect;
            const left = r ? r.left + r.width : this.vw;
            return px(r ? { top: r.top, left, width: Math.max(this.vw - left, 0), height: r.height } : { top: 0, left: this.vw, width: 0, height: 0 });
        },
        get ring() {
            const r = this.rect;
            return r ? px(r) : px({ top: this.vh / 2, left: this.vw / 2, width: 0, height: 0 });
        },
        get cardStyle() {
            return px(this.card);
        },

        start(at = 0) {
            if (!this.list.length) return;
            if (!this.open) {
                this.open = true;
                this.placed = false;
                document.documentElement.classList.add('tour-open');
                window.addEventListener('resize', this.onViewport, { passive: true });
                document.addEventListener('scroll', this.onViewport, { passive: true, capture: true });
                window.addEventListener('keydown', this.onKey, true);
            }
            this.go(at);
        },

        next() {
            if (this.isLast) this.finish();
            else this.go(this.index + 1, 1);
        },

        prev() {
            if (!this.isFirst) this.go(this.index - 1, -1);
        },

        toggleAutoplay() {
            this.autoplay = !this.autoplay;
            storage.setJSON(AUTOPLAY_KEY, this.autoplay);
            this.armAutoplay();
        },

        /**
         * Bu adımın süresi dolunca ilerle. Son adımda durur ("Rehberlere geç" elle basılır). Duraklat düğmesindeki
         * halka kalan süreyi gösterir: `autoKey` her kurulumda değişir, halka yeniden çizilip baştan başlar.
         */
        armAutoplay() {
            clearTimeout(this.autoTimer);
            if (!this.open || !this.autoplay || this.isLast) return;
            const length = (this.step?.title || '').length + (this.step?.text || '').length;
            this.autoMs = Math.min(12000, Math.max(5000, 2500 + length * 55));
            this.autoKey++;
            this.autoTimer = setTimeout(() => this.next(), this.autoMs);
        },

        /** Geri sayım halkası çizilsin mi (tek öğeli liste: anahtar değişince halka yeniden kurulur). */
        get timer() {
            return this.open && this.autoplay && !this.isLast ? [this.autoKey] : [];
        },

        finish() {
            if (!this.open) return;
            this.close();
            window.dispatchEvent(new CustomEvent('km-tour-end'));
        },

        /** Turu kapatır: menü eski haline, dinleyiciler kalkar. */
        close() {
            this.open = false;
            this.rect = null;
            window.removeEventListener('resize', this.onViewport);
            document.removeEventListener('scroll', this.onViewport, { capture: true });
            window.removeEventListener('keydown', this.onKey, true);
            cancelAnimationFrame(this.frame);
            clearTimeout(this.autoTimer);
            document.documentElement.classList.remove('tour-open');
            window.dispatchEvent(new CustomEvent('km-tour-nav', { detail: { drawer: false, expand: false } }));
        },

        key(e) {
            const actions = { Escape: () => this.finish(), ArrowRight: () => this.next(), ArrowLeft: () => this.prev() };
            if (e.key === ' ' && e.target?.tagName !== 'BUTTON') actions[' '] = () => this.toggleAutoplay();
            if (!actions[e.key]) return;
            // Yakalama evresinde: panelin kısayolları (Esc çekmeceyi, `/` aramayı açar) tur sürerken çalışmasın.
            e.preventDefault();
            e.stopImmediatePropagation();
            actions[e.key]();
        },

        /** `direction`: hedefi görünmeyen adım atlanırken hangi yöne gidileceği. */
        go(i, direction = this.direction) {
            this.index = i;
            this.direction = direction;
            this.armAutoplay();
            const step = this.step;
            const mobile = !window.matchMedia(WIDE).matches;
            const nav = step.area === 'nav';
            const opening = mobile && nav && !document.querySelector('[data-tour-drawer]')?.getClientRects().length;
            window.dispatchEvent(new CustomEvent('km-tour-nav', { detail: { drawer: mobile && nav, expand: nav } }));

            this.$nextTick(() => {
                const el = this.target();
                if (step.target && !nav && !el) {
                    const skip = this.index + this.direction;
                    if (skip >= 0 && skip < this.total) return this.go(skip, this.direction);
                }
                if (el) el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: this.reduced || opening ? 'auto' : 'smooth' });
                this.follow(opening ? FOLLOW_MS + 200 : FOLLOW_MS);
                this.$refs.next?.focus({ preventScroll: true });
            });
        },

        target() {
            const id = this.step?.target;
            if (!id) return null;
            return [...document.querySelectorAll(`[data-tour="${id}"]`)].find((el) => el.getClientRects().length > 0) || null;
        },

        /** Animasyon bitene kadar her karede ölç (çekmece, grup açılması, kaydırma). */
        follow(ms) {
            cancelAnimationFrame(this.frame);
            const until = performance.now() + ms;
            const tick = () => {
                this.measure();
                if (performance.now() < until) this.frame = requestAnimationFrame(tick);
            };
            tick();
        },

        schedule() {
            if (!this.open) return;
            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => this.measure());
        },

        measure() {
            this.vw = window.innerWidth;
            this.vh = window.innerHeight;
            const el = this.target();
            if (el) {
                const r = el.getBoundingClientRect();
                this.rect = { top: r.top - PAD, left: r.left - PAD, width: r.width + PAD * 2, height: r.height + PAD * 2 };
            } else {
                this.rect = null;
            }
            this.place();
        },

        /** Kart: hedefin sağına, sığmazsa altına, üstüne; telefonda altta ya da üstte tam genişlik. */
        place() {
            const cardEl = this.$refs.card;
            if (!cardEl) return;
            const w = cardEl.offsetWidth;
            const h = cardEl.offsetHeight;
            const { vw, vh } = this;
            const r = this.rect;
            const clampX = (x) => Math.min(Math.max(x, EDGE), Math.max(vw - w - EDGE, EDGE));
            const clampY = (y) => Math.min(Math.max(y, EDGE), Math.max(vh - h - EDGE, EDGE));
            let pos;

            if (!r) {
                pos = { top: clampY((vh - h) / 2), left: clampX((vw - w) / 2) };
            } else if (vw < 640) {
                const below = r.top + r.height / 2 < vh / 2;
                pos = { top: below ? clampY(vh - h - EDGE) : EDGE, left: clampX((vw - w) / 2) };
            } else if (r.left + r.width + GAP + w <= vw - EDGE) {
                pos = { top: clampY(r.top + r.height / 2 - h / 2), left: r.left + r.width + GAP };
            } else if (r.top + r.height + GAP + h <= vh - EDGE) {
                pos = { top: r.top + r.height + GAP, left: clampX(r.left + r.width / 2 - w / 2) };
            } else if (r.top - GAP - h >= EDGE) {
                pos = { top: r.top - GAP - h, left: clampX(r.left + r.width / 2 - w / 2) };
            } else {
                pos = { top: clampY(vh - h - EDGE), left: clampX((vw - w) / 2) };
            }
            this.card = pos;
            this.placed = true;
        },
    };
}

function px(box) {
    const out = {};
    Object.entries(box).forEach(([k, v]) => (out[k] = `${Math.round(v)}px`));
    return out;
}
