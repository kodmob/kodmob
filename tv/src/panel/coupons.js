/**
 * Mağaza › Kuponlar (/panel/kuponlar; kodmob-api Modules\Commerce\Coupons). Tablo (kmGrid, bellek içi) ve
 * pencerede form: kod, yüzde ya da tutar, alt sınır, kullanım sınırı, tarih aralığı, etkin. Tutarlar formda
 * ondalıklı (149,90), sunucuda kuruş. Kullanım sipariş verilince sayılır.
 */
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { localSource } from '../ui/grid/local-source.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { fromMinor, toMinor } from '../domain/money.js';

const blank = () => ({ id: null, code: '', type: 'percent', value: '', min_total: '', usage_limit: 0, starts_at: '', expires_at: '', active: true, note: '' });
const local = (date) => (date ? String(date).slice(0, 16).replace(' ', 'T') : '');

export function kmCoupons() {
    return {
        fmt,
        grid: null,
        form: null,
        errors: {},
        saving: false,

        init() {
            this.grid = {
                id: 'coupons',
                source: async (q) => localSource((await api.coupons()).items, { search: ['code', 'note'], facets: ['type'] })(q),
                pageSize: 20,
                sort: { key: 'code', order: 'asc' },
                empty: { title: 'Henüz kupon yok', text: '"Yeni kupon" ile ilk indirimi ekleyin.', icon: 'tag' },
                columns: [
                    { key: 'code', label: 'Kod', sortable: true, strong: true, card: 'title' },
                    { key: 'label', label: 'İndirim', card: 'subtitle' },
                    { key: 'used', label: 'Kullanım', cell: (r) => (r.usage_limit ? `${r.used} / ${r.usage_limit}` : String(r.used)), card: 'meta' },
                    { key: 'expires_at', label: 'Bitiş', cell: (r) => (r.expires_at ? fmt.dateTime(r.expires_at) : '—'), hideBelow: 'lg', card: 'spec' },
                    { key: 'active', label: 'Durum', cell: (r) => (r.active ? 'Etkin' : 'Kapalı'), card: 'spec' },
                ],
                filters: [{ key: 'q', type: 'search', placeholder: 'Kod ya da not' }],
                rowActions: [
                    { key: 'open', label: 'Düzenle', primary: true, open: true },
                    { key: 'delete', label: 'Sil', icon: 'trash', danger: true },
                ],
            };
        },

        reload() {
            window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id: 'coupons' } }));
        },

        open(row = null) {
            this.errors = {};
            this.form = row
                ? { ...row, value: row.type === 'fixed' ? fromMinor(row.value) : String(row.value), min_total: fromMinor(row.min_total), starts_at: local(row.starts_at), expires_at: local(row.expires_at) }
                : blank();
        },

        async save() {
            if (this.saving) return;
            this.saving = true;
            this.errors = {};
            const f = this.form;
            const body = {
                code: f.code, type: f.type, note: f.note, active: f.active, usage_limit: Number(f.usage_limit) || 0,
                value: f.type === 'fixed' ? toMinor(f.value) : Math.round(Number(String(f.value).replace(',', '.')) || 0),
                min_total: toMinor(f.min_total), starts_at: f.starts_at || null, expires_at: f.expires_at || null,
            };
            try {
                await api.saveCoupon(f.id, body);
                toast.success(f.id ? 'Kupon güncellendi.' : 'Kupon eklendi.');
                this.form = null;
                this.reload();
            } catch (error) {
                this.errors = error?.details?.fields || {};
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        async onAction({ action, row }) {
            if (action !== 'delete') return;
            if (!(await confirmAction({ title: `${row.code} silinsin mi?`, message: 'Kullanılmış siparişlerdeki indirim değişmez.', confirmText: 'Sil', tone: 'danger' }))) return;
            try {
                await api.deleteCoupon(row.id);
                toast.success('Kupon silindi.');
                this.reload();
            } catch (error) {
                toast.error(error);
            }
        },
    };
}
