<?php

namespace App\Modules\Security;

use App\Core\Mail\SendMail;
use App\Core\Mail\SiteMail;
use App\Core\Support\Clock;
use App\Core\Support\PanelUrl;
use App\Core\Support\Text;
use App\Core\Support\UserAgent;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Yeni cihaz uyarısı: kişi daha önce görülmemiş bir cihazdan (tarayıcı + ağ) girince kendisine e-posta gider; tanımadığı
 * girişse Profilim › Oturumlar'dan kapatır ve şifresini değiştirir. İlk giriş uyarı göndermez (henüz bilinen cihaz yok).
 * Cihaz geri çevrilemeyen özetle tanınır (tarayıcı ailesi + IP'nin ağ kısmı: IPv4 /24, IPv6 /48); aynı evden ya da
 * ofisten tarayıcı güncellemesi yeni cihaz sayılmaz. E-posta sitenin şablonuyla (SiteMail), kişinin dilinde değil
 * panelin dilinde (Türkçe).
 *
 * Ayar: Güvenlik › Ayarlar › "Yeni cihaz uyarısı" (`security.new_device_alert`, varsayılan açık).
 */
final class NewDeviceAlert
{
    public static function check(User $user, Request $request): void
    {
        if (! SecurityModule::bool(SecurityModule::NEW_DEVICE_ALERT, true)) {
            return;
        }
        try {
            $device = UserAgent::describe((string) $request->userAgent());
            $fingerprint = hash('sha256', $user->id.'|'.preg_replace('/\s[\d.]+/', '', $device).'|'.self::network((string) $request->ip()));
            $query = DB::table('known_devices')->where('user_id', $user->id);
            $known = (clone $query)->where('fingerprint', $fingerprint)->exists();
            $first = ! (clone $query)->exists();
            if ($known) {
                (clone $query)->where('fingerprint', $fingerprint)->update(['last_seen_at' => Clock::now()]);

                return;
            }
            DB::table('known_devices')->insert(['user_id' => $user->id, 'fingerprint' => $fingerprint, 'first_seen_at' => Clock::now(), 'last_seen_at' => Clock::now()]);
            if ($first || ! Text::isEmail((string) $user->email)) {
                return;
            }
            SecurityLog::record(SecurityLog::NEW_DEVICE, $request, $device, (string) $user->username, (int) $user->id);
            SendMail::to((string) $user->email, new SiteMail('Hesabınıza yeni bir cihazdan giriş yapıldı', [
                'Merhaba '.((string) $user->display_name ?: (string) $user->username).',',
                'Hesabınıza daha önce görmediğimiz bir cihazdan giriş yapıldı. Siz yaptıysanız bir şey yapmanız gerekmez.',
                'Siz değilseniz hemen Profilim › Oturumlar\'dan bu oturumu kapatın ve şifrenizi değiştirin; iki adımlı doğrulamayı açmanızı öneririz.',
            ], [
                'Zaman' => (string) Clock::local(Clock::now()),
                'Cihaz' => $device,
                'IP' => self::mask((string) $request->ip()),
            ], ['label' => 'Oturumlarımı gör', 'url' => rtrim((string) config('app.url'), '/').PanelUrl::to('profile')]), 'security.new_device');
        } catch (Throwable) {
            // Uyarı girişi engellemez.
        }
    }

    /** IP'nin ağ kısmı (IPv4 /24, IPv6 /48). */
    private static function network(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3));
        }
        $packed = @inet_pton($ip);

        return $packed !== false ? bin2hex(substr($packed, 0, 6)) : $ip;
    }

    /** E-postada IP'nin son kısmı gizli: 203.0.113.x */
    private static function mask(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::network($ip).'.x';
        }

        return $ip !== '' ? implode(':', array_slice(explode(':', $ip), 0, 3)).':…' : '?';
    }
}
