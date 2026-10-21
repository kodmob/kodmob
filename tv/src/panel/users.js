/**
 * Kullanıcılar (/panel/kullanicilar): ortak çoklu kullanıcı yönetimi (kodmob-api Modules/Users).
 *
 * - Liste ortak tablodur: aktif / pasif sekmeleri, rol süzgeci, arama, CSV. Satırda
 *   "Düzenle", menüde "Pasife al / Aktifleştir", "Oturumlarını kapat".
 * - Yan panel: ekleme ve düzenleme formu (ad, kullanıcı adı, e-posta, telefon, rol, şifre),
 *   açık oturumları ve son işlemleri. `/panel/kullanicilar/4` doğrudan açar (İşlem Geçmişi bağlantısı).
 * - Kullanıcı silinmez, pasife alınır: işlem geçmişindeki adı korunur, oturumları hemen kapanır.
 * - SMS ve e-posta (`km_message_users`): satır menüsü, toplu işlem ve yan panelden; yazma penceresi
 *   (`compose`). SMS düğmeleri yalnızca Ayarlar > SMS ayarlıyken görünür; telefonu / e-postası
 *   olmayan atlanır. SMS boy sayısı yazarken hesaplanır (domain/sms.js). Tek kişiye e-posta hemen,
 *   çok kişiye arka planda gider.
 *
 * Görmek `km_view_users`, değiştirmek `km_manage_users`. Yönetici hesabını yalnızca
 * Yönetici düzenler; son aktif Yönetici korunur (sunucu denetler).
 */
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { analyze, MAX_PARTS } from '../domain/sms.js';

const EMPTY = { username: '', display_name: '', email: '', phone: '', role: 'editor', password: '' };

