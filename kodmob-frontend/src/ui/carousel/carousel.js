/**
 * km-carousel — kaydırmalı liste (blog karuseli, referanslar).
 *
 *     <div x-data="kmCarousel">
 *       <button class="btn-round" @click="prev()" :disabled="atStart" aria-label="Önceki">…</button>
 *       <button class="btn-round" @click="next()" :disabled="atEnd" aria-label="Sonraki">…</button>
 *       <div class="carousel-track" x-ref="track">…kartlar…</div>
 *     </div>
 *
 * Kaydırma CSS scroll-snap ile yapılır (dokunmatikte doğal); düğmeler bir
 * kart genişliği kadar ilerletir.
 */
const behavior = () => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth');

export function kmCarousel() {
    return {
        atStart: true,
        atEnd: false,

        init() {
            this.$nextTick(() => {
                const track = this.$refs.track;
                if (!track) return;
                this.update();
                track.addEventListener('scroll', () => this.update(), { passive: true });
                new ResizeObserver(() => this.update()).observe(track);
            });
        },

        step() {
            const track = this.$refs.track;
            const first = track.firstElementChild;
            if (!first) return track.clientWidth;
            const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
            return first.getBoundingClientRect().width + gap;
        },

        prev() {
            this.$refs.track.scrollBy({ left: -this.step(), behavior: behavior() });
        },

        next() {
            this.$refs.track.scrollBy({ left: this.step(), behavior: behavior() });
        },

        update() {
            const track = this.$refs.track;
            this.atStart = track.scrollLeft <= 2;
            this.atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
        },
    };
}
