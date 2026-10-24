/**
 * Mağaza › Siparişler (kodmob-api Modules\Commerce; /panel/siparisler). Ortak tablo (kmGrid): durum sekmeleri
 * sayılarıyla, ödeme yöntemi süzgeci, arama, CSV (yan paneldeki sipariş `sale`: tablonun `order`'ıyla karışmasın); yan panelde sipariş (müşteri, adres, ürünler, tutarlar,
 * ödemeler) ve durum düğmeleri (sunucunun akışı: `next`). Durum değişince müşteriye e-posta gider.
 *
 * `/panel/siparisler/12` (bildirimden) siparişi doğrudan açar.
 */
import { api } from './api.js';
import { relative } from './meta.js';
import { fmt } from '../domain/format.js';
import { status } from '../domain/status.js';
import { toast } from '../ui/toast.js';
import { localSource } from '../ui/grid/local-source.js';
import { confirmAction } from '../ui/confirm/confirm.js';

export const PAYMENT_LABELS = { bank_transfer: 'Havale / EFT', cash_on_delivery: 'Kapıda ödeme', stripe: 'Stripe', paytr: 'PayTR' };

/** Durum düğmelerinin adı (gidilecek durum). */
const NEXT = {
    paid: { label: 'Ödendi', icon: 'check-circle', tone: 'btn-primary' },
    processing: { label: 'Hazırlanıyor', icon: 'package', tone: 'btn-secondary' },
    shipped: { label: 'Kargoya verildi', icon: 'truck', tone: 'btn-secondary' },
    completed: { label: 'Tamamlandı', icon: 'check', tone: 'btn-primary' },
    cancelled: { label: 'İptal et', icon: 'ban', tone: 'btn-ghost text-danger' },
    refunded: { label: 'İade et', icon: 'rotate-ccw', tone: 'btn-ghost text-danger' },
};

/** Siparişin ana hattı (adım göstergesi). */
const FLOW = ['pending', 'paid', 'processing', 'shipped', 'completed'];

export function kmOrders(openId = null) {
    return {
        relative,
        fmt,
        status,
        grid: null,
        sale: null,
        saleLoading: false,
        busy: false,

        init() {
            this.grid = this.gridConfig();
            if (openId) this.show({ id: openId });
        },

        get canManage() {
            return this.$store.session.can('km_manage_orders');
        },

        gridConfig() {
            return {
                id: 'orders',
                syncUrl: true,
                source: async (q) => localSource((await api.orders()).items, { search: ['number', 'customer_name', 'customer_email'], facets: ['status', 'payment_method'], sums: ['total'] })(q),
                pageSize: 20,
                sort: { key: 'created_at', order: 'desc' },
                exportName: 'siparisler',
                empty: { title: 'Bu görünümde sipariş yok', text: 'Sitedeki mağazadan verilen siparişler burada.', icon: 'cart' },
                columns: [
                    { key: 'number', label: 'Sipariş', sortable: true, strong: true, card: 'title' },
                    { key: 'customer_name', label: 'Müşteri', sortable: true, card: 'subtitle' },
                    { key: 'customer_email', label: 'E-posta', muted: true, hidden: true, hideBelow: 'xl' },
                    { key: 'total', label: 'Tutar', sortable: true, cell: (r) => r.totalText, exportValue: (r) => (r.total / 100).toFixed(2), card: 'meta' },
                    { key: 'payment_method', label: 'Ödeme', cell: (r) => PAYMENT_LABELS[r.payment_method] || r.payment_method, filterBy: 'payment_method', hideBelow: 'lg', card: 'spec' },
                    { key: 'status', label: 'Durum', badge: 'order', card: 'badge' },
                    { key: 'created_at', label: 'Tarih', format: 'relative', sortable: true, card: 'spec', exportValue: (r) => fmt.dateTime(r.created_at) },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Sipariş no, ad ya da e-posta' },
                    { key: 'status', type: 'tabs', label: 'Durum', statusKind: 'order' },
                    { key: 'payment_method', type: 'select', label: 'Ödeme', options: Object.entries(PAYMENT_LABELS).map(([value, label]) => ({ value, label })) },
                ],
                rowActions: [{ key: 'open', label: 'Aç', primary: true, open: true }],
            };
        },

        reload() {
            window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id: 'orders' } }));
        },

        async show(row) {
            this.saleLoading = true;
            this.sale = { ...row, items: [], payments: [], next: [] };
            try {
                this.sale = await api.shopOrder(row.id);
            } catch (error) {
                toast.error(error);
                this.sale = null;
            } finally {
                this.saleLoading = false;
            }
        },

        payment(key) {
            return PAYMENT_LABELS[key] || key;
        },

        next(key) {
            return NEXT[key] || { label: key, icon: 'arrow-right', tone: 'btn-secondary' };
        },

        /**
         * Siparişin yolu (kodmob-api Orders::FLOW'un ana hattı): ödeme bekliyor → ödendi → hazırlanıyor → kargoda →
         * tamamlandı. Atlanan adım (ör. ödenmeden hazırlanan, kargosuz tamamlanan) geçilmiş sayılır. İptal / iade hat
         * dışıdır: `flowStop` uyarı olarak gösterilir.
         */
        flow(sale) {
            const at = FLOW.indexOf(sale?.status);
            return FLOW.map((key, i) => ({ key, label: status('order', key).label, state: at < 0 ? 'off' : i < at ? 'done' : i === at ? 'now' : 'todo' }));
        },

        flowStop(sale) {
            return ['cancelled', 'refunded'].includes(sale?.status) ? status('order', sale.status).label : '';
        },

        address(a = {}) {
            return [a.line, [a.district, a.city].filter(Boolean).join(' / '), a.postal, a.country].filter(Boolean).join(', ');
        },

        async move(target) {
            if (this.busy) return;
            if (['cancelled', 'refunded'].includes(target)) {
                const ok = await confirmAction({
                    title: `${this.sale.number} ${target === 'cancelled' ? 'iptal edilsin' : 'iade edilsin'} mi?`,
                    message: 'Stok takibi olan ürünlerin adedi geri eklenir; müşteriye e-posta gider. Bu işlem geri alınmaz.',
                    confirmText: target === 'cancelled' ? 'İptal et' : 'İade et',
                    tone: 'danger',
                });
                if (!ok) return;
            }
            this.busy = true;
            try {
                this.sale = await api.orderStatus(this.sale.id, target);
                toast.success(`Durum: ${status('order', target).label}. Müşteriye bildirildi.`);
                this.reload();
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = false;
            }
        },
    };
}
