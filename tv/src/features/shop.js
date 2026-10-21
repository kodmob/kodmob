/**
 * Mağaza'nın tarayıcı bileşenleri (pages/shop/*; kodmob-api Modules\Commerce\Http\ShopController). Sepet
 * sunucuda: tarayıcı yalnızca uçları çağırır, anahtar HttpOnly `km_cart` çerezinde. Fiyat, stok, kupon ve
 * kargo her yanıtta sunucunun hesabıdır.
 *
 *   shopAdd(id)  ürün sayfasında adet + "Sepete ekle"      shopCart()     sepet sayfası
 *   shopCheckout()  ödeme formu → sipariş                   shopOrder()    müşterinin sipariş sayfası
 *   shopBook(entry)  rezervasyon kutusu: paket, tarih, kişi / gün / adet, anlık fiyat ve kalan yer → sepet
 */
import { http } from '@/services/http.js';
import { toast } from '@/ui/toast.js';
import { t, lang } from '@/i18n/client.js';

const shop = {
    cart: (payment = '') => http.get('/shop/cart', { locale: lang(), ...(payment ? { payment } : {}) }),
    add: (product, quantity, type = 'product', data = {}) => http.post(`/shop/cart/items?locale=${lang()}`, { product, quantity, type, data }),
    set: (line, quantity) => http.patch(`/shop/cart/items/${encodeURIComponent(line)}?locale=${lang()}`, { quantity }),
    remove: (line) => http.delete(`/shop/cart/items/${encodeURIComponent(line)}?locale=${lang()}`),
    coupon: (code) => http.post(`/shop/cart/coupon?locale=${lang()}`, { code }),
    checkout: (body) => http.post('/shop/checkout', { ...body, locale: lang() }),
    order: (number, token) => http.get(`/shop/orders/${encodeURIComponent(number)}`, { token }),
    pay: (number, token) => http.post(`/shop/orders/${encodeURIComponent(number)}/pay`, { token }),
    availability: (tour, month = '') => http.get(`/shop/bookings/${tour}/availability`, month ? { month } : {}),
    quote: (body) => http.post('/shop/bookings/quote', { ...body, locale: lang() }),
};

/** Sunucunun alan hataları (`details.fields`). */
const fieldsOf = (error) => error?.details?.fields || {};

export function shopAdd(productId) {
    return {
        quantity: 1,
        busy: false,
        async add() {
            if (this.busy) return;
            this.busy = true;
            try {
                await shop.add(productId, Math.max(1, Number(this.quantity) || 1));
                toast.success(t('shop.added'));
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = false;
            }
        },
    };
}

