/**
 * Profilim (/panel/profilim): her kullanıcının kendi hesabı (kodmob-core Auth\Http\ProfileController).
 *
 * - Bilgiler: ad, e-posta, telefon (kullanıcı adı ve rol yalnızca görüntülenir).
 * - Şifre: şu anki şifreyle değiştirilir; bu cihaz dışındaki oturumlar kapanır.
 * - Oturumlarım: açık oturumlar (cihaz, IP, son etkinlik; bu cihaz işaretli), tek tek ya da
 *   "Diğer oturumları kapat" ile kapatılır.
 * - Yetkilerim: rolün verdiği işlem yetkileri (bilgi; rolü Yönetici değiştirir).
 */
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

export function kmProfile() {
    return {
        form: { display_name: '', email: '', phone: '' },
        original: '',
        errors: {},
        saving: false,
        pw: { current_password: '', password: '', password_confirmation: '' },
        pwErrors: {},
        pwSaving: false,
        showPw: false,
        sessions: [],
        fmt,

        async init() {
            const me = this.$store.session.me || (await this.$store.session.load());
            this.fill(me);
            this.loadSessions();
        },

        fill(me) {
            this.form = { display_name: me.display_name || '', email: me.email || '', phone: me.phone || '' };
            this.original = JSON.stringify(this.form);
        },

        get me() {
            return this.$store.session.me || {};
        },

        get dirty() {
            return JSON.stringify(this.form) !== this.original;
        },

        async save() {
            if (!this.dirty || this.saving) return;
            this.saving = true;
            this.errors = {};
            try {
                const me = await api.saveMe(this.form);
                this.$store.session.set(me);
                this.$store.panel.user = { name: me.display_name, email: me.email };
                this.fill(me);
                toast.success('Profiliniz kaydedildi.');
            } catch (error) {
                this.errors = error.details?.fields || {};
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        async changePassword() {
            if (this.pwSaving) return;
            this.pwSaving = true;
            this.pwErrors = {};
            try {
                const res = await api.changePassword(this.pw);
                this.pw = { current_password: '', password: '', password_confirmation: '' };
                toast.success(res.ended_sessions ? `Şifreniz değişti; ${res.ended_sessions} diğer oturum kapatıldı.` : 'Şifreniz değişti.');
                this.loadSessions();
            } catch (error) {
                this.pwErrors = error.details?.fields || {};
                toast.error(error);
            } finally {
                this.pwSaving = false;
            }
        },

        /** Şifre gücü: uzunluk + çeşitlilik (yalnızca yol gösterir; kural sunucudadır). */
        get strength() {
            const p = this.pw.password;
            if (!p) return null;
            let score = 0;
            if (p.length >= (this.me.password_min || 10)) score++;
            if (p.length >= 14) score++;
            if (/[a-zçğıöşü]/.test(p) && /[A-ZÇĞİÖŞÜ]/.test(p)) score++;
            if (/\d/.test(p)) score++;
            if (/[^\p{L}\d]/u.test(p)) score++;
            const levels = [['Çok zayıf', 'danger', 15], ['Zayıf', 'danger', 30], ['Orta', 'warning', 55], ['İyi', 'success', 75], ['Güçlü', 'success', 90], ['Çok güçlü', 'success', 100]];
            const [label, tone, width] = levels[score];
            return { label, tone, width };
        },

        async loadSessions() {
            try {
                this.sessions = (await api.mySessions()).items;
            } catch (error) {
                toast.error(error);
            }
        },

        async endSession(s) {
            const ok = await confirmAction({ title: 'Oturum kapatılsın mı?', message: `${s.device} (${s.ip}) panelden çıkar.`, confirmText: 'Kapat', tone: 'danger' });
            if (!ok) return;
            try {
                await api.endMySession(s.id);
                toast.success('Oturum kapatıldı.');
                this.loadSessions();
            } catch (error) {
                toast.error(error);
            }
        },

        async endOthers() {
            const ok = await confirmAction({ title: 'Diğer oturumlar kapatılsın mı?', message: 'Bu cihaz dışındaki bütün cihazlarda panelden çıkarsınız.', confirmText: 'Hepsini kapat', tone: 'danger' });
            if (!ok) return;
            try {
                const res = await api.endMyOtherSessions();
                toast.success(`${res.ended} oturum kapatıldı.`);
                this.loadSessions();
            } catch (error) {
                toast.error(error);
            }
        },

        /** Rolün yetkileri, gruplu ve okunur (/me `capability_groups`). */
        get capabilityGroups() {
            return this.me.capability_groups || [];
        },
    };
}
