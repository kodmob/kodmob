/**
 * km-confirm — geri alınamaz işlemler için onay.
 *
 *     import { confirmAction } from '@/ui/confirm/confirm.js';
 *     const ok = await confirmAction({
 *         title: 'Görsel silinsin mi?',
 *         message: 'Bu işlem geri alınamaz.',
 *         summary: [{ label: 'Dosya', value: 'kapak.webp' }],
 *         confirmText: 'Sil', tone: 'danger',
 *     });
 *
 * İsteğe bağlı seçim kutusu ve alan: nesne verilir, pencere onu değiştirir, onaydan sonra okunur.
 *
 *     const option = { label: 'Siparişleri de geri yükle', checked: false };
 *     const field = { label: 'Yedek şifresi', type: 'password', value: '' };
 *     if (await confirmAction({ title: '…', option, field })) send(option.checked, field.value);
 *
 * Tek pencere `shell/overlays.html` içinde durur; aynı anda tek onay açılır.
 */
import { t } from '../../i18n/client.js';
import { iconHref as icon } from '../icon.js';

export function createConfirmStore() {
    let resolver = null;

    return {
        open: false,
        title: '',
        message: '',
        summary: [],
        confirmText: '',
        cancelText: '',
        tone: 'primary',
        option: null,
        field: null,

        get confirmClass() {
            return this.tone === 'danger' ? 'btn-danger' : this.tone === 'success' ? 'btn-success' : 'btn-primary';
        },

        get iconHref() {
            return icon(this.tone === 'danger' ? 'alert' : 'help');
        },

        get iconClass() {
            return this.tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-primary-soft text-primary';
        },

        ask({ title, message = '', summary = [], confirmText = t('confirm.ok'), cancelText = t('confirm.cancel'), tone = 'primary', option = null, field = null }) {
            if (resolver) resolver(false);
            Object.assign(this, { title, message, summary, confirmText, cancelText, tone, option, field, open: true });
            return new Promise((resolve) => {
                resolver = resolve;
            });
        },

        answer(value) {
            this.open = false;
            const done = resolver;
            resolver = null;
            done?.(value);
        },
    };
}

export function confirmAction(options) {
    return window.Alpine.store('confirm').ask(options);
}
