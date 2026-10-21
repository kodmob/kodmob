<?php

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Security\SecurityModule;

/**
 * Yeni şifre kuralları: en az `security.password_min_length` karakter, harf ve
 * rakam içerir, kullanıcı adını içermez, çok bilinen şifrelerden değildir.
 */
final class PasswordPolicy
{
    private const COMMON = ['password', 'parola', 'sifre', 'şifre', 'qwerty', 'kodmob', 'admin', 'yonetici', 'letmein', 'welcome', '123456', 'abc123'];

    public static function minLength(): int
    {
        return SecurityModule::int(SecurityModule::PASSWORD_MIN, 10);
    }

    public static function assert(string $password, string $username = '', string $field = 'password'): void
    {
        $min = self::minLength();
        if (mb_strlen($password) < $min) {
            throw ValidationException::field($field, "Şifre en az {$min} karakter olmalı.");
        }
        if (mb_strlen($password) > 200) {
            throw ValidationException::field($field, 'Şifre en çok 200 karakter olabilir.');
        }
        if (! preg_match('/\pL/u', $password) || ! preg_match('/\d/', $password)) {
            throw ValidationException::field($field, 'Şifrede en az bir harf ve bir rakam olmalı.');
        }
        $lower = mb_strtolower($password);
        if ($username !== '' && mb_strlen($username) >= 3 && str_contains($lower, mb_strtolower($username))) {
            throw ValidationException::field($field, 'Şifre kullanıcı adını içermemeli.');
        }
        foreach (self::COMMON as $common) {
            if (preg_replace('/[\d\W_]+/u', '', $lower) === $common) {
                throw ValidationException::field($field, 'Bu şifre çok yaygın; daha tahmin edilmesi zor bir şifre seçin.');
            }
        }
    }
}
