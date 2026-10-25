<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Core\Exceptions\DomainException;
use App\Modules\Auth\Services\TokenService;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Authorization: Bearer <jwt>` doğrular; `$request->user()`, `Auth::id()` ve
 * `$request->attributes->get('jwt')` (iddialar) sonraki katmanlara açılır.
 */
final class AuthenticateJwt implements AuthenticatesRequests
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            throw new DomainException('km_unauthenticated', 'Oturumunuz kapanmış. Lütfen yeniden giriş yapın.', 401);
        }

        [$user, $claims] = $this->tokens->authenticate($token);
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('jwt', $claims);

        return $next($request);
    }
}
