/**
 * Sitenin sayfa listesi — tek kaynak. Her rota her dil için ayrı bir HTML olarak derlenir; adresi dil öneki +
 * yol (varsayılan dil öneksiz). Çatının rotaları (panel) tv/vite/framework-routes.js'te ayrıca eklenir.
 *
 * - `name`: rota adı; şablonda `url('blog')` ile adres üretilir
 * - `paths`: dil → yol. Bir dil yoksa sayfa o dilde üretilmez.
 * - `dynamic: 'post'`: kayıt sayfası; `:slug` kaydın o dildeki adresidir. Her dilde tek kabuk üretilir.
 * - `needs`: sayfanın içerik ihtiyacı (posts; içerik türü eklenince onun adı). Bkz. kodmob-api Content\PageData.
 * - `template` / `script`: bu profilin (yoksa tv/src'nin) şablonu ve giriş modülü
 * - `titleKey` / `descriptionKey`: sözlükteki başlık ve açıklama
 * - `sitemap: false`, `noindex: true`: arama motoru dışı; `data(locale)`: şablona ek veri
 *
 * Yeni sayfa: buraya bir satır, pages/<ad>/index.njk + main.js, sözlükte metinleri.
 */
import { bookingRoutes, commerceRoutes, hotelRoutes } from '../../../vite/commerce-routes.js';

const page = (name) => ({ template: `pages/${name}/index.njk`, script: `pages/${name}/main.js` });

export const ROUTES = [
    { name: 'home', paths: { tr: '/', en: '/' }, ...page('home'), needs: ['posts'], titleKey: 'home.meta.title', descriptionKey: 'home.meta.description' },

    { name: 'blog', paths: { tr: '/blog/', en: '/blog/' }, ...page('blog'), needs: ['posts'], titleKey: 'blog.meta.title', descriptionKey: 'blog.meta.description' },
    { name: 'post', dynamic: 'post', fallback: 'blog', paths: { tr: '/blog/:slug/', en: '/blog/:slug/' }, ...page('post'), titleKey: 'nav.blog' },

    { name: 'contact', paths: { tr: '/iletisim/', en: '/contact/' }, ...page('contact'), titleKey: 'contact.meta.title', descriptionKey: 'contact.meta.description' },


    // Mağaza (isteğe bağlı modül commerce): profile.modules'e 'commerce' eklenince açılır; kapalıyken 404.
    ...commerceRoutes({}, ['tr', 'en']),
    // Rezervasyon (commerce'e takılı modül booking): turlar ve tur sayfası.
    ...bookingRoutes({}, ['tr', 'en']),
    // Oteller (booking'e takılı modül hotels): otel listesi ve otel sayfası.
    ...hotelRoutes({}, ['tr', 'en']),

    { name: 'notFound', paths: { tr: '/404.html', en: '/404.html' }, ...page('404'), titleKey: 'notFound.title', sitemap: false, noindex: true },
];
