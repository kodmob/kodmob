<?php

/*
 * API giriş noktası. Canlıda bu klasör public_html/api/'dir ve Laravel
 * çekirdeği (kodmob-core) web kökünün DIŞINDA durur; dosya onu kendisi bulur:
 *
 *   1) geliştirme: depo düzeni (kodmob-api/public → kodmob-api)
 *   2) public_html'in yanında: /home/kullanici/domains/…/kodmob-core   (önerilen)
 *   3) bu klasörün içinde (_core): hosting kök dışına izin vermiyorsa;
 *      .htaccess bütün erişimi kapatır.
 */

use App\Core\Sites\SiteContext;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$core = null;
foreach ([__DIR__.'/..', __DIR__.'/../../kodmob-core', __DIR__.'/_core'] as $candidate) {
    if (is_file($candidate.'/bootstrap/app.php') && is_file($candidate.'/vendor/autoload.php')) {
        $core = $candidate;
        break;
    }
}
if ($core === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 'km_core_missing', 'message' => 'Sunucu kurulumu eksik: kodmob-core klasörü bulunamadı.', 'details' => new stdClass]);
    exit;
}

// Çok site: alan adından site seçilir; alt sitenin depolaması storage/sites/<anahtar> (bakım modu da).
require_once $core.'/app/Core/Sites/SiteContext.php';
$storage = SiteContext::boot($core)['storage'];
if (file_exists($maintenance = $storage.'/framework/maintenance.php')) {
    require $maintenance;
}

require $core.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $core.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
