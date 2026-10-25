<?php

namespace App\Modules\Auth\Models;

use App\Core\Security\Capability;
use App\Core\Security\Roles;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Panel kullanıcısı. Yetkileri rolünden gelir (Core\Security\Roles); pasif
 * kullanıcının hiçbir yetkisi yoktur, giriş yapamaz.
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $display_name
 * @property string $phone
 * @property string $role
 * @property string $status
 */
class User extends Authenticatable
{
    public const ADMIN = Roles::ADMIN;

    public const ACTIVE = 'active';

    public const PASSIVE = 'passive';

    protected $fillable = ['username', 'email', 'display_name', 'phone', 'password', 'role', 'status', 'deactivated_at', 'password_changed_at', 'created_by', 'last_login_at'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery', 'preferences'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password_changed_at' => 'datetime',
            // İki adımlı doğrulama (Services\TwoFactor): anahtar şifreli, yedek kodların özetleri.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return ($this->status ?: self::ACTIVE) === self::ACTIVE;
    }

    public function isAdmin(): bool
    {
        return $this->isActive() && $this->role === Roles::ADMIN;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return $this->isActive() ? Roles::capsOf((string) $this->role) : [];
    }

    /** Yetki; `'km_a|km_b'` biçiminde verilirse herhangi biri yeter. */
    public function can($abilities, $arguments = []): bool
    {
        if (! is_string($abilities)) {
            return parent::can($abilities, $arguments);
        }
        $caps = $this->capabilities();
        foreach (explode('|', $abilities) as $cap) {
            if (in_array(trim($cap), $caps, true)) {
                return true;
            }
        }

        return false;
    }

    public function canAccessPanel(): bool
    {
        return $this->can(Capability::ACCESS_PANEL);
    }
}
