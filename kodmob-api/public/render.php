<?php

/*
 * Ziyaretçi sayfalarının giriş noktası. Site kökündeki .htaccess kök adresi (/),
 * /sitemap.xml, /robots.txt, /llms.txt ve dosyası olmayan bütün adresleri buraya
 * yönlendirir (varsayılan dil öneksiz: /projeler/x/, diğerleri /en/projects/x/).
 *
 * 0. SEO (Modules\Seo, storage/framework/seo/config.json; SEO kapalıysa atlanır):
 *    robots.txt, IndexNow anahtar dosyası, yönlendirmeler (301/302/307/410).
 * 1. Adres km-manifest.json'daki rotalarla eşlenir (Laravel açılmadan; Site\Render\Router).
 *    Eski adresler 301, yayında olmayan dil 302 ile yönlenir; hangi dilin yayında olduğu
 *    dil kaydının derlenmiş kopyasındadır (storage/framework/i18n/config.json).
 * 2. Önbellekte güncel kopya varsa doğrudan gönderilir (Laravel hiç açılmaz).
 * 3. Yoksa Laravel açılır; sayfa kabuğu (dist) içerikle tamamlanır,
 *    önbelleğe yazılır ve gönderilir (Site\Render\SitePage).
 * 4. 404 yanıtı SEO > 404 izleme açıksa günlüğe eklenir (panel toplar).
 *
 * Önbellek içerik/ayar değişince silinir (Core\Content\PageCache); ayrıca
 * kabuktan ya da manifest'ten eskiyse veya 24 saati geçtiyse kullanılmaz.
 *
 * Yerleşim: public_html/api/render.php (site kökü bir üst klasör).
 */

use App\Core\Sites\SiteContext;
use App\Modules\Site\Blocks\PreviewToken;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\SitePage;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('KODMOB_RENDER', true);

$core = null;
foreach ([__DIR__.'/..', __DIR__.'/../../kodmob-core', __DIR__.'/_core'] as $candidate) {
    if (is_file($candidate.'/bootstrap/app.php') && is_file($candidate.'/vendor/autoload.php')) {
        $core = realpath($candidate);
        break;
    }
}
if ($core === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Sunucu kurulumu eksik: kodmob-core klasörü bulunamadı.');
}

// Depolama: kodmob-core/storage; LARAVEL_STORAGE_PATH verilmişse orası (Laravel ile aynı kural;
// uçtan uca test ortamı kendi depolamasıyla çalışır, yerel sitenin önbelleğine dokunmaz).
// Çok site: alan adından site seçilir; alt sitenin depolaması storage/sites/<anahtar>, derlenmiş
// sayfaları public_html/_sites/<anahtar> (Core\Sites\SiteContext).
require_once $core.'/app/Core/Sites/SiteContext.php';
$siteContext = SiteContext::boot($core);
$storage = $siteContext['storage'];
$root = SiteContext::root(dirname(__DIR__));
$cacheDir = $storage.'/framework/pages';
$maxAge = 86400;

require_once $core.'/app/Modules/Site/Render/Router.php';

$router = Router::load($root, $storage.'/framework/i18n/config.json');
if (! $router) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Site yayına hazırlanıyor: km-manifest.json bulunamadı.');
}
$manifest = $router->manifest();

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));

// Sayfa düzenleyicinin önizlemesi (panel; Site\Blocks\PreviewToken): geçerli anahtar önbelleği atlar, taslak
// bloklar çizilir ve sayfa panelin çerçevesinde (aynı köken) açılabilir. Geçersiz anahtar yok sayılır.
require_once $core.'/app/Modules/Site/Blocks/PreviewToken.php';
$previewToken = (string) ($_GET[PreviewToken::PARAM] ?? '');
$preview = $previewToken !== '' && PreviewToken::user($storage, $previewToken) !== null;

