/**
 * Site profili: yeni projede ilk düzenlenen dosya (Kodmob başlangıç profili). Siteye özgü olup dile bağlı
 * olmayanlar burada; metinler i18n/<dil>.json, adres yapısı routes.config.js ve locales.js (hepsi bu klasörde;
 * sayfalar pages/, üst bar ve alt bilgi shell/). Derleme bunları km-manifest.json'a yazar.
 *
 * - `url`: canlı adres (.env SITE_URL yoksa).
 * - `organization`: yapısal veri (schema.org) kurum bilgisi; panelde SEO > Ayarlar'dakiler bunun yerine geçer.
 * - `vocab`: sunucunun da bildiği sözlükler: ad → sözlükteki yer (blog kategorileri gibi).
 * - `searchPages`: site içi aramada sayfa olarak çıkan rotalar.
 * - `profile`: açık isteğe bağlı modüller ve içerik türleri. Modüller: blog, briefs (talepler / iletişim
 *   formu), estimate, jarvis, domains, sms, sites. İçerik türü yoksa boş liste; yenisi
 *   `php artisan kodmob:module` ile eklenir (buraya da yazar).
 * - `zones`: sayfa düzenleyicinin bölgelerindeki ilk bloklar (`{ type, data }`; `@anahtar` sözlükten). Panelde
 *   yayınlanan düzen bunların yerine geçer (vite/blocks.js, docs/done-onyuz-raporu.md).
 */
export const SITE = {
    url: 'https://ornek.com',
    profile: {
        modules: ['blog', 'briefs'],
        contentTypes: [
            // kodmob:module
        ],
    },
    organization: {
        type: 'Organization',
    },
    vocab: {
        blogCategories: 'blog.categories',
    },
    searchPages: ['home', 'blog', 'contact'],
    zones: {
        'home.main': [
            { type: 'hero', data: { eyebrow: '@home.hero.eyebrow', title: '@home.hero.title', titleAccent: '@home.hero.titleAccent', lead: '@home.hero.lead', primary: '@home.hero.ctaPrimary', secondary: '@home.hero.ctaSecondary' } },
            { type: 'features', data: { eyebrow: '@home.features.eyebrow', title: '@home.features.title', titleAccent: '@home.features.titleAccent', items: '@home.features.items' } },
            { type: 'steps', data: { eyebrow: '@home.process.eyebrow', title: '@home.process.title', titleAccent: '@home.process.titleAccent', steps: '@home.process.steps' } },
            { type: 'posts', data: { eyebrow: '@home.blog.eyebrow', title: '@home.blog.title', cta: '@home.blog.cta' } },
            { type: 'cta', data: { title: '@home.cta.title', titleAccent: '@home.cta.titleAccent', lead: '@home.cta.lead', button: '@home.cta.primary' } },
        ],
    },
};
