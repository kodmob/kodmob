import { startPage } from '@/app.js';
import { http } from '@/services/http.js';
import { fieldErrors } from '@/domain/errors.js';
import { t } from '@/i18n/client.js';
import { pushPermission, subscribePush } from '@/services/sw.js';

/**
 * Üye hesabı sayfası (araç `members`; kodmob-core Modules\Members). Tek sayfa, kip adresten:
 *
 *   /hesap/                 oturum açıksa hesabım (bilgiler, siparişler, şifre, hesabı silme); değilse giriş
 *   ?kip=kayit | sifre      kayıt formu · şifremi unuttum
 *   ?dogrula=<anahtar>      e-posta doğrulama (e-postadaki bağlantı; sayfa açılınca POST, anahtar adresten silinir)
 *   ?sifirla=<anahtar>      yeni şifre
 *   ?google=hata            Google ile giriş yapılamadı
 *
 * Oturum HttpOnly çerezdedir (`km_member`); sayfa yalnızca /members/me ile durumu öğrenir.
 */
function accountPage() {
    return {
        mode: 'loading',
        member: null,
        orders: [],
        google: false,
        pushKey: '',
        pushState: 'off',
        form: { name: '', email: '', password: '', consent: false, website: '', current: '', confirm: '' },
        errors: {},
        message: '',
        busy: false,
        token: '',
        startedAt: Date.now(),

        async init() {
            const query = new URLSearchParams(window.location.search);
            const verify = query.get('dogrula');
            const reset = query.get('sifirla');
            if (verify || reset) window.history.replaceState(null, '', window.location.pathname);
            if (query.get('google') === 'hata') this.message = t('account.googleFailed');
            try {
                const res = await http.get('/members/me');
                this.google = !!res.google;
                this.pushKey = res.push_key || '';
                this.pushState = pushPermission() === 'unsupported' ? 'unsupported' : 'off';
                this.member = res.member;
            } catch {
                this.member = null;
            }
            if (verify) return this.verify(verify);
            if (reset) {
                this.token = reset;
                return this.show('reset');
            }
            const kip = query.get('kip');
            this.show(this.member ? 'account' : kip === 'kayit' ? 'register' : kip === 'sifre' ? 'forgot' : 'login');
        },

        show(mode) {
            this.mode = mode;
            this.errors = {};
            if (mode === 'account') this.loadOrders();
        },

        async run(fn, success = '') {
            this.busy = true;
            this.errors = {};
            this.message = '';
            try {
                const res = await fn();
                if (success) this.message = success;
                return res;
            } catch (error) {
                this.errors = fieldErrors(error);
                if (!Object.keys(this.errors).length) this.message = error.message;
                return null;
            } finally {
                this.busy = false;
            }
        },

        async login() {
            const res = await this.run(() => http.post('/members/login', { email: this.form.email, password: this.form.password }));
            if (res) {
                this.member = res.member;
                this.form.password = '';
                this.show('account');
            }
        },

        async register() {
            const res = await this.run(() => http.post('/members/register', {
                name: this.form.name, email: this.form.email, password: this.form.password, consent: this.form.consent,
                website: this.form.website, started_at: this.startedAt, locale: document.documentElement.lang,
            }));
            if (res) {
                this.member = res.member;
                this.form.password = '';
                this.show('account');
                this.message = t('account.registered');
            }
        },

        async forgot() {
            await this.run(() => http.post('/members/forgot', { email: this.form.email, locale: document.documentElement.lang }), t('account.forgotSent'));
        },

        async reset() {
            const res = await this.run(() => http.post('/members/reset', { token: this.token, password: this.form.password }));
            if (res) {
                this.member = res.member;
                this.form.password = '';
                this.show('account');
                this.message = t('account.passwordChanged');
            }
        },

        async verify(token) {
            const res = await this.run(() => http.post('/members/verify', { token }));
            if (res?.member) this.member = res.member;
            this.show(this.member ? 'account' : 'login');
            this.message = res ? t('account.verified') : t('account.verifyFailed');
        },

        async resend() {
            await this.run(() => http.post('/members/verify/resend', { locale: document.documentElement.lang }), t('account.verifySent'));
        },

        async saveProfile() {
            const res = await this.run(() => http.put('/members/me', { name: this.member.name }), t('account.saved'));
            if (res) this.member = res.member;
        },

        async changePassword() {
            const res = await this.run(() => http.post('/members/password', { current: this.form.current, password: this.form.password }), t('account.passwordChanged'));
            if (res) this.form.current = this.form.password = '';
        },

        async loadOrders() {
            try {
                this.orders = (await http.get('/members/orders')).items || [];
            } catch {
                this.orders = [];
            }
        },

        async logout() {
            await this.run(() => http.post('/members/logout'));
            this.member = null;
            this.show('login');
        },

        async remove() {
            const res = await this.run(() => http.post('/members/delete', { password: this.form.current, confirm: this.form.confirm }));
            if (res) {
                this.member = null;
                this.form.current = this.form.confirm = '';
                this.show('login');
                this.message = t('account.deleted');
            }
        },

        /** Sipariş durumu değişince bu cihaza bildirim (Web Push). */
        async enablePush() {
            const res = await this.run(async () => http.post('/members/push/subscribe', await subscribePush(this.pushKey)), t('account.pushOn'));
            if (res) this.pushState = 'on';
        },

        googleUrl() {
            return `/api/v1/members/google?locale=${encodeURIComponent(document.documentElement.lang)}`;
        },
    };
}

startPage({ components: { accountPage } });
