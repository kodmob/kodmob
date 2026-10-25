<?php

/*
 * Kodmob'a özel ayarlar. Yollar boşsa canlıdaki yerleşimden çıkarılır:
 *
 *   public_html/              site kökü (ön yüz: tr/, en/, de/, panel/, km-manifest.json)
 *   public_html/api/          Laravel giriş noktası (index.php, render.php) = public_path()
 *   public_html/media/        yüklenen görseller
 *   kodmob-core/              bu uygulama (web kökünün dışında)
 */
return [
    // Uygulama sürümü (kodmob-frontend/package.json ile aynı olmalı; paketleme betiği denetler).
    // 1.x: WordPress (tv-core + eklentiler) · 2.0: Laravel, çok kullanıcılı panel, SEO.
    'version' => '3.0.0',

    // Çok site: bu isteğin sitesi (`main` ya da storage/sites.json'daki alt site anahtarı). Elle
    // yazılmaz; alan adından ya da KODMOB_SITE'tan seçilir (App\Core\Sites).
    'site' => 'main',

    // Ön yüz derlemesinin bulunduğu klasör (km-manifest.json burada). Boşsa public_path()'in bir üstü.
    'site_root' => env('SITE_ROOT'),

    // Sistem Sağlığı › Bildir'in varsayılan alıcısı (panelde Ayarlar › Sistem sağlığı'ndan değişir).
    // Geliştirici müşteri kurulumunda kendi teknik servis adresini buradan verebilir.
    'health_report_to' => env('HEALTH_REPORT_TO', ''),

    // Görsellerin yazıldığı klasör ve tarayıcıdaki adresi. Boşsa <site kökü>/media.
    'media_root' => env('MEDIA_ROOT'),
    'media_url' => env('MEDIA_URL', '/media'),

    // İsteğe bağlı modüller (App\Core\Modules): blog, briefs (talepler), estimate (teklif hesaplayıcı),
    // jarvis, domains (domain arama), sms, sites (çok site). Listede olmayan modül panelde, aramada,
    // yetkilerde ve rotalarda yoktur; çekirdek modüller her zaman açıktır. Liste normalde sitenin
    // profilindedir (kodmob-frontend/src/profiles/<ad>/site.config.js `profile.modules`); burada ya da ortamda
    // (KODMOB_MODULES=blog,briefs) verilirse onun yerine geçer. İkisi de yoksa (eski derleme) 2.5 öncesinin modülleri açık (Mağaza değil).
    // Projenin kendi modülleri ve içerik türleri config/project.php'de.
    'modules' => env('KODMOB_MODULES') !== null
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('KODMOB_MODULES')))))
        : null,

    // Araç paketlerinin klasörü (Core\Extensions\Extensions; boşsa kodmob-core/extensions). Klasördeki paket
    // kuruludur; Araç Pazarı'nda proje başına kapanır.
    'extensions_path' => env('KODMOB_EXTENSIONS'),

    // SSH'sız kurulum ucu (/api/v1/setup) için anahtar; kurulumdan sonra boşaltın.
    'setup_token' => env('SETUP_TOKEN', ''),

    // Ziyaretçi sayfası önbelleğinin en uzun ömrü (saniye).
    'page_cache_max_age' => (int) env('PAGE_CACHE_MAX_AGE', 86400),

    // Vekil sunucu (Cloudflare vb.) arkasında gerçek IP için: "*" ya da virgüllü IP listesi.
    'trusted_proxies' => env('TRUSTED_PROXIES'),
];
