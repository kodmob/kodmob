/**
 * Gelenler › Üyeler (site üyeliği; kodmob-core Modules\Members): arama, durum süzgeci, kapat / aç, sil. Kişisel veri:
 * yalnızca `km_view_members`; değiştirmek `km_manage_members`.
 */
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

export function kmMembers() {
    return {
        items: [],
        total: 0,
        page: 1,
        pages: 0,
        search: '',
        status: '',
        loading: true,
        timer: null,

        init() {
            this.load();
        },

        get canManage() {
            return this.$store.session.can('km_manage_members');
        },

        async load() {
            this.loading = true;
            try {
                const res = await api.members({ page: this.page, search: this.search, status: this.status });
                Object.assign(this, { items: res.items, total: res.total, pages: res.pages });
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        find() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => {
                this.page = 1;
                this.load();
            }, 300);
        },

        async toggle(m) {
            const status = m.status === 'active' ? 'disabled' : 'active';
            try {
                await api.memberUpdate(m.id, { status });
                m.status = status;
                toast.success(status === 'disabled' ? 'Üyelik kapatıldı; açık oturumları sonlandı.' : 'Üyelik açıldı.');
            } catch (error) {
                toast.error(error);
            }
        },

        async remove(m) {
            if (!(await confirmAction({ title: `${m.email} silinsin mi?`, message: 'Üye ve oturumları kalıcı olarak silinir. Siparişleri kalır.', confirmText: 'Sil', tone: 'danger' }))) return;
            try {
                await api.memberDelete(m.id);
                toast.success('Üye silindi.');
                this.load();
            } catch (error) {
                toast.error(error);
            }
        },
    };
}
