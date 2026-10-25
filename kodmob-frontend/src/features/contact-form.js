/**
 * İletişim formu (ui/contact/contact-form.njk): ad, e-posta, isteğe bağlı telefon, mesaj ve KVKK onayı.
 * Gönderim `POST /briefs` (`source: 'contact'`; talepler modülü, herkese açık): panelde Talepler'e "İletişim
 * formu" olarak düşer, ziyaretçiye onay e-postası gider. Hizmet seçimi istemez (proje brifinden farkı).
 *
 * Spam koruması brif sihirbazıyla aynı: görünmez tuzak alan (`website`) ve açılış zamanı (`started_at`).
 * Sunucunun alan hataları (`details.fields`) ziyaretçinin dilindeki metinle gösterilir.
 */
import { http } from '@/services/http.js';
import { toast } from '@/ui/toast.js';
import { t, lang } from '@/i18n/client.js';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MESSAGES = { name: 'contact.nameRequired', email: 'contact.emailInvalid', message: 'contact.messageShort', consent: 'contact.consentRequired' };
const blank = (email = '') => ({ name: '', email, phone: '', message: '', consent: false, website: '' });

export function contactForm() {
    return {
        busy: false,
        done: false,
        notice: null,
        errors: {},
        startedAt: Date.now(),
        form: blank(),

        validate() {
            const e = {};
            if (this.form.name.trim().length < 2) e.name = t(MESSAGES.name);
            if (!EMAIL.test(this.form.email.trim())) e.email = t(MESSAGES.email);
            if (this.form.message.trim().length < 20) e.message = t(MESSAGES.message);
            if (!this.form.consent) e.consent = t(MESSAGES.consent);
            this.errors = e;
            return Object.keys(e).length === 0;
        },

        async submit() {
            if (!this.validate()) {
                this.notice = toast.error(t('contact.invalid'));
                return;
            }
            this.busy = true;
            try {
                await http.post('/briefs', { ...this.form, locale: lang(), source: 'contact', started_at: this.startedAt });
                this.done = true;
                toast.dismiss(this.notice);
                this.$nextTick(() => this.$root.querySelector('[data-done-title]')?.focus());
            } catch (error) {
                const fields = Object.keys(error?.details?.fields || {}).filter((k) => MESSAGES[k]);
                if (fields.length) {
                    this.errors = Object.fromEntries(fields.map((k) => [k, t(MESSAGES[k])]));
                    this.notice = toast.error(t('contact.invalid'));
                } else {
                    toast.error(error);
                }
            } finally {
                this.busy = false;
            }
        },

        reset() {
            Object.assign(this, { done: false, errors: {}, startedAt: Date.now(), form: blank(this.form.email) });
        },
    };
}
