<?php

namespace App\Modules\Live\Http;

use App\Core\Http\Controller;
use App\Modules\Live\Changes;
use Illuminate\Http\Request;

/** Değişiklik akışı (Modules\Live\Changes): panel tablolarının canlı güncelleme şeridi. */
final class ChangesController extends Controller
{
    public function cursor(): array
    {
        return ['cursor' => Changes::cursor()];
    }

    public function index(Request $request): array
    {
        $types = array_filter(array_map('trim', explode(',', (string) $request->query('types', ''))));

        return Changes::since(max(0, (int) $request->query('since', 0)), $types, $request->user());
    }
}
