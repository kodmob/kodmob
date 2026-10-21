/**
 * km-count-up — istatistik sayacı: görünür olunca 0'dan değerine sayar.
 *
 *     <span class="stat-value" x-data="kmCountUp">150+</span>
 *     <span class="metric-value" x-data="kmCountUp(m.value)"></span>   (içerikten gelen değer)
 *
 * Sabit değer HTML'de yazılıdır (arama motoru ve JavaScript'siz ziyaretçi onu
 * görür). Hareket azaltma tercihinde sayılmaz. Biçimleyici dil başına bir kez kurulur (her karede
 * `toLocaleString` yeni biçimleyici açar); değişmeyen metin yeniden yazılmaz (gereksiz yerleşim olmasın).
 */
const formatters = new Map();
const formatter = (lang) => {
    if (!formatters.has(lang)) formatters.set(lang, new Intl.NumberFormat(lang));
    return formatters.get(lang);
};

export function kmCountUp(value) {
    return {
        init() {
            const el = this.$el;
            if (value != null) el.textContent = value;
            const text = el.textContent.trim();
            const match = text.match(/^(\D*)([\d.,]+)(\D*)$/);
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!match || reduced || !('IntersectionObserver' in window)) return;

            const [, prefix, digits, suffix] = match;
            const target = Number(digits.replace(/\./g, '').replace(',', '.'));
            if (!Number.isFinite(target)) return;

            const observer = new IntersectionObserver((entries) => {
                if (!entries[0].isIntersecting) return;
                observer.disconnect();
                const start = performance.now();
                const duration = 1200;
                const format = formatter(document.documentElement.lang || 'tr');
                const tick = (now) => {
                    const p = Math.min(1, (now - start) / duration);
                    const eased = 1 - (1 - p) ** 3;
                    const next = p < 1 ? prefix + format.format(Math.round(target * eased)) + suffix : text;
                    if (next !== el.textContent) el.textContent = next;
                    if (p < 1) requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);
            }, { threshold: 0.6 });
            observer.observe(el);
        },
    };
}
