/**
 * Mağaza'nın site sayfaları (isteğe bağlı modül `commerce`; docs/done-commerce-raporu.md). Şablon ve betik çatıda
 * (tv/src/pages/shop/); adres yapısı profilin (karar A): profil bu listeyi kendi rotalarına ekler, yolları
 * değiştirebilir, yalnızca sitesindeki dilleri verir.
 *
 *     import { commerceRoutes } from '../../../vite/commerce-routes.js';
 *     export const ROUTES = [ …, ...commerceRoutes({ shop: { tr: '/urunler/', en: '/products/' } }) ];
 *
 * Bütün rotalar `module: 'commerce'`: modül kapalıyken sayfalar 404 olur, site haritasına girmez. Sepet, ödeme
 * ve sipariş sayfaları arama motoru dışı. Satış sözleşmesi, ön bilgilendirme, teslimat ve iade metinleri yasal belgeler
 * (tv/src/legal; vite/legal.js), modülle açılır.
 */
const page = (name) => ({ template: `pages/shop/${name}.njk`, script: '/pages/shop/main.js', module: 'commerce' });

export const COMMERCE_PATHS = {
    shop: { tr: '/magaza/', en: '/shop/', de: '/shop/' },
    product: { tr: '/magaza/:slug/', en: '/shop/:slug/', de: '/shop/:slug/' },
    cart: { tr: '/sepet/', en: '/cart/', de: '/warenkorb/' },
    checkout: { tr: '/odeme/', en: '/checkout/', de: '/kasse/' },
    shopOrder: { tr: '/siparis/', en: '/order/', de: '/bestellung/' },
    tours: { tr: '/turlar/', en: '/tours/', de: '/touren/' },
    tour: { tr: '/turlar/:slug/', en: '/tours/:slug/', de: '/touren/:slug/' },
    hotels: { tr: '/oteller/', en: '/hotels/', de: '/hotels/' },
    hotel: { tr: '/oteller/:slug/', en: '/hotels/:slug/', de: '/hotels/:slug/' },
};

/**
 * @param {Partial<Record<keyof COMMERCE_PATHS, Record<string,string>>>} paths  değişen yollar
 * @param {string[]|null} locales  sitenin dilleri (verilmezse yollardaki hepsi)
 */
export function commerceRoutes(paths = {}, locales = null) {
    const p = { ...COMMERCE_PATHS, ...paths };
    const only = (byLocale) => (locales ? Object.fromEntries(Object.entries(byLocale).filter(([code]) => locales.includes(code))) : byLocale);
    const hidden = { sitemap: false, noindex: true };

    return [
        { name: 'shop', paths: only(p.shop), ...page('index'), needs: ['products'], titleKey: 'shop.meta.title', descriptionKey: 'shop.meta.description' },
        { name: 'product', dynamic: 'product', fallback: 'shop', paths: only(p.product), ...page('product'), titleKey: 'shop.meta.title' },
        { name: 'cart', paths: only(p.cart), ...page('cart'), titleKey: 'shop.cart.title', ...hidden },
        { name: 'checkout', paths: only(p.checkout), ...page('checkout'), titleKey: 'shop.checkout.title', ...hidden },
        { name: 'shopOrder', paths: only(p.shopOrder), ...page('order'), titleKey: 'shop.order.title', ...hidden },
    ];
}

/**
 * Rezervasyon'un sayfaları (Mağaza'ya takılı modül `booking`): turlar listesi ve tur sayfası (tarih, kişi sayıları,
 * anlık fiyat, sepete ekle). Sepet ve ödeme Mağaza'nın sayfaları; profil ikisini birlikte ekler.
 */
export function bookingRoutes(paths = {}, locales = null) {
    const p = { ...COMMERCE_PATHS, ...paths };
    const only = (byLocale) => (locales ? Object.fromEntries(Object.entries(byLocale).filter(([code]) => locales.includes(code))) : byLocale);
    const book = (name) => ({ ...page(name), module: 'booking' });

    return [
        { name: 'tours', paths: only(p.tours), ...book('tours'), needs: ['tours'], titleKey: 'shop.booking.meta.title', descriptionKey: 'shop.booking.meta.description' },
        { name: 'tour', dynamic: 'tour', fallback: 'tours', paths: only(p.tour), ...book('tour'), titleKey: 'shop.booking.meta.title' },
    ];
}

/**
 * Oteller'in sayfaları (Rezervasyon'a takılı araç `hotels`): otel listesi ve otel sayfası (giriş – çıkış, oda, oda
 * tipi × pansiyon, anlık fiyat). Sepet ve ödeme Mağaza'nın sayfaları.
 */
export function hotelRoutes(paths = {}, locales = null) {
    const p = { ...COMMERCE_PATHS, ...paths };
    const only = (byLocale) => (locales ? Object.fromEntries(Object.entries(byLocale).filter(([code]) => locales.includes(code))) : byLocale);
    const stay = (name) => ({ ...page(name), module: 'hotels' });

    return [
        { name: 'hotels', paths: only(p.hotels), ...stay('hotels'), needs: ['hotels'], titleKey: 'shop.hotels.meta.title', descriptionKey: 'shop.hotels.meta.description' },
        { name: 'hotel', dynamic: 'hotel', fallback: 'hotels', paths: only(p.hotel), ...stay('hotel'), titleKey: 'shop.hotels.meta.title' },
    ];
}