// ------------------------------------------------------------ SEO (Laravel açılmadan)
$seoDir = $storage.'/framework/seo';
$seo = is_file($seoDir.'/config.json') ? (json_decode((string) file_get_contents($seoDir.'/config.json'), true) ?: []) : [];
$seoOn = ! empty($seo['enabled']);
$site = rtrim((string) ($manifest['siteUrl'] ?? ''), '/');

// Yapay zekâ botlarının ziyaret kaydı (SEO > Yapay zekâ; Seo\Services\AiVisibility içeri alır). Yanıt kodu
// çıkışta belli olur; önbellekten sunulan sayfa da yazılır. Yalnızca bot eşleşince dosyaya dokunulur.
if ($seoOn && ! empty($seo['aiBots']) && preg_match((string) $seo['aiBots'], (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $aiBot)) {
    register_shutdown_function(static function () use ($seoDir, $path, $aiBot): void {
        $file = $seoDir.'/ai.log';
        if (is_file($file) && filesize($file) > 5_000_000) {
            return;
        }
        $line = json_encode(['b' => $aiBot[1], 'p' => mb_substr($path, 0, 255), 'c' => (int) (http_response_code() ?: 200), 't' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
    });
}

if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    if (empty($manifest['indexable'])) {
        exit("User-agent: *\nDisallow: /\n");
    }
    exit($seoOn && isset($seo['robots']) && is_string($seo['robots'])
        ? $seo['robots']
        : "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /panel/\n".($site !== '' ? "\nSitemap: {$site}/sitemap.xml\n" : ''));
}
if ($seoOn && ! empty($seo['indexnow']) && $path === '/'.$seo['indexnow'].'.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    exit((string) $seo['indexnow']);
}

$log404 = static function () use ($seoOn, $seo, $seoDir, $path): void {
    if (! $seoOn || empty($seo['log404']) || preg_match('#^/(api|assets|media)/|\.(php\d?|asp|aspx|env|git|sql|bak|ini|log)$|/wp-|/\.#i', $path)) {
        return;
    }
    $file = $seoDir.'/404.log';
    if (is_file($file) && filesize($file) > 5_000_000) {
        return;
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $line = json_encode([
        'p' => mb_substr($path, 0, 500),
        'r' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
        'b' => $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|curl|wget|python|scan|preview/i', $ua),
        't' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
};

// Yönlendirmeler: önce kesin eşleşme, sonra düzenli ifadeler (sırayla).
if ($seoOn && ! empty($seo['redirects'])) {
    $key = rtrim($path, '/') ?: '/';
    $rule = null;
    $target = '';
    if (isset($seo['redirects']['exact'][$key])) {
        [$id, $target, $code] = $seo['redirects']['exact'][$key];
        $rule = [$id, $code];
    } else {
        foreach ((array) ($seo['redirects']['regex'] ?? []) as [$id, $pattern, $replacement, $code]) {
            if (@preg_match($pattern, $path)) {
                $target = (string) preg_replace($pattern, $replacement, $path);
                $rule = [$id, $code];
                break;
            }
        }
    }
    if ($rule !== null && ($rule[1] === 410 || ($target !== '' && $target !== $path))) {
        @file_put_contents($seoDir.'/hits.log', $rule[0]."\t".date('c')."\n", FILE_APPEND | LOCK_EX);
        if ($rule[1] === 410) {
            http_response_code(410);
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Robots-Tag: noindex');
            exit("410 Gone\n");
        }
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        header('Location: '.$target.($query !== '' && ! str_contains($target, '?') ? '?'.$query : ''), true, (int) $rule[1]);
        exit;
    }
}
if (($path === '/llms.txt' || $path === '/llms-full.txt') && (! $seoOn || empty($seo['llms']))) {
    $log404();
    http_response_code(404);
    exit;
}

$match = $router->match($path);

if ($match['kind'] === 'redirect') {
    $query = (string) parse_url($uri, PHP_URL_QUERY);
    header('Location: '.$match['location'].($query !== '' ? '?'.$query : ''), true, (int) ($match['code'] ?? 301));
    exit;
}
if ($match['kind'] === 'none') {
    $log404();
    http_response_code(404);
    exit;
}

// ------------------------------------------------------------ başlıklar
// Site kökündeki .htaccess ile aynı güvenlik başlıkları (iç yönlendirmede
// .htaccess'in <If> koşulu /api/ yolunu görebilir; sayfa kendi başlığını taşır).
$isXml = $match['kind'] !== 'page';
$extension = match ($match['kind']) {
    'page' => 'html',
    'llms' => 'txt',
    default => 'xml',
};
header('Content-Type: '.match ($match['kind']) {
    'page' => 'text/html',
    'sitemap' => 'application/xml',
    'llms' => 'text/plain',
    default => 'application/rss+xml',
}.'; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval' 'inline-speculation-rules'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; media-src 'self' blob:; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors ".($preview ? "'self'" : "'none'"));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: '.($preview ? 'SAMEORIGIN' : 'DENY'));
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cache-Control: no-cache');
if (empty($manifest['indexable'])) {
    header('X-Robots-Tag: noindex, nofollow');
} elseif ($match['kind'] === 'llms') {
    header('X-Robots-Tag: noindex');
}

// ------------------------------------------------------------ önbellek
$fresh = static function (string $file) use ($match, $router, $maxAge): bool {
    if (! is_file($file)) {
        return false;
    }
    $time = filemtime($file);
    if ($time < time() - $maxAge || $time < filemtime($router->manifestFile())) {
        return false;
    }

    return $match['kind'] !== 'page' || $time >= filemtime($match['shell']);
};
foreach ($preview ? [] : [200, 404] as $code) {
    $file = $cacheDir.'/'.md5($path).'-'.$code.'.'.$extension;
    if ($fresh($file)) {
        if ($code === 404) {
            $log404();
        }
        // Doğrulayıcılar: tarayıcı aynı sayfayı yeniden isterse (yenileme, tekrar ziyaret) değişmemişse gövdesiz 304.
        // Sunucunun sıkıştırması ETag'e ek koyabilir (-gzip, -br): karşılaştırmada atılır.
        $mtime = (int) filemtime($file);
        $etag = sprintf('W/"%x-%x"', $mtime, (int) filesize($file));
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
        header('ETag: '.$etag);
        $ifNone = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        $since = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
        if ($code === 200 && ($ifNone !== '' ? in_array($etag, array_map(static fn ($t) => (string) preg_replace('/-(gzip|br)"$/', '"', trim($t)), explode(',', $ifNone)), true) : $since >= $mtime)) {
            http_response_code(304);
            header('X-KM-Cache: HIT');
            exit;
        }
        http_response_code($code);
        header('X-KM-Cache: HIT');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            readfile($file);
        }
        exit;
    }
}

// ------------------------------------------------------------ üretim
require $core.'/vendor/autoload.php';

/** @var Application $app */
$app = require $core.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$request = Request::capture();
$app->instance('request', $request);
$app->make(Kernel::class)->bootstrap();

// Rota tablosu Laravel'siz yüklenmişti; site kimliği (panelde site adı, logo, renkler: Site\SiteProfile)
// Laravel açılınca bağlanır, sayfa onunla üretilsin.
$router = Router::load($root, $storage.'/framework/i18n/config.json') ?? $router;
[$status, $body, $headers] = (new SitePage($router))->render($match, $path, $preview ? $previewToken : '') + [2 => []];
if ($status === 404) {
    $log404();
}

http_response_code($status);
// Adresi değişen kayıt: Location (301); silinen kayıt 410 (404 sayfasıyla).
foreach ($headers as $name => $value) {
    header($name.': '.$value);
}
if ($status === 410) {
    header('X-Robots-Tag: noindex');
}
header('X-KM-Cache: MISS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo $body;
}
