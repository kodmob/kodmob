<?php

namespace App\Modules\System;

use App\Core\Health\Health;
use App\Core\Http\Controller;
use App\Core\Settings\SettingsService;
use App\Core\Support\ErrorJournal;

/**
 * Sistem Sağlığı ekranı (km_view_health): kartlar (Core\Health), hata kodları (ErrorJournal) ve
 * "Bildir" (HealthReport). Salt okuma; yalnızca hata günlüğünü temizlemek ve rapor göndermek yazar.
 */
final class HealthController extends Controller
{
    public function show(): array
    {
        return Health::report() + ['report_to' => HealthReport::recipient() !== '', 'monthly' => SettingsService::instance()->bool(HealthReport::MONTHLY)];
    }

    public function errors(): array
    {
        return ['items' => ErrorJournal::all()];
    }

    public function clearErrors(): array
    {
        ErrorJournal::clear();

        return ['cleared' => true];
    }

    /** Alıcının adresi yanıtta gösterilmez. */
    public function report(): array
    {
        HealthReport::send();

        return ['sent' => true];
    }
}
