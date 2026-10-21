<?php

namespace KodmobTools\Merhaba;

use App\Core\Http\Controller;
use App\Core\Settings\SettingsService;

/** Merhaba aracının uçları (php/routes.php). */
final class MerhabaController extends Controller
{
    public function show(): array
    {
        return ['message' => (string) SettingsService::instance()->get('merhaba.greeting')];
    }
}
