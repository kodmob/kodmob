/**
 * km-sheet — masaüstünde ortada modal, mobilde tam ekran sayfa.
 *
 *     <div x-data="kmSheet({ title: 'Proje Brifi', size: 'md' })" x-modelable="open" x-model="briefOpen">
 *       {% include "ui/sheet/sheet-open.html" %}
 *         … form …
 *         <div class="sheet-footer">
 *           <button class="btn btn-secondary" @click="close()">İptal</button>
 *           <button class="btn btn-primary" @click="…">Gönder</button>
 *         </div>
 *       {% include "ui/sheet/sheet-close.html" %}
 *     </div>
 *
 * - Escape ve arka plana dokunma kapatır (`busy` iken kapatmaz).
 * - Açılınca ilk `[autofocus]` ya da ilk form alanı odak alır; kapanınca odak
 *   açan öğeye döner. Açıkken sayfa kaydırılmaz.
 */
let openCount = 0;

const SIZES = {
    sm: 'sm:max-w-md',
    md: 'sm:max-w-xl',
    lg: 'sm:max-w-3xl',
    xl: 'sm:max-w-5xl',
};

export function kmSheet({ title = '', subtitle = '', size = 'md', dismissible = true } = {}) {
    return {
        open: false,
        busy: false,
        title,
        subtitle,
        dismissible,
        sizeClass: SIZES[size] || SIZES.md,
        returnFocus: null,

        init() {
            this.$watch('open', (value) => (value ? this.onOpen() : this.onClose()));
            if (this.open) this.onOpen();
        },

        destroy() {
            if (this.open) this.onClose();
        },

        onOpen() {
            this.returnFocus = document.activeElement;
            openCount++;
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => {
                const panel = this.$refs.panel;
                const target = panel?.querySelector('[autofocus]') || panel?.querySelector('input:not([type=hidden]):not([disabled]), select, textarea') || panel;
                target?.focus?.();
            });
        },

        onClose() {
            openCount = Math.max(0, openCount - 1);
            if (!openCount) document.documentElement.classList.remove('overflow-hidden');
            this.returnFocus?.focus?.();
        },

        close() {
            if (this.busy) return;
            this.open = false;
        },

        dismiss() {
            if (this.dismissible) this.close();
        },

        /** Odağı pencerenin içinde tutar (Tab / Shift+Tab). */
        trap(event) {
            const focusables = [...this.$refs.panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter((el) => el.offsetParent !== null);
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
    };
}