/** Okunabilir rastgele şifre: harf + rakam + işaret (karışan 0/O, 1/l yok). */
export function generatePassword(length = 14) {
    const sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789', '-_.!?'];
    const bytes = crypto.getRandomValues(new Uint32Array(length));
    const all = sets.join('');
    const chars = [...bytes].map((b, i) => (i < sets.length ? sets[i][b % sets[i].length] : all[b % all.length]));
    for (let i = chars.length - 1; i > 0; i--) {
        const j = bytes[i] % (i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
}

export function kmUsers(route = {}) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _onRoute: null,
        options: { roles: [], statuses: [], password_min: 10, messaging: { sms: false, mail: true } },
        grid: null,
        user: null,
        tab: 'info',
        form: { ...EMPTY },
        errors: {},
        saving: false,
        loadingDetail: false,
        showPassword: false,
        compose: { open: false, channel: 'sms', rows: [], subject: '', message: '', sending: false, errors: {} },
        MAX_PARTS,
        fmt,

        async init() {
            this.grid = this.gridConfig();
            try {
                this.options = await api.userOptions();
            } catch (error) {
                toast.error(error);
            }
            if (route.id) this.edit({ id: route.id });
            this._onRoute = (e) => e.detail?.id && this.edit({ id: e.detail.id });
            window.addEventListener('km-route', this._onRoute);
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
        },

        get canManage() {
            return this.$store.session.can('km_manage_users');
        },

        get canSms() {
            return this.$store.session.can('km_message_users') && !!this.options.messaging?.sms;
        },

        get canMail() {
            return this.$store.session.can('km_message_users') && this.options.messaging?.mail !== false;
        },

        gridConfig() {
            return {
                id: 'users',
                syncUrl: true,
                source: (q) => api.users(q),
                pageSize: 25,
                pageSizes: [25, 50, 100],
                sort: { key: 'display_name', order: 'asc' },
                values: { status: 'active' },
                live: { types: ['user'] },
                exportName: 'kullanicilar',
                empty: { title: 'Kullanıcı yok', text: 'Ekip arkadaşlarınızı ekleyip her birine rol verin.', icon: 'users' },
                columns: [
                    { key: 'display_name', label: 'Ad', sortable: true, strong: true, card: 'title' },
                    { key: 'username', label: 'Kullanıcı adı', sortable: true, mono: true, card: 'subtitle' },
                    { key: 'role_label', label: 'Rol', sortable: true, sortKey: 'role', filterBy: 'role', filterValue: 'role', card: 'spec' },
                    { key: 'status', label: 'Durum', badge: 'user', card: 'badge' },
                    { key: 'email', label: 'E-posta', hideBelow: 'lg', muted: true, card: 'meta' },
                    { key: 'phone', label: 'Telefon', hideBelow: 'xl', muted: true, hidden: true },
                    { key: 'sessions', label: 'Oturum', format: 'number', hideBelow: 'lg', card: 'spec' },
                    { key: 'two_factor', label: 'İki adım', chips: (r) => (r?.two_factor ? [{ label: 'Açık', tone: 'success', title: 'İki adımlı doğrulama açık' }] : []), hideBelow: 'xl' },
                    { key: 'last_login_at', label: 'Son giriş', format: 'relative', sortable: true, hideBelow: 'md', card: 'spec' },
                    { key: 'created_at', label: 'Eklenme', format: 'date', sortable: true, hidden: true, hideBelow: 'xl' },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Ad, kullanıcı adı, e-posta ya da telefon' },
                    { key: 'status', type: 'tabs', label: 'Durum', statusKind: 'user' },
                    { key: 'role', type: 'select', label: 'Rol', options: [], loadOptions: async () => (await api.userOptions()).roles },
                ],
                rowActions: [
                    { key: 'edit', label: 'Düzenle', primary: true, open: true },
                    { key: 'deactivate', label: 'Pasife al', icon: 'ban', tone: 'danger', can: 'km_manage_users', when: (r) => r.status === 'active' && !r.is_self },
                    { key: 'activate', label: 'Aktifleştir', icon: 'check-circle', can: 'km_manage_users', when: (r) => r.status !== 'active' },
                    { key: 'sessions', label: 'Oturumlarını kapat', icon: 'logout', can: 'km_manage_users', when: (r) => r.sessions > 0 && !r.is_self },
                    { key: 'sms', label: 'SMS gönder', icon: 'message', can: 'km_message_users', when: (r) => !!r.phone && this.canSms },
                    { key: 'mail', label: 'E-posta gönder', icon: 'mail', can: 'km_message_users', when: (r) => !!r.email && this.canMail },
                    { key: 'two_factor', label: 'İki adımlı doğrulamayı sıfırla', icon: 'shield', tone: 'danger', can: 'km_manage_users', when: (r) => r.two_factor && !r.is_self },
                ],
                bulkActions: [
                    { key: 'deactivate', label: 'Pasife al', icon: 'ban', tone: 'danger', can: 'km_manage_users' },
                    { key: 'activate', label: 'Aktifleştir', icon: 'check-circle', can: 'km_manage_users' },
                    { key: 'sms', label: 'SMS gönder', icon: 'message', can: 'km_message_users', when: () => this.canSms },
                    { key: 'mail', label: 'E-posta gönder', icon: 'mail', can: 'km_message_users', when: () => this.canMail },
                ],
                deletable: false,
            };
        },

        reload() {
            window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id: 'users' } }));
        },

        async onAction({ action, row }) {
            if (action === 'deactivate') return this.setActive(row, false);
            if (action === 'activate') return this.setActive(row, true);
            if (action === 'sessions') return this.endSessions(row);
            if (action === 'two_factor') return this.resetTwoFactor(row);
            if (action === 'sms' || action === 'mail') return this.openCompose(action, [row]);
        },

        async onBulk({ action, rows }) {
            if (action === 'sms' || action === 'mail') return this.openCompose(action, rows);
            const list = rows.filter((r) => (action === 'deactivate' ? r.status === 'active' && !r.is_self : r.status !== 'active'));
            if (!list.length) return toast.info('Seçilenler arasında bu işlemi bekleyen kullanıcı yok.');
            const ok = await confirmAction({
                title: `${list.length} kullanıcı ${action === 'deactivate' ? 'pasife alınsın' : 'aktifleştirilsin'} mi?`,
                message: action === 'deactivate' ? 'Panele giremezler; açık oturumları hemen kapanır. İşlem geçmişleri korunur.' : 'Panele yeniden girebilirler.',
                confirmText: action === 'deactivate' ? 'Pasife al' : 'Aktifleştir',
                tone: action === 'deactivate' ? 'danger' : 'primary',
            });
            if (!ok) return;
            let done = 0;
            for (const row of list) {
                try {
                    await (action === 'deactivate' ? api.deactivateUser(row.id) : api.activateUser(row.id));
                    done += 1;
                } catch (error) {
                    toast.error(`${row.display_name}: ${error.message}`);
                }
            }
            if (done) toast.success(`${done} kullanıcı güncellendi.`);
            this.reload();
        },

        async setActive(row, active) {
            if (!active) {
                const ok = await confirmAction({
                    title: `${row.display_name} pasife alınsın mı?`,
                    message: 'Panele giremez; açık oturumları hemen kapanır. Kullanıcı silinmez, işlem geçmişi korunur; istediğinizde aktifleştirebilirsiniz.',
                    confirmText: 'Pasife al',
                    tone: 'danger',
                });
                if (!ok) return;
            }
            try {
                const user = await (active ? api.activateUser(row.id) : api.deactivateUser(row.id));
                toast.success(active ? `${user.display_name} aktifleştirildi.` : `${user.display_name} pasife alındı.`);
                if (this.user?.id === row.id) this.user = { ...this.user, ...user };
                this.reload();
            } catch (error) {
                toast.error(error);
            }
        },

        async endSessions(row) {
            const ok = await confirmAction({
                title: `${row.display_name} kullanıcısının oturumları kapatılsın mı?`,
                message: 'Bütün cihazlarda panelden çıkar; yeniden giriş yapması gerekir.',
                confirmText: 'Oturumları kapat',
                tone: 'danger',
            });
            if (!ok) return;
            try {
                const res = await api.endUserSessions(row.id);
                toast.success(`${res.ended} oturum kapatıldı.`);
                if (this.user?.id === row.id) this.edit(row);
                this.reload();
            } catch (error) {
                toast.error(error);
            }
        },

        /** Telefonunu kaybeden kullanıcı: iki adımlı doğrulama kapanır, oturumları kapatılır. */
        async resetTwoFactor(row) {
            const ok = await confirmAction({
                title: `${row.display_name} için iki adımlı doğrulama sıfırlansın mı?`,
                message: 'Kapanır ve bütün oturumları kapatılır; yalnızca şifresiyle girip Profilim\'den yeniden açar. Yalnızca kimliğinden emin olduğunuz kişi için yapın (ör. telefonunu kaybetti).',
                confirmText: 'Sıfırla',
                tone: 'danger',
            });
            if (!ok) return;
            try {
                const user = await api.resetUserTwoFactor(row.id);
                toast.success(`${user.display_name}: iki adımlı doğrulama sıfırlandı.`);
                if (this.user?.id === row.id) this.user = { ...this.user, ...user };
                this.reload();
            } catch (error) {
                toast.error(error);
            }
        },

        // ------------------------------------------------------------ SMS / e-posta
        openCompose(channel, rows) {
            const field = channel === 'sms' ? 'phone' : 'email';
            const list = rows.map((r) => ({ id: r.id, name: r.display_name, ok: !!String(r[field] || '').trim() }));
            if (!list.some((r) => r.ok)) {
                return toast.info(channel === 'sms' ? 'Seçilenlerin hiçbirinin telefonu kayıtlı değil.' : 'Seçilenlerin hiçbirinin e-postası kayıtlı değil.');
            }
            this.compose = { open: true, channel, rows: list, subject: '', message: '', sending: false, errors: {} };
            this.$nextTick(() => this.$root.querySelector('[data-compose-first]')?.focus());
        },

        closeCompose() {
            if (!this.compose.sending) this.compose.open = false;
        },

        get composeTargets() {
            return (this.compose?.rows || []).filter((r) => r.ok);
        },

        get composeSkipped() {
            return (this.compose?.rows || []).filter((r) => !r.ok);
        },

        get smsInfo() {
            return analyze(this.compose?.message || '');
        },

        get composeReady() {
            const c = this.compose;
            if (!c.open || c.sending || !c.message.trim()) return false;
            if (c.channel === 'mail') return !!c.subject.trim();
            const info = this.smsInfo;
            return !info.unsupported.length && info.parts <= MAX_PARTS;
        },

        names(list, max = 6) {
            const shown = list.slice(0, max).map((r) => r.name).join(', ');
            return list.length > max ? `${shown} ve ${list.length - max} kişi daha` : shown;
        },

        async sendCompose() {
            const c = this.compose;
            if (!this.composeReady) return;
            c.sending = true;
            c.errors = {};
            const ids = this.composeTargets.map((r) => r.id);
            try {
                const res = c.channel === 'sms' ? await api.smsUsers(ids, c.message) : await api.mailUsers(ids, c.subject, c.message);
                if (c.channel === 'sms') toast.success(`${res.sent} kişiye SMS gönderildi (${res.parts} boy).`);
                else if (res.queued) toast.success(`${res.sent} e-posta arka planda gönderiliyor. Sonuçlar Ayarlar > E-posta'da.`);
                else toast.success('E-posta gönderildi.');
                if (res.skipped?.length) toast.info(`Gönderilmedi: ${res.skipped.map((s) => `${s.name} (${s.reason})`).join(', ')}`);
                c.sending = false;
                c.open = false;
            } catch (error) {
                c.errors = error.details?.fields || {};
                toast.error(error);
                c.sending = false;
            }
        },

        // ------------------------------------------------------------ yan panel
        create() {
            this.user = { id: 0 };
            this.tab = 'info';
            this.form = { ...EMPTY, role: this.options.roles.find((r) => r.value === 'editor') ? 'editor' : this.options.roles[0]?.value || '', password: generatePassword() };
            this.showPassword = true;
            this.errors = {};
        },

        async edit(row) {
            this.user = { id: row.id, display_name: row.display_name || '' };
            this.tab = 'info';
            this.errors = {};
            this.showPassword = false;
            this.loadingDetail = true;
            try {
                const user = await api.user(row.id);
                this.user = user;
                this.form = { username: user.username, display_name: user.display_name, email: user.email, phone: user.phone, role: user.role, password: '' };
            } catch (error) {
                toast.error(error);
                this.user = null;
            } finally {
                this.loadingDetail = false;
            }
        },

        close() {
            this.user = null;
        },

        get isNew() {
            return this.user && !this.user.id;
        },

        get readonly() {
            return !this.canManage || (this.user?.role === 'admin' && !this.$store.session.isAdmin);
        },

        roleDescription(slug) {
            return this.options.roles.find((r) => r.value === slug)?.description || '';
        },

        newPassword() {
            this.form.password = generatePassword();
            this.showPassword = true;
        },

        async save() {
            if (this.saving || this.readonly) return;
            this.saving = true;
            this.errors = {};
            try {
                const body = { ...this.form };
                if (!this.isNew && !body.password) delete body.password;
                const user = this.isNew ? await api.createUser(body) : await api.updateUser(this.user.id, body);
                toast.success(this.isNew ? `${user.display_name} eklendi. Şifreyi kendisine güvenli bir yoldan iletin.` : 'Kullanıcı kaydedildi.');
                this.reload();
                await this.edit(user);
            } catch (error) {
                this.errors = error.details?.fields || {};
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        async copyPassword() {
            try {
                await navigator.clipboard.writeText(this.form.password);
                toast.success('Şifre kopyalandı.');
            } catch {
                toast.error('Kopyalanamadı; şifreyi elle seçin.');
            }
        },
    };
}
