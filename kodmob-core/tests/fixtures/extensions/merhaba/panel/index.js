/**
 * Merhaba aracının panel sayfaları (biçim: kodmob-frontend/src/panel/extension.js). Menüde "Proje modülleri"
 * altında, Tüm İşlemler'in Araçlar kartında, aramada ve turda görünür; yetkisi olmayana (ya da araç
 * kapalıyken) görünmez. Sayfa anahtarı aracın anahtarıyla başlar.
 */
export const PAGES = [
    {
        key: 'merhaba',
        path: 'merhaba',
        label: 'Merhaba',
        icon: 'sparkles',
        can: 'km_use_merhaba',
        guide: 'Merhaba: aracın kısa tanıtımı (Kullanım Kılavuzu turu).',
        load: () => import('./merhaba.js'),
        template: 'panel/_merhaba.njk',
    },
];
