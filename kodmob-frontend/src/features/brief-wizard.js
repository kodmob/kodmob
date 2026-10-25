/**
 * Proje brifi sihirbazı (ui/brief/brief-wizard.njk): 1) hizmet 2) bütçe ve
 * süre 3) iletişim. Her adım kendi alanlarını doğrular; gönderim
 * `POST /briefs` (kodmob-core, herkese açık).
 *
 * - Teklif hesaplayıcıdan gelinirse (`?estimate` + sessionStorage) hizmet
 *   seçimi ve mesaj önceden dolar, tahmin talebe eklenir.
 * - Spam koruması: görünmez tuzak alan (`website`) ve açılış zamanı
 *   (`started_at`); sunucu ikisini de denetler.
 * - Sunucunun alan hataları (`details.fields`) ziyaretçinin dilindeki metinle
 *   gösterilir ve ilgili adıma dönülür.
 */
import { http } from '@/services/http.js';
import { toast } from '@/ui/toast.js';
import { t, lang } from '@/i18n/client.js';

export const ESTIMATE_KEY = 'km_estimate';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const FIELD_STEP = { services: 1, name: 3, email: 3, message: 3, consent: 3 };
const FIELD_MESSAGE = { services: 'brief.servicesRequired', name: 'brief.nameRequired', email: 'brief.emailInvalid', message: 'brief.messageShort', consent: 'brief.consentRequired' };

export function briefWizard() {
    return {
        step: 1,
        total: 3,
        busy: false,
        notice: null, // açık "eksik alan" bildiriminin kimliği
        done: false,
        errors: {},
        estimate: null,
        startedAt: Date.now(),
        form: { services: [], budget: '', timeline: '', name: '', email: '', phone: '', company: '', message: '', consent: false, website: '' },

        init() {
            try {
                const raw = sessionStorage.getItem(ESTIMATE_KEY);
                if (raw && new URLSearchParams(window.location.search).has('estimate')) {
                    this.estimate = JSON.parse(raw);
                    this.form.services = this.estimate.services || [];
                    this.form.message = this.estimate.summary || '';
                }
            } catch {
                /* depolama kapalı ya da bozuk veri: tahmin olmadan devam */
            }
        },

        has(key) {
            return this.form.services.includes(key);
        },

        toggle(key) {
            this.form.services = this.has(key) ? this.form.services.filter((k) => k !== key) : [...this.form.services, key];
            delete this.errors.services;
        },

        validate(step) {
            const e = {};
            if (step === 1 && !this.form.services.length) e.services = t(FIELD_MESSAGE.services);
            if (step === 3) {
                if (this.form.name.trim().length < 2) e.name = t(FIELD_MESSAGE.name);
                if (!EMAIL.test(this.form.email.trim())) e.email = t(FIELD_MESSAGE.email);
                if (this.form.message.trim().length < 20) e.message = t(FIELD_MESSAGE.message);
                if (!this.form.consent) e.consent = t(FIELD_MESSAGE.consent);
            }
            this.errors = e;
            return Object.keys(e).length === 0;
        },

        next() {
            if (!this.validate(this.step)) return;
            this.step = Math.min(this.total, this.step + 1);
            this.focusStep();
        },

        back() {
            this.errors = {};
            this.step = Math.max(1, this.step - 1);
            this.focusStep();
        },

        focusStep() {
            this.$nextTick(() => this.$root.querySelector('[data-step-title]:not([hidden])')?.focus());
        },

        async submit() {
            if (!this.validate(3)) {
                this.notice = toast.error(t('brief.invalid'));
                return;
            }
            this.busy = true;
            try {
                await http.post('/briefs', {
                    ...this.form,
                    locale: lang(),
                    source: this.estimate ? 'calculator' : 'brief',
                    estimate: this.estimate ? { min: this.estimate.min, max: this.estimate.max, currency: this.estimate.currency, weeks: this.estimate.weeks, items: this.estimate.items } : null,
                    started_at: this.startedAt,
                });
                this.done = true;
                toast.dismiss(this.notice); // önceki "eksik alan" uyarısı açık kalmasın
                try {
                    sessionStorage.removeItem(ESTIMATE_KEY);
                } catch {
                    /* depolama kapalı */
                }
                this.$nextTick(() => this.$root.querySelector('[data-done-title]')?.focus());
            } catch (error) {
                const fields = error?.details?.fields || {};
                const keys = Object.keys(fields).filter((k) => FIELD_MESSAGE[k]);
                if (keys.length) {
                    this.errors = Object.fromEntries(keys.map((k) => [k, t(FIELD_MESSAGE[k])]));
                    this.step = Math.min(...keys.map((k) => FIELD_STEP[k]));
                    this.notice = toast.error(t('brief.invalid'));
                } else {
                    toast.error(error);
                }
            } finally {
                this.busy = false;
            }
        },

        reset() {
            Object.assign(this, { step: 1, done: false, errors: {}, estimate: null, startedAt: Date.now() });
            this.form = { services: [], budget: '', timeline: '', name: '', email: this.form.email, phone: '', company: '', message: '', consent: false, website: '' };
        },
    };
}
