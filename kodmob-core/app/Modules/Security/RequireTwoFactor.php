<?php

namespace App\Modules\Security;

use App\Core\Exceptions\DomainException;
use App\Core\Settings\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\TwoFactor;
use Closure;
use Illuminate\Http\Request;

/**
 * Zorunlu iki adımlı doğrulama (Güvenlik › Ayarlar › `security.require_2fa`: off | admins | all): kapsamdaki kişi
 * doğrulamayı açana kadar panelde yalnızca oturum, Profilim ve iki adımlı doğrulama adreslerini kullanabilir; diğerleri
 * 403 `km_two_factor_required` döner, panel kişiyi Profilim'e götürür. Kendi oturumunu kilitlememesi için ayarı
 * değiştiren yöneticiye de uygulanır ama Profilim her zaman açıktır.
 */
final class RequireTwoFactor
{
    /** Kişi kapsamda mı ve doğrulaması kapalı mı. */
    public static function pending(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $mode = (string) (SettingsService::instance()->get(SecurityModule::REQUIRE_2FA) ?: 'off');
        $applies = $mode === 'all' || ($mode === 'admins' && $user->isAdmin());

        return $applies && ! TwoFactor::enabled($user);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (self::pending($request->user()) && ! $this->allowed($request)) {
            throw new DomainException('km_two_factor_required', 'Bu hesap için iki adımlı doğrulama zorunlu. Profilim\'den açın, sonra devam edin.', 403);
        }

        return $next($request);
    }

    private function allowed(Request $request): bool
    {
        $path = preg_replace('#^api/#', '', trim($request->path(), '/'));

        return (bool) preg_match('#^v1/(me(/.*)?|panel-lang/.*|auth/.*|notifications.*|push.*|live|changes.*)$#', (string) $path);
    }
}
