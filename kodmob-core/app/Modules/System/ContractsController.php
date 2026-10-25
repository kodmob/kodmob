<?php

namespace App\Modules\System;

use App\Core\Contracts\Contracts;
use App\Core\Contracts\OpenApi;
use App\Core\Contracts\RequestSchemas;
use App\Core\Http\Controller;

/**
 * Panel: API'nin makine okunur belgesi (OpenAPI 3.1; `cap:km_manage_settings`). Herkese açık değil: uçların haritası
 * yalnızca yetkili yöneticiye. Depodaki kopyası docs/contracts/openapi.json (php artisan kodmob:contracts).
 *
 *   GET /system/openapi
 */
final class ContractsController extends Controller
{
    public function openapi(): array
    {
        return OpenApi::build(Contracts::build(), RequestSchemas::bodies());
    }
}
