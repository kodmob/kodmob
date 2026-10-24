/**
 * Panel uzantı noktası: projenin panel.js'i (profil klasöründe) çatının panel dosyalarına dokunmadan
 * içerik türleri (`TYPES`) ve özel sayfalar (`PAGES`) ekler. Bu dosya profil takma adı gerektirmez:
 * derleme (vite.config.js → km-manifest `panel`) ve Node testleri de açar; tarayıcıda profile.js okur.
 */

/**
 * Çatının panel içerik türleri: blog, blok sayfaları ve Mağaza'nın ürünleri (yan menüde İçerik bölümünün sekmeleri).
 * Modülü kapalı olan türün yetkisi yoktur, menüde görünmez. `menu: false`: menüde değil, Tüm İşlemler'de (blok
 * sayfaları Sayfa düzenleyiciden açılır). `blocks`: içeriği Sayfa düzenleyicide (düzenleyicide bağlantı).
 */
export const FRAMEWORK_TYPES = {
    posts: { label: 'Blog', singular: 'Yazı', icon: 'pencil', orderable: false, newLabel: 'Yeni yazı', record: 'post', can: 'km_manage_posts', slug: 'blog' },
    pages: { label: 'Blok sayfaları', singular: 'Blok sayfası', icon: 'layers', orderable: false, newLabel: 'Yeni sayfa', record: 'page', can: 'km_manage_pages', slug: 'blok-sayfalari', menu: false, blocks: true,
        guide: 'Sayfa düzenleyicide bloklarla kurulan sayfalar (kampanya, etkinlik, hakkımızda…): başlık, adres ve paylaşım bilgisi burada; içeriği Sayfa düzenleyicide.' },
    products: { label: 'Ürünler', singular: 'Ürün', icon: 'cart', orderable: true, newLabel: 'Yeni ürün', record: 'product', can: 'km_manage_products', slug: 'urunler', group: 'project',
        guide: 'Mağazanın ürünleri: fiyat, KDV, stok, görseller ve açıklama; her dilde ayrı ad ve adres. Sürükleyerek sıralanır; taslaktaki ürün satılmaz.' },
    tours: { label: 'Turlar', singular: 'Tur', icon: 'map', orderable: true, newLabel: 'Yeni tur', record: 'tour', can: 'km_manage_tours', slug: 'turlar', group: 'project',
        guide: 'Rezerve edilen turlar ve etkinlikler: kişi başı fiyatlar, günlük kapasite, tarihler; grup, sezon ve erken rezervasyon kuralları.' },
    hotels: { label: 'Oteller', singular: 'Otel', icon: 'moon', orderable: true, newLabel: 'Yeni otel', record: 'hotel', can: 'km_manage_hotels', slug: 'oteller', group: 'project',
        guide: 'Oteller, pansiyonlar ve villalar: oda tipleri ve pansiyon başına gecelik fiyatlar, oda adetleri, en az gece, giriş / çıkış saati; sezon ve erken rezervasyon kuralları.' },
};

/** Projenin türleri önce (menü sırası), çatınınkiler sonda. */
export const panelTypes = (projectTypes = {}) => ({ ...projectTypes, ...FRAMEWORK_TYPES });

/**
 * Projenin özel sayfaları (panel.js `PAGES`), boşlukları doldurulmuş:
 *
 *   { key: 'reports', path: 'raporlar/:tab?', label: 'Raporlar', icon: 'chart', section: 'inbox',
 *     can: 'km_view_reports', guide: 'Turdaki tanıtım.', hint: 'Tüm İşlemler kartındaki açıklama.',
 *     menu: false, load: () => import('./panel/reports.js'), template: 'panel/_reports.njk' }
 *
 * - `key`: sayfa ve görünüm adı: küçük harf, rakam, tire (Hızlı İşlem anahtarı olur; çatının sayfalarıyla çakışamaz) · `path`: adres (routes.js söz dizimi)
 * - `section`: yan menünün bölümü (varsayılan content: İçerik; inbox, growth, settings); sayfa o bölümde sekme olur ·
 *   `can`: yetki (projenin modülü verir)
 * - `load`: görünümün bileşenleri (modülün dışa açtığı işlevler Alpine'a kaydedilir; şablondaki x-data)
 * - `template`: profil klasöründe Nunjucks şablonu (sayfa açıkken çizilir)
 */
export function projectPages(pages = []) {
    return pages.map((p) => {
        if (!/^[a-z][a-z0-9-]{1,40}$/.test(p?.key || '')) throw new Error(`panel.js PAGES: geçersiz anahtar "${p?.key}"`);
        if (!p.path || !p.template) throw new Error(`panel.js PAGES ${p.key}: path ve template gerekli`);
        return { group: 'project', icon: 'layers', can: null, guide: '', hint: '', ...p };
    });
}

/**
 * Araç paketlerinin sayfaları (`virtual:kodmob-extensions`: `[{ key, panel }]`; vite/extensions.js). Biçim
 * projeninkiyle aynı; ek kurallar: sayfa anahtarı aracın anahtarıyla başlar (paketler çakışmasın), `can`
 * zorunlu (araç kapalıyken yetkisi yoktur, sayfa görünmez). Şablon paketin klasöründen (`<anahtar>/<template>`);
 * sayfada `tool: <anahtar>` (Tüm İşlemler'de Araçlar kartı).
 */
export function toolPages(list = []) {
    return list.flatMap(({ key, panel }) => projectPages(panel?.PAGES).map((p) => {
        if (p.key !== key && !p.key.startsWith(`${key}-`)) throw new Error(`${key} aracı: sayfa anahtarı "${key}" ile başlamalı (${p.key})`);
        if (!p.can) throw new Error(`${key} aracı ${p.key}: can (yetki) gerekli`);
        return { ...p, tool: key, template: `${key}/${p.template}` };
    }));
}
