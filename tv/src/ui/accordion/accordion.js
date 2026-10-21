/**
 * km-accordion — SSS ve açılır içerik.
 *
 *     <div class="faq" x-data="kmAccordion({ open: 'sure' })">
 *       <div class="faq-item">
 *         <h3><button class="faq-q" @click="toggle('sure')" :aria-expanded="isOpen('sure')" aria-controls="faq-sure">…</button></h3>
 *         <div id="faq-sure" class="faq-a" x-show="isOpen('sure')" x-collapse>…</div>
 *       </div>
 *     </div>
 *
 * Cevaplar HTML'de hazır durur (arama motoru okur); başta kapalı olanlara
 * `x-cloak` eklenir ki sayfa açılırken bir an görünmesin.
 */
export function kmAccordion({ single = true, open = null } = {}) {
    return {
        active: open === null ? [] : [open],

        isOpen(id) {
            return this.active.includes(id);
        },

        toggle(id) {
            if (this.isOpen(id)) {
                this.active = this.active.filter((item) => item !== id);
            } else {
                this.active = single ? [id] : [...this.active, id];
            }
        },
    };
}
