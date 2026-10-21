<?php

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\DomainException;
use App\Core\Sites\Sites;
use App\Core\Support\Clock;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Projeler arası tek oturumla geçiş (docs/done-panel-yapisi-raporu.md P3). Ana projenin yöneticisi proje seçiciden alt
 * projeye geçerken hedef projede yeniden giriş yapmaz:
 *
 * 1. Ana projede `issue()`: hedef projede **aynı e-postalı, etkin bir yönetici** varsa onun için tek kullanımlık
 *    anahtar üretilir; hedef projenin veritabanına özetiyle yazılır (`handoff_tokens`), 60 sn geçerli.
 * 2. Tarayıcı hedef projenin paneline `?gecis=<anahtar>` ile gider; panel anahtarı hemen `POST /auth/handoff` ile
 *    gönderir ve adresi temizler.
 * 3. Hedef projede `redeem()`: anahtar bir kez ve süresi içinde kullanılır; o yönetici için olağan oturum açılır.
 *
 * Kullanıcılar proje başına ayrıdır; eşleşme e-postayla ve yalnızca yöneticiler arasında. Ana projenin projeleri
 * yöneten kişisi alt projeyi zaten yönetebildiğinden (kurulum, tablolar) hedefin iki adımlı doğrulaması sorulmaz.
 */
final class Handoff
{
    public const TTL = 60;

    public static function issue(User $from, string $siteKey): string
    {
        $token = bin2hex(random_bytes(24));
        Sites::run($siteKey, static function () use ($from, $token) {
            $target = User::query()->where('email', mb_strtolower((string) $from->email))
                ->where('role', User::ADMIN)->where('status', User::ACTIVE)->first();
            if (! $target) {
                throw new DomainException('km_handoff_no_account', 'Bu projede e-posta adresinizle etkin bir yönetici yok; orada giriş yapın.', 409);
            }
            DB::table('handoff_tokens')->where('expires_at', '<', Clock::now())->delete();
            DB::table('handoff_tokens')->insert([
                'token_hash' => hash('sha256', $token),
                'user_id' => $target->id,
                'from_user' => mb_substr((string) $from->username, 0, 60),
                'expires_at' => now()->addSeconds(self::TTL)->format('Y-m-d H:i:s'),
                'created_at' => Clock::now(),
            ]);
        });

        return $token;
    }

    /**
     * Anahtar → kullanıcı (bir kez, süresi içinde; kullanıcı hâlâ etkin yönetici).
     *
     * @return array{user:User,from:string}
     */
    public static function redeem(string $token): array
    {
        $invalid = new DomainException('km_handoff_invalid', 'Geçiş bağlantısının süresi dolmuş ya da kullanılmış; giriş yapın.', 403);
        if (! preg_match('/^[0-9a-f]{48}$/', $token)) {
            throw $invalid;
        }
        $row = DB::table('handoff_tokens')->where('token_hash', hash('sha256', $token))->first();
        // Tek kullanım: yalnızca kullanılmamış ve süresi geçmemiş satır işaretlenir (yarışta biri kazanır).
        $claimed = $row ? DB::table('handoff_tokens')->where('id', $row->id)->whereNull('used_at')->where('expires_at', '>=', Clock::now())->update(['used_at' => Clock::now()]) : 0;
        if (! $claimed) {
            throw $invalid;
        }
        $user = User::query()->find($row->user_id);
        if (! $user || ! $user->isAdmin() || ! $user->canAccessPanel()) {
            throw $invalid;
        }

        return ['user' => $user, 'from' => (string) $row->from_user];
    }
}
