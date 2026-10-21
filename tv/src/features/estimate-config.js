/**
 * Teklif hesaplayıcının yedek fiyatları.
 *
 * Asıl değerler panelden düzenlenir (Panel → Teklif hesaplayıcı) ve sayfa
 * verisiyle gelir (`$store.km.estimate`). Bu dosya yalnızca veri yoksa
 * (ör. API'ye ulaşılamadı) kullanılır; varsayılanları kodmob-api
 * Content\EstimateConfig::DEFAULTS ile aynıdır.
 *
 * - `prices.{TRY|EUR}.types`: proje türü → [en az, en çok] taban fiyat
 * - `prices.{TRY|EUR}.features`: seçilen her özelliğin eklediği tutar
 * - `design`: tasarım seviyesinin taban fiyata çarpanı
 * - `weeks` / `featureWeeks`: süre (hafta)
 * - `off`: sitede gösterilmeyen türler ve özellikler
 * - Para birimi sayfanın diline göre: TR → TRY, EN/DE → EUR (sözlükte estimate.currency)
 */
export const DEFAULTS = {
    prices: {
        TRY: {
            types: { landing: [25000, 45000], corporate: [60000, 120000], ecommerce: [150000, 300000], webapp: [200000, 450000], mobile: [250000, 500000], ai: [150000, 350000] },
            features: { cms: 15000, multilang: 20000, auth: 25000, payments: 30000, booking: 35000, integrations: 40000, seo: 10000, support: 36000 },
            round: 5000,
        },
        EUR: {
            types: { landing: [1500, 3000], corporate: [3500, 7000], ecommerce: [8000, 16000], webapp: [12000, 25000], mobile: [14000, 28000], ai: [9000, 20000] },
            features: { cms: 900, multilang: 1200, auth: 1500, payments: 1800, booking: 2000, integrations: 2500, seo: 600, support: 2400 },
            round: 250,
        },
    },
    design: { template: 0.85, custom: 1, premium: 1.3 },
    weeks: { landing: [1, 2], corporate: [3, 6], ecommerce: [6, 10], webapp: [8, 16], mobile: [10, 18], ai: [6, 12] },
    featureWeeks: { cms: 1, multilang: 1, auth: 1, payments: 1, booking: 2, integrations: 2, seo: 0, support: 0 },
    off: { types: [], features: [] },
};

/** Proje türü → brif sihirbazındaki hizmet anahtarı */
export const TYPE_SERVICE = { landing: 'web', corporate: 'web', ecommerce: 'ecommerce', webapp: 'custom', mobile: 'mobile', ai: 'ai' };
