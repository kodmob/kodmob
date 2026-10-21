<?php

namespace App\Modules\System;

use App\Core\Http\Controller;
use App\Core\Search\SearchRegistry;
use Illuminate\Http\Request;

/**
 * Panel araması (üst çubuk): GET /search?q= → yetkili olunan kayıt grupları (Core\Search).
 * Sayfalar, sekmeler ve ayarlar panelde, sunucuya gitmeden aranır.
 */
final class SearchController extends Controller
{
    public function index(Request $request): array
    {
        return SearchRegistry::query($request->user(), (string) $request->query('q', ''), max(1, min(10, (int) $request->query('limit', 5))));
    }
}
