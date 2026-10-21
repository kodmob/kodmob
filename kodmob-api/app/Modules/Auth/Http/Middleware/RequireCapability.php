<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Core\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yetki denetimi (oturumdan sonra): `->middleware('cap:km_manage_users')`.
 * Birden çok yetki `|` ile verilir, herhangi biri yeter: `cap:km_view_briefs|km_manage_briefs`.
 */
final class RequireCapability
{
    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();
        if (! $user) {
            throw new DomainException('km_unauthenticated', 'Oturumunuz kapanmış. Lütfen yeniden giriş yapın.', 401);
        }
        $wanted = implode('|', $capabilities);
        if (! $user->can($wanted)) {
            throw new DomainException('km_forbidden', 'Bu işlem için yetkiniz yok.', 403, ['capability' => $wanted]);
        }

        return $next($request);
    }
}
