<?php

namespace App\Core\Sms;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Options;
use App\Core\Support\Secret;
use App\Core\Support\Text;

/**
 * SMS ayarları (Ayarlar > SMS). `provider`:
 *
 * - `off`: kapalı (varsayılan; panelde SMS düğmeleri görünmez);
 * - `netgsm`: NetGSM hesabı: kullanıcı adı (abone no / alt kullanıcı), API şifresi, onaylı
 *   gönderici başlığı, İYS filtresi, isteğe bağlı uygulama adı (appname);
 * - `log`: gönderilmez, günlüğe yazılır (deneme ve uçtan uca testler).
 *
 * Şifre APP_KEY ile şifrelenmiş saklanır ve panele geri gönderilmez (Secret).
 */
final class SmsSettings
{
    private const OPTION = 'sms_settings';

    public const PROVIDERS = ['off' => 'Kapalı', 'netgsm' => 'NetGSM', 'log' => 'Gönderme, günlüğe yaz (deneme)'];

    /** NetGSM `iysfilter`: bilgilendirme İYS'ye takılmaz; ticari ileti İYS onayı olan alıcıya gider. */
    public const IYS_FILTERS = [
        '0' => 'Bilgilendirme (İYS kontrolü yok)',
        '11' => 'Ticari ileti · bireysel alıcı (İYS kontrollü)',
        '12' => 'Ticari ileti · tacir alıcı (İYS kontrollü)',
    ];

    private const DEFAULTS = ['provider' => 'off', 'username' => '', 'password' => '', 'sender' => '', 'iys_filter' => '0', 'app_name' => ''];

    public static function get(): array
    {
        $stored = Options::get(self::OPTION, []);
        $s = array_merge(self::DEFAULTS, is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []);
        $s['password'] = Secret::open($s['password']);

        return $s;
    }

    /** Panelde SMS düğmeleri görünsün mü: sağlayıcı seçili ve bilgiler tam. */
    public static function enabled(): bool
    {
        return self::missing(self::get()) === null;
    }

    /** Ayarlı sağlayıcı; kapalı ya da eksikse yöneticiye ne yapacağını söyleyen hata. */
    public static function gateway(): SmsGateway
    {
        $s = self::get();
        $missing = self::missing($s);
        if ($missing !== null) {
            throw new DomainException('km_sms_off', $missing, 422);
        }

        return match ($s['provider']) {
            'netgsm' => new NetgsmGateway($s['username'], $s['password'], $s['sender'], $s['iys_filter'], $s['app_name']),
            default => new LogGateway($s['sender']),
        };
    }

    /** Panelde gösterilen hal (şifresiz). */
    public static function view(): array
    {
        $s = self::get();

        return [
            'provider' => $s['provider'],
            'username' => $s['username'],
            'password_set' => $s['password'] !== '',
            'sender' => $s['sender'],
            'iys_filter' => $s['iys_filter'],
            'app_name' => $s['app_name'],
            'enabled' => self::missing($s) === null,
            'problem' => $s['provider'] === 'off' ? null : self::missing($s),
            'providers' => self::PROVIDERS,
            'iys_filters' => self::IYS_FILTERS,
        ];
    }

    /** Verilmeyen alan değişmez; `password` boş gelirse eski şifre kalır, `clear_password` siler. */
    public static function save(array $input): array
    {
        $s = self::get();
        if (array_key_exists('provider', $input)) {
            if (! isset(self::PROVIDERS[$input['provider']])) {
                throw ValidationException::field('provider', 'Geçersiz SMS sağlayıcısı.');
            }
            $s['provider'] = (string) $input['provider'];
        }
        if (array_key_exists('iys_filter', $input)) {
            if (! isset(self::IYS_FILTERS[(string) $input['iys_filter']])) {
                throw ValidationException::field('iys_filter', 'Geçersiz İYS filtresi.');
            }
            $s['iys_filter'] = (string) $input['iys_filter'];
        }
        foreach (['username', 'app_name'] as $key) {
            if (array_key_exists($key, $input)) {
                $s[$key] = mb_substr(Text::field((string) $input[$key]), 0, 100);
            }
        }
        if (array_key_exists('sender', $input)) {
            // Gönderici başlığı NetGSM'de onaylı haliyle yazılmalı (büyük-küçük harf, boşluk dahil).
            $s['sender'] = mb_substr(Text::field((string) $input['sender']), 0, 20);
        }
        if (! empty($input['clear_password'])) {
            $s['password'] = '';
        } elseif (isset($input['password']) && (string) $input['password'] !== '') {
            $s['password'] = mb_substr((string) $input['password'], 0, 200);
        }
        if ($s['provider'] === 'netgsm') {
            $errors = array_filter([
                'username' => $s['username'] === '' ? 'NetGSM kullanıcı adını (abone numarası ya da alt kullanıcı) yazın.' : null,
                'sender' => $s['sender'] === '' ? 'Onaylı gönderici başlığını yazın.' : null,
            ]);
            if ($errors) {
                throw ValidationException::fields($errors);
            }
        }

        $stored = $s;
        $stored['password'] = Secret::seal($s['password']);
        Options::set(self::OPTION, $stored);

        return self::view();
    }

    private static function missing(array $s): ?string
    {
        return match (true) {
            $s['provider'] === 'off' => 'SMS kapalı: Ayarlar > SMS\'ten bir sağlayıcı seçin.',
            $s['provider'] === 'netgsm' && ($s['username'] === '' || $s['password'] === '') => 'NetGSM kullanıcı adı ya da şifresi eksik (Ayarlar > SMS).',
            $s['provider'] === 'netgsm' && $s['sender'] === '' => 'NetGSM gönderici başlığı eksik (Ayarlar > SMS).',
            default => null,
        };
    }
}
