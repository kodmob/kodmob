/**
 * TEST PROFİLİ (birim testleri; tests/profile-hook.mjs): kodmob-api'nin test sitesiyle (tests/fixtures/site) aynı
 * panel içerik türleri. Çatının testleri etkin profile (tv/profile.js) bağlı değildir; portföy sitesi örneği (projeler, hizmetler,
 * referanslar). Sitenin gerçek profili tv/src/profiles/<ad>/'dadır.
 */
export const TYPES = {
    // Panelde "Portföy": "Proje" panelin çalışma alanıdır (proje seçici); sitedeki adresler /projeler/ aynı.
    projects: { label: 'Portföy', singular: 'Çalışma', icon: 'briefcase', orderable: true, newLabel: 'Yeni çalışma', record: 'project', can: 'km_manage_projects', slug: 'projeler', featured: true,
        guide: 'Sitedeki projeler (portföy): başlık, görseller, galeri, açıklama, öne çıkan. Listede tutamaktan sürükleyerek sıralarsınız; sıra sitedeki sıradır.' },
    services: { label: 'Hizmetler', singular: 'Hizmet', icon: 'layers', orderable: true, newLabel: 'Yeni hizmet', record: 'service', can: 'km_manage_services', slug: 'hizmetler', site: { route: 'services', anchor: 'key' },
        guide: 'Sitenin Hizmetler bölümü ve ana sayfadaki hizmet kartları. Sürükleyerek sıralanır; taslaktaki hizmet sitede görünmez.' },
    references: { label: 'Referanslar', singular: 'Referans', icon: 'users', orderable: true, newLabel: 'Yeni referans', record: 'reference', can: 'km_manage_references', slug: 'referanslar', site: { route: 'references' },
        guide: 'Müşteri logoları ve referanslar. Sürükleyerek sıralanır.' },
    // kodmob:module
};

/** Projenin özel ekranları (biçim: tv/src/profiles/starter/panel.js). kodmob.com'un yok. */
export const PAGES = [];
