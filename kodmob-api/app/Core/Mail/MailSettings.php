<?php

namespace App\Core\Mail;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Options;
use App\Core\Support\Secret;
use App\Core\Support\Text;
use Illuminate\Support\Facades\Mail;

/**
 * E-posta gönderim ayarları (Ayarlar > E-posta). `mailer`:
 *
 * - `env`: .env'deki MAIL_* (varsayılan; kurulum paketi sendmail ile gelir);
 * - `smtp`: panelden girilen sunucu (Hostinger: smtp.hostinger.com, 465, SSL);
 * - `sendmail`, `log` (gönderilmez, günlüğe yazılır; deneme için).
 *
 * Şifre APP_KEY ile şifrelenmiş saklanır ve panele hiç geri gönderilmez. Ayarlar yalnızca
 * gönderimden hemen önce uygulanır (SendMail, test gönderimi): her API isteğinde okunmaz.
 */
final class MailSettings
{
    private const OPTION = 'mail_settings';

    public const MAILERS = ['env' => '.env ayarları', 'smtp' => 'SMTP sunucusu', 'sendmail' => 'Sendmail (sunucunun kendisi)', 'log' => 'Gönderme, günlüğe yaz (deneme)'];

    public const ENCRYPTIONS = ['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'Yok'];

    private const DEFAULTS = [
        'mailer' => 'env', 'host' => '', 'port' => 465, 'encryption' => 'ssl',
        'username' => '', 'password' => '', 'from_address' => '', 'from_name' => '',
    ];

    public static function get(): array
    {
        $stored = Options::get(self::OPTION, []);
        $s = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
        $s['password'] = Secret::open($s['password']); // APP_KEY değişmişse '': yeniden girilmeli.

        return $s;
    }

    /** Ayarları Laravel'in posta yapılandırmasına uygular (gönderimden hemen önce). */
    public static function apply(): void
    {
        $s = self::get();
        if ($s['mailer'] === 'env') {
            self::from($s);

            return;
        }
        $config = ['mail.default' => $s['mailer']];
        if ($s['mailer'] === 'smtp') {
            $config += [
                'mail.mailers.smtp.host' => $s['host'],
                'mail.mailers.smtp.port' => (int) $s['port'],
                'mail.mailers.smtp.scheme' => $s['encryption'] === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.username' => $s['username'] !== '' ? $s['username'] : null,
                'mail.mailers.smtp.password' => $s['password'] !== '' ? $s['password'] : null,
            ];
        }
        config($config);
        self::from($s);
        // Önceden kurulmuş posta nesnesi eski ayarla kalmasın.
        Mail::purge($s['mailer']);
    }

    /** Panelde gösterilen hal (şifresiz) + .env'deki karşılıkları. */
    public static function view(): array
    {
        $s = self::get();

        return [
            'mailer' => $s['mailer'],
            'host' => $s['host'],
            'port' => (int) $s['port'],
            'encryption' => $s['encryption'],
            'username' => $s['username'],
            'password_set' => $s['password'] !== '',
            'from_address' => $s['from_address'],
            'from_name' => $s['from_name'],
            'env' => [
                // config/mail.php'nin .env'den okuduğu (apply() gönderimden hemen önce çalışır; yapılandırma önbelleğinde de doğru).
                'mailer' => (string) config('mail.default'),
                'host' => (string) config('mail.mailers.smtp.host'),
                'from_address' => (string) config('mail.from.address'),
                'from_name' => (string) config('mail.from.name'),
            ],
            'mailers' => self::MAILERS,
            'encryptions' => self::ENCRYPTIONS,
        ];
    }

    /** Verilmeyen alan değişmez; `password` boş gelirse eski şifre kalır, `clear_password` siler. */
    public static function save(array $input): array
    {
        $s = self::get();
        foreach (['host', 'username', 'from_name'] as $key) {
            if (array_key_exists($key, $input)) {
                $s[$key] = Text::field((string) $input[$key]);
            }
        }
        if (array_key_exists('mailer', $input)) {
            if (! isset(self::MAILERS[$input['mailer']])) {
                throw ValidationException::field('mailer', 'Geçersiz gönderim yöntemi.');
            }
            $s['mailer'] = (string) $input['mailer'];
        }
        if (array_key_exists('encryption', $input)) {
            if (! isset(self::ENCRYPTIONS[$input['encryption']])) {
                throw ValidationException::field('encryption', 'Geçersiz şifreleme.');
            }
            $s['encryption'] = (string) $input['encryption'];
        }
        if (array_key_exists('port', $input)) {
            $port = (int) $input['port'];
            if ($port < 1 || $port > 65535) {
                throw ValidationException::field('port', 'Port 1 ile 65535 arasında olmalı.');
            }
            $s['port'] = $port;
        }
        if (array_key_exists('from_address', $input)) {
            $from = trim((string) $input['from_address']);
            if ($from !== '' && ! Text::isEmail($from)) {
                throw ValidationException::field('from_address', 'Geçerli bir e-posta adresi yazın.');
            }
            $s['from_address'] = $from;
        }
        if (! empty($input['clear_password'])) {
            $s['password'] = '';
        } elseif (isset($input['password']) && (string) $input['password'] !== '') {
            $s['password'] = mb_substr((string) $input['password'], 0, 500);
        }
        if ($s['mailer'] === 'smtp' && $s['host'] === '') {
            throw ValidationException::field('host', 'SMTP sunucusunun adresini yazın (ör. smtp.hostinger.com).');
        }

        $stored = $s;
        $stored['password'] = Secret::seal($s['password']);
        Options::set(self::OPTION, $stored);

        return self::view();
    }

    private static function from(array $s): void
    {
        if ($s['from_address'] !== '') {
            config(['mail.from.address' => $s['from_address']]);
        }
        if ($s['from_name'] !== '') {
            config(['mail.from.name' => $s['from_name']]);
        }
    }
}
