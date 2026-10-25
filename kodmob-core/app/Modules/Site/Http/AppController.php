<?php

namespace App\Modules\Site\Http;

use App\Core\Content\Locales;
use App\Core\Http\Controller;
use App\Core\Support\SiteManifest;
use App\Modules\Site\ProjectIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web uygulaması (PWA) için ziyaretçiye açık iki adres:
 *
 *   GET /manifest.webmanifest?app=panel   sitenin ya da panelin bildirimi (ad, renk, ikonlar ayarlardan)
 *   GET /icon/{boy}.png                   proje ikonu (32, 180, 192, 512, 512-maskable); ikon yoksa favicon.svg'ye
 */
final class AppController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        $lang = Locales::default();
        $strings = (array) (SiteManifest::data()['strings'][$lang] ?? []);
        $body = ProjectIcon::manifest($request->query('app') === 'panel' ? 'panel' : 'site', $strings, $lang);

        return response()->json($body, 200, [
            'Content-Type' => 'application/manifest+json; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function icon(string $size): Response
    {
        $maskable = $size === '512-maskable';
        $file = preg_match('/^\d+$/', $size) || $maskable ? ProjectIcon::png($maskable ? 512 : (int) $size, $maskable) : null;
        if ($file === null) {
            return redirect('/assets/favicon/favicon.svg', 302)->header('Cache-Control', 'public, max-age=3600');
        }

        return response()->file($file, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
