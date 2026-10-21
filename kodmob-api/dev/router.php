<?php

/*
 * Yerel geliştirme: PHP'nin yerleşik sunucusu için yönlendirici. Site kökündeki
 * ve api/ klasöründeki .htaccess kurallarının karşılığıdır (Apache gerekmez):
 *
 *   php -S 127.0.0.1:8090 -t <kök>/public_html kodmob-api/dev/router.php
 *
 * (tv: `npm run serve:local` bunu çalıştırır.)
 */

// Ayrı depolama (uçtan uca test ortamı, tv/e2e): süreç ortamındaki değer Laravel'in okuduğu yere.
if (($storage = getenv('LARAVEL_STORAGE_PATH')) !== false && $storage !== '') {
    $_SERVER['LARAVEL_STORAGE_PATH'] = $_ENV['LARAVEL_STORAGE_PATH'] = $storage;
}

$root = $_SERVER['DOCUMENT_ROOT'];
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));

// Sunucu havuzunun yoklaması (tv/scripts/php-pool.mjs): cevap veren süreç havuzun kendisi mi.
if ($path === '/km-pool-ping') {
    header('X-Km-Pool: '.(getenv('KM_POOL_TOKEN') ?: ''));
    http_response_code(204);

    return true;
}

$run = static function (string $file, string $scriptName) use ($root): bool {
    $_SERVER['SCRIPT_FILENAME'] = $root.$scriptName;
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $_SERVER['PHP_SELF'] = $scriptName;
    chdir(dirname($root.$scriptName));
    require $root.$scriptName;

    return true;
};

// /api/… : var olan dosya (render.php gibi) doğrudan, gerisi Laravel.
if (preg_match('#^/api(/|$)#', $path)) {
    if (str_starts_with($path, '/api/_core')) {
        http_response_code(403);

        return true;
    }
    $file = $root.$path;
    if ($path !== '/api/' && is_file($file)) {
        return str_ends_with($file, '.php') ? $run($file, $path) : false;
    }

    return $run($root.'/api/index.php', '/api/index.php');
}

// Görsel klasöründe betik yok.
if (preg_match('#^/media/.*\.(php\d?|phtml|phar)$#i', $path)) {
    http_response_code(403);

    return true;
}

// Panel tek sayfa: /panel/ altında dosyası olmayan, uzantısız her adres panel/index.html (.htaccess'teki kural).
if (str_starts_with($path, '/panel/') && ! preg_match('#\.[a-z0-9]+$#i', $path) && ! is_file($root.$path) && is_file($root.'/panel/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($root.'/panel/index.html');

    return true;
}
// Yalnızca render.php içindir: rota tablosu ve sayfa kabukları (alt sitelerinki _sites/<anahtar>/).
if (in_array($path, ['/km-manifest.json', '/km-blocks.json'], true) || preg_match('#^/_(pages|sites)(/|$)#', $path)) {
    http_response_code(403);

    return true;
}
// Gerçek klasörler (assets, media, panel, _ui) ve dosyalar doğrudan; panel ve _ui eğik çizgisiz gelirse tamamlanır.
$static = (bool) preg_match('#^/(assets|media|panel|_ui)(/|$)#', $path);
if ($static && is_dir($root.$path) && ! str_ends_with($path, '/')) {
    header('Location: '.$path.'/', true, 301);

    return true;
}
// Kök adres, site haritası, robots/llms, eski kabukların index.html'leri ve dosyası olmayan her adres
// (klasör olsa da: eski sürümden kalan tr/, en/…) render.php'ye. Varsayılan dil öneksizdir.
if ($path === '/'
    || in_array($path, ['/sitemap.xml', '/robots.txt', '/llms.txt', '/llms-full.txt'], true)
    || (! $static && str_ends_with($path, '/index.html'))
    || (! $static && ! is_file($root.$path))) {
    return $run($root.'/api/render.php', '/api/render.php');
}

return false;
