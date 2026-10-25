import { t } from '../i18n/client.js';

/**
 * Tek bildirim sistemi.
 * Metin her zaman `x-text` ile basılır; sunucu mesajı HTML olarak yorumlanmaz.
 */
let seq = 0;

const DURATION = { success: 3500, info: 4500, warning: 6000, error: 7000 };

export function createToastStore() {
    return {
        items: [],

        /**
         * `action: { label, run }` bildirimde bir düğme gösterir (ör. "Geri Al"); düğmeye
         * basınca bildirim kapanır. Süreli bildirimde altta kalan süre çubuğu görünür.
         */
        push(type, message, { title = '', timeout, action = null } = {}) {
            const id = ++seq;
            const text = String(message ?? '').trim() || t('toast.done');
            const ms = timeout ?? DURATION[type] ?? 4500;
            this.items.push({ id, type, title, message: text, action, ms: action ? ms : 0 });
            if (this.items.length > 4) this.items.shift();
            if (ms > 0) setTimeout(() => this.dismiss(id), ms);
            return id;
        },

        success(message, options) {
            return this.push('success', message, options);
        },

        info(message, options) {
            return this.push('info', message, options);
        },

        warning(message, options) {
            return this.push('warning', message, options);
        },

        /** Hata nesnesi (ApiError) ya da metin kabul eder. */
        error(errorOrMessage, options) {
            const message = typeof errorOrMessage === 'string' ? errorOrMessage : errorOrMessage?.message;
            return this.push('error', message || t('toast.error'), options);
        },

        dismiss(id) {
            this.items = this.items.filter((t) => t.id !== id);
        },

        act(t) {
            this.dismiss(t.id);
            t.action?.run();
        },
    };
}
