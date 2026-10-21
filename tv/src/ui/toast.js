/**
 * Alpine dışından (servis, sayfa modülü) bildirim göstermek için:
 *
 *     import { toast } from '@/ui/toast.js';
 *     toast.success('Mesajınız gönderildi.');
 *     toast.error(error);
 */
const store = () => window.Alpine?.store('toast');

export const toast = {
    success: (message, options) => store()?.success(message, options),
    info: (message, options) => store()?.info(message, options),
    warning: (message, options) => store()?.warning(message, options),
    error: (error, options) => store()?.error(error, options),
    dismiss: (id) => id && store()?.dismiss(id),
};
