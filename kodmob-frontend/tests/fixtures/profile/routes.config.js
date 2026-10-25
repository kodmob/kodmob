/**
 * TEST PROFİLİ (birim testleri; tests/profile-hook.mjs): kodmob-core'nin test sitesiyle (tests/fixtures/site) aynı
 * rota tablosu. Çatının testleri etkin profile (kodmob-frontend/profile.js) bağlı değildir; portföy sitesi örneği (projeler, hizmetler,
 * referanslar). Sitenin gerçek profili kodmob-frontend/src/profiles/<ad>/'dadır.
 */
const page = (name) => ({ template: `pages/${name}/index.njk`, script: `pages/${name}/main.js` });

export const ROUTES = [
    { name: 'home', paths: { tr: '/', en: '/', de: '/' }, ...page('home'), needs: ['projects', 'posts', 'references'], titleKey: 'home.meta.title', descriptionKey: 'home.meta.description' },

    { name: 'projects', paths: { tr: '/projeler/', en: '/projects/', de: '/projekte/' }, ...page('projects'), needs: ['projects'], titleKey: 'projects.meta.title', descriptionKey: 'projects.meta.description' },
    { name: 'project', dynamic: 'project', fallback: 'projects', paths: { tr: '/projeler/:slug/', en: '/projects/:slug/', de: '/projekte/:slug/' }, ...page('project'), titleKey: 'nav.projects' },

    { name: 'services', paths: { tr: '/hizmetler/', en: '/services/', de: '/leistungen/' }, ...page('services'), needs: ['projects'], titleKey: 'services.meta.title', descriptionKey: 'services.meta.description' },
    { name: 'references', paths: { tr: '/referanslar/', en: '/references/', de: '/referenzen/' }, ...page('references'), needs: ['references', 'projects'], titleKey: 'references.meta.title', descriptionKey: 'references.meta.description' },

    { name: 'blog', paths: { tr: '/blog/', en: '/blog/', de: '/blog/' }, ...page('blog'), needs: ['posts'], titleKey: 'blog.meta.title', descriptionKey: 'blog.meta.description' },
    { name: 'post', dynamic: 'post', fallback: 'blog', paths: { tr: '/blog/:slug/', en: '/blog/:slug/', de: '/blog/:slug/' }, ...page('post'), titleKey: 'nav.blog' },

    { name: 'about', paths: { tr: '/hakkimizda/', en: '/about/', de: '/ueber-uns/' }, ...page('about'), titleKey: 'about.meta.title', descriptionKey: 'about.meta.description' },
    { name: 'contact', paths: { tr: '/iletisim/', en: '/contact/', de: '/kontakt/' }, ...page('contact'), titleKey: 'contact.meta.title', descriptionKey: 'contact.meta.description' },
    { name: 'estimate', module: 'estimate', paths: { tr: '/teklif-hesapla/', en: '/estimate/', de: '/kostenrechner/' }, ...page('estimate'), needs: ['estimate'], titleKey: 'estimate.meta.title', descriptionKey: 'estimate.meta.description' },

    // Yasal metinler. KVKK aydınlatma metni yalnızca Türkçe; EN/DE gizlilik metni GDPR'a göre yazılır.

    { name: 'notFound', paths: { tr: '/404.html', en: '/404.html', de: '/404.html' }, ...page('404'), titleKey: 'notFound.title', sitemap: false, noindex: true },

    // Bileşen vitrini — yalnızca geliştirme ve test derlemesinde (npm run dev / build:dev).
    { name: 'ui', paths: { tr: '/_ui/' }, prefix: false, ...page('_ui'), needs: ['projects', 'posts', 'references'], titleKey: 'ui.title', dev: true, sitemap: false, noindex: true },
];
