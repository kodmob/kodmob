<?php

/*
 * Projenin kendi kayıtları. Bu dosya projeye aittir: çatı güncellemesi (kodmob:upgrade) dokunmaz. Projenin kodu
 * app/Project/ altında (ad alanı App\Project), testleri tests/Project/ altında.
 */
return [
    // Panelden yönetilen içerik türleri (App\Core\Content\Types). Yenisi: php artisan kodmob:module.
    // Sitenin profili (site.config.js `profile.contentTypes`) hangilerinin açık olduğunu söyler.
    // Sıra: yedekten geri yüklemede bu sırayla yazılır (başka türe bağlanan alan, bağlandığından sonra).
    'content_types' => [
        // kodmob:module
    ],

    // Projenin modülleri (App\Core\Module alt sınıfları): rotalar, ayarlar, olaylar, arama, sağlık kartı.
    // Her zaman açıktır (isteğe bağlı çatı modülleri gibi profilden kapatılmaz).
    'modules' => [
    ],
];
