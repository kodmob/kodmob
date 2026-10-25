/**
 * km-lightbox — ekran görüntüsü galerisi (ui/gallery/lightbox.html).
 *
 *     <div x-data="kmLightbox">
 *       <div class="gallery">
 *         <a class="gallery-item" href="/img/panel.webp" data-lightbox data-caption="Yönetim Paneli" @click.prevent="show($event)">…</a>
 *       </div>
 *       {% include "ui/gallery/lightbox.html" %}
 *     </div>
 *
 * Bağlantılar görselin kendisine gider: JavaScript yoksa görsel yeni sayfada açılır.
 * Ok tuşları gezinir, Escape kapatır.
 */
export function kmLightbox() {
    return {
        open: false,
        index: 0,
        items: [],

        links() {
            return [...this.$root.querySelectorAll('[data-lightbox]')];
        },

        show(event) {
            const links = this.links();
            this.items = links.map((link) => ({
                src: link.getAttribute('href'),
                caption: link.dataset.caption || link.getAttribute('aria-label') || '',
            }));
            this.index = Math.max(0, links.indexOf(event.currentTarget));
            this.open = true;
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.close?.focus());
        },

        close() {
            this.open = false;
            document.documentElement.classList.remove('overflow-hidden');
        },

        next() {
            this.index = (this.index + 1) % this.items.length;
        },

        prev() {
            this.index = (this.index - 1 + this.items.length) % this.items.length;
        },

        get current() {
            return this.items[this.index] || { src: '', caption: '' };
        },
    };
}
