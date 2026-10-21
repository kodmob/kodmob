<?php

namespace App\Modules\Users;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\DomainException;
use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Security\Roles;
use App\Core\Support\Clock;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\PasswordPolicy;
use App\Modules\Auth\Services\Sessions;

/**
 * Ortak çoklu kullanıcı yönetimi (Kullanıcılar sayfası ve Profilim).
 *
 * Kurallar:
 * - Yönetici rolündeki hesabı yalnızca Yönetici ekler, düzenler, pasife alır.
 * - Kimse kendini pasife alamaz, kendi rolünü değiştiremez.
 * - Son aktif Yönetici pasife alınamaz ve rolü değiştirilemez (panel sahipsiz kalmasın).
 * - Kullanıcı silinmez, pasife alınır: İşlem Geçmişi'ndeki adı korunur.
 * - Şifre değişince (ya da hesap pasife alınınca) açık oturumları kapanır.
 */
final class UserService
{
    private const FIELDS = ['display_name' => 'Ad', 'email' => 'E-posta', 'phone' => 'Telefon', 'role' => 'Rol', 'username' => 'Kullanıcı adı'];

    public function create(array $input, User $actor): User
    {
        $values = $this->validate($input, null);
        $this->assertCanAssign($actor, $values['role']);
        $password = (string) ($input['password'] ?? '');
        PasswordPolicy::assert($password, $values['username']);

        return User::query()->create($values + [
            'password' => $password,
            'status' => User::ACTIVE,
            'password_changed_at' => Clock::now(),
            'created_by' => $actor->id,
        ]);
    }

    /** @return array<string,array{label:string,from:string,to:string}> değişen alanlar */
    public function update(User $user, array $input, User $actor): array
    {
        $this->assertCanManage($actor, $user);
        $values = $this->validate($input + $user->only(['username', 'email', 'display_name', 'phone', 'role']), $user);
        if ($values['role'] !== $user->role) {
            if ($user->id === $actor->id) {
                throw new ConflictException('km_user_self', 'Kendi rolünüzü değiştiremezsiniz.');
            }
            $this->assertCanAssign($actor, $values['role']);
            $this->assertNotLastAdmin($user);
        }

        $changes = [];
        foreach (self::FIELDS as $key => $label) {
            $from = (string) $user->{$key};
            if ($from !== $values[$key]) {
                $changes[$key] = $key === 'role'
                    ? ['label' => $label, 'from' => Roles::get($from)['label'] ?? $from, 'to' => Roles::get($values[$key])['label'] ?? $values[$key]]
                    : ['label' => $label, 'from' => $from, 'to' => $values[$key]];
            }
        }
        $user->fill($values);

        $password = (string) ($input['password'] ?? '');
        if ($password !== '') {
            PasswordPolicy::assert($password, $values['username']);
            $user->password = $password;
            $user->password_changed_at = Clock::now();
            $changes['password'] = ['label' => 'Şifre', 'from' => '', 'to' => 'yenilendi'];
        }
        $user->save();
        if ($password !== '' && $user->id !== $actor->id) {
            Sessions::endAll((int) $user->id);
        }

        return $changes;
    }

    public function deactivate(User $user, User $actor): int
    {
        $this->assertCanManage($actor, $user);
        if ($user->id === $actor->id) {
            throw new ConflictException('km_user_self', 'Kendi hesabınızı pasife alamazsınız.');
        }
        $this->assertNotLastAdmin($user);
        $user->fill(['status' => User::PASSIVE, 'deactivated_at' => Clock::now()])->save();

        return Sessions::endAll((int) $user->id);
    }

    public function activate(User $user, User $actor): void
    {
        $this->assertCanManage($actor, $user);
        $user->fill(['status' => User::ACTIVE, 'deactivated_at' => null])->save();
    }

    public function assertCanManage(User $actor, User $user): void
    {
        if ($user->role === Roles::ADMIN && ! $actor->isAdmin()) {
            throw new DomainException('km_forbidden', 'Yönetici hesaplarını yalnızca Yönetici düzenleyebilir.', 403);
        }
    }

    private function assertCanAssign(User $actor, string $role): void
    {
        if ($role === Roles::ADMIN && ! $actor->isAdmin()) {
            throw new DomainException('km_forbidden', 'Yönetici rolünü yalnızca Yönetici verebilir.', 403);
        }
    }

    private function assertNotLastAdmin(User $user): void
    {
        if ($user->role !== Roles::ADMIN || ! $user->isActive()) {
            return;
        }
        $others = User::query()->where('role', Roles::ADMIN)->where('status', User::ACTIVE)->where('id', '<>', $user->id)->count();
        if ($others === 0) {
            throw new ConflictException('km_last_admin', 'Bu son aktif Yönetici. Önce başka bir kullanıcıya Yönetici rolü verin.');
        }
    }

    /**
     * İstek şeması (Core\Http\Schema; OpenAPI PUT /users/{id}): biçim burada, benzersizlik ve rol kuralları
     * serviste.
     */
    public static function schema(): Schema
    {
        return Schema::object([
            'username' => Field::string('Kullanıcı adı 3-40 karakter; küçük harf, rakam, nokta, tire ya da alt çizgi olmalı.')->lower()->pattern('/^[a-z0-9._-]{3,40}$/'),
            'email' => Field::email('Geçerli bir e-posta adresi yazın.'),
            'display_name' => Field::string('Ad en az 2 karakter olmalı.')->cut(120)->min(2),
            'phone' => Field::string()->optional()->strip('/[^\d+() \-]/')->cut(30),
            'role' => Field::enum(static fn () => array_keys(Roles::all()), 'Rol seçin.'),
        ]);
    }

    /** Oluşturmada şifre de istenir (OpenAPI POST /users; denetimi PasswordPolicy). */
    public static function createSchema(): Schema
    {
        return self::schema()->with([
            'password' => Field::string()->describe('Şifre politikası: Güvenlik ayarlarındaki en az uzunluk, yaygın şifre ve kullanıcı adı olamaz.'),
        ]);
    }

    /** @return array{username:string,email:string,display_name:string,phone:string,role:string} */
    private function validate(array $input, ?User $user): array
    {
        [$values, $errors] = self::schema()->check($input);
        $taken = static fn (string $column, string $value) => User::query()->where($column, $value)->when($user, fn ($q) => $q->where('id', '<>', $user->id))->exists();
        if (! isset($errors['username']) && $taken('username', $values['username'])) {
            $errors['username'] = 'Bu kullanıcı adı kullanılıyor.';
        }
        if (! isset($errors['email']) && $taken('email', $values['email'])) {
            $errors['email'] = 'Bu e-posta başka bir kullanıcıda kayıtlı.';
        }
        Schema::fail($errors);

        return $values;
    }
}