export function shopCart() {
    return {
        cart: null,
        loading: true,
        busy: false,
        code: '',
        async init() {
            try {
                this.cart = await shop.cart();
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },
        async run(call, message = '') {
            if (this.busy) return;
            this.busy = true;
            try {
                this.cart = await call();
                if (message) toast.success(message);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = false;
            }
        },
        setQuantity(item, quantity) {
            return this.run(() => shop.set(item.key, Math.max(0, Number(quantity) || 0)), t('shop.updated'));
        },
        remove(item) {
            return this.run(() => shop.remove(item.key), t('shop.updated'));
        },
        applyCoupon() {
            return this.run(() => shop.coupon(this.code.trim()), this.code.trim() ? t('shop.couponApplied') : '');
        },
        removeCoupon() {
            this.code = '';
            return this.run(() => shop.coupon(''));
        },
    };
}

export function shopCheckout(orderUrl) {
    return {
        cart: null,
        loading: true,
        busy: false,
        errors: {},
        form: { name: '', email: '', phone: '', address: { line: '', district: '', city: '', postal: '', country: '' }, note: '', payment: '', terms: false },
        async init() {
            await this.refresh();
            this.loading = false;
            this.$watch('form.payment', () => this.refresh());
        },
        async refresh() {
            try {
                this.cart = await shop.cart(this.form.payment);
                if (!this.form.payment && this.cart.payments?.length) this.form.payment = this.cart.payments[0].key;
            } catch (error) {
                toast.error(error);
            }
        },
        err(path) {
            return this.errors[path] || '';
        },
        async submit() {
            if (this.busy) return;
            if (!this.form.terms) {
                this.errors = { terms: t('shop.terms') };
                return toast.error(t('shop.terms'));
            }
            this.busy = true;
            this.errors = {};
            try {
                const result = await shop.checkout(this.form);
                // Online ödeme: sağlayıcının sayfasına; ödemeden sonra sipariş sayfasına döner.
                window.location.href = result.payment?.redirect || `${orderUrl}?no=${encodeURIComponent(result.order.number)}&anahtar=${result.token}&yeni=1`;
            } catch (error) {
                this.errors = fieldsOf(error);
                toast.error(Object.keys(this.errors).length ? t('shop.invalid') : error);
            } finally {
                this.busy = false;
            }
        },
    };
}

export function shopOrder() {
    return {
        order: null,
        loading: true,
        missing: false,
        fresh: false,
        paying: false,
        result: '',
        async init() {
            const query = new URLSearchParams(window.location.search);
            this.fresh = query.has('yeni') || query.get('odeme') === 'tamam';
            this.result = query.get('odeme') || '';
            this.query = query;
            try {
                this.order = await shop.order(query.get('no') || '', query.get('anahtar') || '');
            } catch {
                this.missing = true;
            } finally {
                this.loading = false;
            }
        },
        /** Online ödemeyi (yeniden) başlatır: sağlayıcının sayfasına gider. */
        async pay() {
            if (this.paying) return;
            this.paying = true;
            try {
                const { payment } = await shop.pay(this.order.number, this.query.get('anahtar') || '');
                if (payment?.redirect) window.location.href = payment.redirect;
                else toast.error(payment?.error || t('shop.error'));
            } catch (error) {
                toast.error(error);
            } finally {
                this.paying = false;
            }
        },
    };
}

/** Gün ekler: "2026-11-05" + 2 → "2026-11-07". */
const addDays = (date, days) => {
    const d = new Date(`${date}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
};

/**
 * Rezervasyon kutusu (pages/shop/_booking-box.njk): rezerve edilebilen her kayıt (`entry.booking`; kodmob-api
 * Booking\BookingView). Birime göre girişler: kişi (yetişkin / çocuk / bebek), gün (alış – teslim, adet), sabit
 * (kişi sayısı); paket seçimi. Fiyat, kurallar ve kalan yer her değişiklikte sunucudan; dar ekranda alt sayfa.
 */
export function shopBook(entry, cartUrl = '') {
    const b = { unit: 'person', features: [], packages: [], groups: [], rooms: [], dates: entry.dates || [], prices: {}, fromText: entry.priceAdultText || '', minDays: 1, maxPersons: 0, ...(entry.booking || {}) };
    // Gece birimi: ilk oda tipi ve onun ilk pansiyonu seçili gelir (sunucu da seçim yoksa ilk seçeneği alır).
    const firstRoom = b.rooms[0] || null;
    // Tarih aralığıyla rezerve edilen birimler (alış – teslim, giriş – çıkış).
    const ranged = b.unit === 'day' || b.unit === 'night';
    return {
        b,
        unit: b.unit,
        dates: b.dates || [],
        date: (b.dates || [])[0] || '',
        end: '',
        first: '',
        adults: 1,
        children: 0,
        infants: 0,
        quantity: 1,
        persons: 1,
        pkg: b.packages[0]?.key || '',
        room: firstRoom?.key || '',
        board: firstRoom?.boards[0]?.board || '',
        ranged,
        quote: null,
        error: '',
        busy: false,
        seq: 0,
        sheet: false,
        async init() {
            // İzleyiciler önce: ziyaretçi ilk fiyat gelmeden de değiştirebilir.
            for (const field of ['date', 'end', 'adults', 'children', 'infants', 'quantity', 'persons', 'pkg', 'room', 'board']) this.$watch(field, () => this.refresh());
            // Gün ve gece biriminde teslim / çıkış, başlangıçtan en az `minDays` sonra.
            this.$watch('date', (value) => {
                if (this.ranged && value && (!this.end || this.end <= value)) this.end = addDays(value, this.b.minDays || 1);
            });
            if (!this.dates.length) {
                try {
                    this.first = (await shop.availability(entry.id)).first;
                    this.date ||= this.first;
                } catch (error) {
                    toast.error(error);
                }
            }
            if (this.ranged && this.date && !this.end) this.end = addDays(this.date, this.b.minDays || 1);
            await this.refresh();
        },

        /** Kutudaki sayaçlar (birime göre). */
        get counters() {
            const s = (key, params) => t(`shop.booking.${key}`, params);
            if (this.unit === 'day') return [{ field: 'quantity', label: s('quantity'), hint: '', min: 1, max: 20 }];
            if (this.unit === 'night') {
                return [
                    { field: 'quantity', label: s('rooms'), hint: s('roomsHint'), min: 1, max: 20 },
                    { field: 'adults', label: s('adults'), hint: s('adultsHint'), min: 1, max: 50 },
                    { field: 'children', label: s('children'), hint: s('childrenStayHint'), min: 0, max: 50 },
                ];
            }
            if (this.unit === 'fixed') return [{ field: 'persons', label: s('persons'), hint: this.b.maxPersons ? s('maxPersons', { max: this.b.maxPersons }) : '', min: 1, max: this.b.maxPersons || 50 }];
            return [
                { field: 'adults', label: s('adults'), hint: s('adultsHint'), min: 1, max: 50 },
                { field: 'children', label: s('children'), hint: s('childrenHint'), min: 0, max: 50 },
                { field: 'infants', label: s('infants'), hint: s('infantsHint'), min: 0, max: 20 },
            ];
        },

        get unitLabel() {
            return t(`shop.booking.${{ person: 'perPerson', day: 'perDay', fixed: 'perBooking', night: 'perNight' }[this.unit] || 'perPerson'}`);
        },

        get minEnd() {
            return this.date ? addDays(this.date, this.b.minDays || 1) : '';
        },

        /** Oda tipi seçimi var mı (yalnızca gecelik fiyatla satılan kayıtta yok). */
        get hasRooms() {
            return this.b.rooms.some((r) => r.key);
        },

        /** Oda tipi ve pansiyon seçer. */
        pick(room, board) {
            this.room = room;
            this.board = board;
        },

        boardLabel(code) {
            return code ? t(`shop.booking.boards.${code}`) : t('shop.booking.perNight');
        },

        roomFor(n) {
            return t('shop.booking.roomFor', { n });
        },

        /** "3 gece" (sunucunun hesapladığı aralık). */
        get nightsText() {
            return this.quote?.days ? t('shop.booking.nights', { n: this.quote.days }) : '';
        },

        /** Kişi sayısının ulaştığı en büyük grup kademesi (kutuda vurgulu). */
        get activeTier() {
            const persons = Number(this.adults) + Number(this.children);
            return this.b.groups.filter((g) => persons >= g.min).reduce((best, g) => Math.max(best, g.min), 0);
        },

        tierLabel(g) {
            return t('shop.booking.groupTier', { min: g.min });
        },

        step(field, delta) {
            const counter = this.counters.find((c) => c.field === field);
            if (!counter) return;
            this[field] = Math.min(counter.max, Math.max(counter.min, Number(this[field]) + delta));
        },

        /** Sunucuya giden seçim (birime göre). */
        request() {
            const out = { date: this.date };
            if (this.unit === 'day') Object.assign(out, { end: this.end, quantity: Number(this.quantity) || 1 });
            else if (this.unit === 'night') {
                Object.assign(out, { end: this.end, quantity: Number(this.quantity) || 1, adults: Number(this.adults) || 0, children: Number(this.children) || 0 });
                if (this.hasRooms) Object.assign(out, { room: this.room, board: this.board });
            }
            else if (this.unit === 'fixed') out.persons = Number(this.persons) || 1;
            else Object.assign(out, { adults: Number(this.adults) || 0, children: Number(this.children) || 0, infants: Number(this.infants) || 0 });
            if (this.pkg) out.package = this.pkg;
            return out;
        },

        async refresh() {
            if (!this.date || (this.ranged && !this.end)) return;
            // Yalnızca son isteğin yanıtı yazılır (hızlı değişiklikte eski yanıt yeniyi ezmesin).
            const seq = ++this.seq;
            try {
                const quote = await shop.quote({ item: entry.id, ...this.request() });
                if (seq !== this.seq) return;
                this.quote = quote;
                this.error = '';
            } catch (error) {
                if (seq !== this.seq) return;
                this.quote = null;
                const fields = fieldsOf(error);
                this.error = Object.values(fields)[0] || error?.message || t('shop.error');
            }
        },

        /** Uygulanan kural: "Grup indirimi −%10", "Sezon farkı +%20". */
        rule(r) {
            const sign = r.kind === 'season' && r.percent > 0 ? '+' : '−';
            return `${t('shop.booking.' + r.kind)} ${sign}%${Math.abs(r.percent)}`;
        },
        day(date) {
            return new Intl.DateTimeFormat(document.documentElement.lang || 'tr', { day: 'numeric', month: 'long', year: 'numeric', weekday: 'short' }).format(new Date(`${date}T12:00:00`));
        },
        async book() {
            if (this.busy || !this.quote) return;
            this.busy = true;
            try {
                await shop.add(entry.id, 1, 'booking', this.request());
                window.location.href = cartUrl;
            } catch (error) {
                this.error = Object.values(fieldsOf(error))[0] || '';
                toast.error(error);
            } finally {
                this.busy = false;
            }
        },
    };
}
