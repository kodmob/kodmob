/**
 * Mağaza › Rezervasyonlar (/panel/rezervasyonlar; kodmob-api Modules\Commerce\Booking). Tarihe göre tablo (kmGrid,
 * bellek içi): rezerve edilen kayıt (tur, otel, kiralama, transfer…) ve paketi (otelde oda tipi), kişi / adet, müşteri, siparişin durumu; "Yaklaşan" / "Geçmiş" sekmeleri. Ödeme ve durum
 * değişikliği siparişin kendisinden (Siparişler'e gider).
 */
import { api } from './api.js';
import { href } from './routes.js';
import { localSource } from '../ui/grid/local-source.js';

const day = (date) => (date ? date.split('-').reverse().join('.') : '');
/** Miktar birime göre: kişi başında yetişkin / çocuk / bebek, gün biriminde adet, gecede oda ve kişi, sabitte kişi bilgisi yok. */
const persons = (r) => {
    if (r.unit === 'day') return `${r.persons} adet`;
    if (r.unit === 'fixed') return '1 rezervasyon';
    if (r.unit === 'night') return [`${r.persons} oda`, `${r.adults} yetişkin`, r.children ? `${r.children} çocuk` : ''].filter(Boolean).join(', ');
    return [`${r.adults} yetişkin`, r.children ? `${r.children} çocuk` : '', r.infants ? `${r.infants} bebek` : ''].filter(Boolean).join(', ');
};
/** Gün biriminde alış – teslim, gecede giriş – çıkış. */
const when = (r) => (r.end ? `${day(r.date)} – ${day(r.end)}` : day(r.date));

export function kmBookings() {
    return {
        grid: null,

        init() {
            this.grid = {
                id: 'bookings',
                syncUrl: true,
                source: async (q) => localSource((await api.bookings()).items, { search: ['tour', 'number', 'customer_name', 'customer_email'], facets: ['upcoming', 'status'], sums: ['persons'] })(q),
                pageSize: 50,
                sort: { key: 'date', order: 'asc' },
                values: { upcoming: 'yes' },
                exportName: 'rezervasyonlar',
                empty: { title: 'Bu görünümde rezervasyon yok', text: 'Sitedeki tur, otel, kiralama ve transfer sayfalarından yapılan rezervasyonlar burada.', icon: 'calendar' },
                columns: [
                    { key: 'date', label: 'Tarih', sortable: true, strong: true, cell: when, card: 'title' },
                    { key: 'tour', label: 'Rezervasyon', sortable: true, cell: (r) => [r.tour, r.package].filter(Boolean).join(' · '), card: 'subtitle' },
                    { key: 'kind', label: 'Tür', muted: true, hideBelow: 'lg' },
                    { key: 'persons', label: 'Kişi', sortable: true, cell: persons, exportValue: (r) => r.persons, card: 'meta' },
                    { key: 'customer_name', label: 'Müşteri', sortable: true, card: 'spec' },
                    { key: 'customer_phone', label: 'Telefon', muted: true, hideBelow: 'lg' },
                    { key: 'number', label: 'Sipariş', muted: true, hideBelow: 'lg' },
                    { key: 'status', label: 'Durum', badge: 'order', card: 'badge' },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Tur, sipariş no ya da müşteri' },
                    { key: 'upcoming', type: 'tabs', label: 'Zaman', options: [{ value: 'yes', label: 'Yaklaşan' }, { value: 'no', label: 'Geçmiş' }] },
                ],
                rowActions: [{ key: 'order', label: 'Siparişi aç', primary: true }],
            };
        },

        onAction({ action, row }) {
            if (action === 'order') window.dispatchEvent(new CustomEvent('km-navigate', { detail: { url: href('orders', { id: row.order_id }) } }));
        },
    };
}
