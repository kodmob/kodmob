<?php

namespace App\Core\Http\Middleware;

use App\Core\Exceptions\DomainException;
use App\Core\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kapalı modülün rotası yokmuş gibi davranır (404): `->middleware('module:briefs')`.
 * Modüller config/kodmob.php `modules` listesindedir (Core\Modules).
 */
final class RequireModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! Modules::enabled($module)) {
            throw new DomainException('km_not_found', 'Bu özellik bu sitede kapalı.', 404, ['module' => $module]);
        }

        return $next($request);
    }
}
