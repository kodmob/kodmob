/**
 * Giriş ekranı: kodmob-core oturumu (/v1/auth) ile kullanıcı adı + şifre. Başarıda kök bileşen
 * (`@signed-in` olayıyla) paneli açar. Hatalar sunucunun mesajıyla gösterilir (kilit,
 * hatalı şifre). İki adımlı doğrulama açık hesapta şifreden sonra kod adımı açılır
 * (uygulamadaki kod ya da yedek kod); "Geri" kullanıcı adı ve şifreye döner.
 */
import { auth } from '../services/auth.js';

export function kmLogin() {
    return {
        username: '',
        password: '',
        code: '',
        needCode: false,
        show: false,
        busy: false,
        error: '',

        async submit() {
            if (this.busy) return;
            this.error = '';
            if (!this.username.trim() || !this.password) {
                this.error = 'Kullanıcı adı ve şifre gerekli.';
                return;
            }
            if (this.needCode && !this.code.trim()) {
                this.error = 'Doğrulama kodunu girin.';
                return;
            }
            this.busy = true;
            try {
                const data = await auth.login(this.username.trim(), this.password, this.needCode ? this.code.trim() : '');
                this.password = '';
                this.code = '';
                this.needCode = false;
                this.$dispatch('signed-in', data);
            } catch (error) {
                if (error.code === 'otp_required') {
                    this.needCode = true;
                    this.$nextTick(() => document.getElementById('login-code')?.focus());
                } else {
                    if (error.code === 'jwt_auth_otp_invalid') this.code = '';
                    this.error = error.message;
                }
            } finally {
                this.busy = false;
            }
        },

        back() {
            this.needCode = false;
            this.code = '';
            this.error = '';
        },
    };
}
