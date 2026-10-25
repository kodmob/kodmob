/**
 * Profilim > İki adımlı doğrulama (`kmTwoFactor`; kodmob-core Auth\Services\TwoFactor).
 *
 * Aç: şifre → QR kodu (ya da anahtar elle) doğrulama uygulamasına eklenir → uygulamadaki kod →
 * 8 yedek kod bir kez gösterilir (indir / kopyala). Kapat ve yedek kodları yenile şifre ister.
 * Açıkken girişte şifreden sonra uygulamadaki kod sorulur (panel/login.js).
 */
import qrcode from 'qrcode-generator';
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { copyText } from '../ui/clipboard.js';

/** otpauth adresinin QR kodu (SVG; sayfada üretilir, dışarı gönderilmez). */
function qrSvg(text) {
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true, alt: 'İki adımlı doğrulama QR kodu' });
}

export function kmTwoFactor() {
    return {
        /** idle · password (action: setup | disable | recovery) · scan · codes */
        step: 'idle',
        action: null,
        password: '',
        code: '',
        errors: {},
        busy: false,
        secret: '',
        qr: '',
        codes: [],

        get state() {
            return this.$store.session.me?.two_factor || { enabled: false, recovery_left: 0 };
        },

        /** Anahtar dörtlü gruplarla (elle girmek için). */
        get secretGroups() {
            return this.secret.replace(/(.{4})/g, '$1 ').trim();
        },

        ask(action) {
            this.reset();
            this.action = action;
            this.step = 'password';
            this.$nextTick(() => this.$root.querySelector('[data-testid=two-factor-password]')?.focus());
        },

        reset() {
            Object.assign(this, { step: 'idle', action: null, password: '', code: '', errors: {}, secret: '', qr: '', codes: [] });
        },

        async submitPassword() {
            if (this.busy || !this.password) return;
            this.busy = true;
            this.errors = {};
            try {
                if (this.action === 'setup') {
                    const res = await api.twoFactorSetup(this.password);
                    this.secret = res.secret;
                    this.qr = qrSvg(res.otpauth_url);
                    this.step = 'scan';
                    this.$nextTick(() => this.$root.querySelector('[data-testid=two-factor-code]')?.focus());
                } else if (this.action === 'disable') {
                    await api.twoFactorDisable(this.password);
                    await this.$store.session.load();
                    toast.success('İki adımlı doğrulama kapatıldı.');
                    this.reset();
                } else {
                    this.codes = (await api.twoFactorRecovery(this.password)).recovery_codes;
                    await this.$store.session.load();
                    this.step = 'codes';
                }
                this.password = '';
            } catch (error) {
                this.errors = error.details?.fields || {};
                if (!this.errors.password) toast.error(error);
            } finally {
                this.busy = false;
            }
        },

        async confirm() {
            if (this.busy || !this.code.trim()) return;
            this.busy = true;
            this.errors = {};
            try {
                this.codes = (await api.twoFactorConfirm(this.code.trim())).recovery_codes;
                await this.$store.session.load();
                this.step = 'codes';
                toast.success('İki adımlı doğrulama açıldı.');
            } catch (error) {
                this.errors = error.details?.fields || {};
                if (!this.errors.code) toast.error(error);
            } finally {
                this.busy = false;
            }
        },

        async copyCodes() {
            if (await copyText(this.codes.join('\n'))) toast.success('Yedek kodlar kopyalandı.');
        },

        downloadCodes() {
            const user = this.$store.session.me?.username || 'hesap';
            const text = `İki adımlı doğrulama yedek kodları (${user})\nHer kod bir kez kullanılır. Güvenli bir yerde saklayın.\n\n${this.codes.join('\n')}\n`;
            const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
            const link = Object.assign(document.createElement('a'), { href: url, download: `yedek-kodlar-${user}.txt` });
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        },
    };
}
