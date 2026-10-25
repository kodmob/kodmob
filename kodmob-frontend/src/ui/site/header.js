/**
 * km-header — yapışkan üst bar ve mobil çekmece (shell/site-header.html).
 *
 * - Sayfa kaydırılınca `scrolled` olur; bar kenarlık ve gölge alır.
 * - Çekmece açıkken sayfa kaydırılmaz; Escape ve geniş ekrana geçiş kapatır.
 */
export function kmHeader() {
    return {
        scrolled: false,
        open: false,

        init() {
            const onScroll = () => {
                this.scrolled = window.scrollY > 8;
            };
            onScroll();
            window.addEventListener('scroll', onScroll, { passive: true });

            window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
                if (event.matches) this.close();
            });
            this.$watch('open', (value) => document.documentElement.classList.toggle('overflow-hidden', value));
        },

        toggle() {
            this.open = !this.open;
        },

        close() {
            this.open = false;
        },
    };
}
