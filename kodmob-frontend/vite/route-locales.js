/**
 * Rota adreslerini profilin dilleriyle süzer (çatının araç rotaları: Mağaza, Rezervasyon, Oteller, Bülten, Üyelik):
 * `{ tr: '/bulten/', en: '/newsletter/' }` → yalnızca istenen diller. `locales` verilmezse hepsi.
 */
export const onlyLocales = (byLocale, locales = null) => (locales ? Object.fromEntries(Object.entries(byLocale).filter(([code]) => locales.includes(code))) : byLocale);
