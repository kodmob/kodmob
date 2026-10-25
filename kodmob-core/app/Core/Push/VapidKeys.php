<?php

namespace App\Core\Push;

use App\Core\Support\Options;
use App\Core\Support\Secret;
use App\Core\Support\Text;
use Throwable;

/**
 * Sitenin anlık bildirim anahtar çifti (VAPID): ilk istekte üretilir, özel anahtar mühürlü saklanır (Secret) ve hiçbir
 * yere gönderilmez. Her projenin (alt site) kendi anahtarı vardır. Anahtar değişirse tarayıcıların aboneliği geçersiz
 * olur; bu yüzden bir kez üretilir ve yedekten geri yüklenmez (seçenek yedeğe girmez: Options'ta mühürlü).
 */
final class VapidKeys
{
    private const OPTION = 'push_vapid';

    /** @return array{public:string,private:string,subject:string}|null openssl P-256 desteklemiyorsa null */
    public static function get(): ?array
    {
        $stored = Options::get(self::OPTION);
        $private = is_array($stored) ? Secret::open($stored['private'] ?? '') : '';
        if (! is_array($stored) || $private === '' || empty($stored['public'])) {
            try {
                $keys = WebPush::generateKeys();
            } catch (Throwable) {
                return null;
            }
            $stored = ['public' => WebPush::encode($keys['public']), 'private' => Secret::seal(WebPush::encode($keys['private']))];
            Options::set(self::OPTION, $stored);
            $private = WebPush::encode($keys['private']);
        }

        return ['public' => (string) $stored['public'], 'private' => $private, 'subject' => self::subject()];
    }

    public static function publicKey(): ?string
    {
        return self::get()['public'] ?? null;
    }

    /** İtme servisinin gerekirse ulaşacağı adres: gönderen e-postası, yoksa sitenin adresi. */
    private static function subject(): string
    {
        $mail = (string) config('mail.from.address');
        if (Text::isEmail($mail) && ! str_ends_with($mail, '@example.com')) {
            return 'mailto:'.$mail;
        }
        $url = rtrim((string) config('app.url'), '/');

        return str_starts_with($url, 'https://') ? $url : 'mailto:info@'.(parse_url($url, PHP_URL_HOST) ?: 'localhost');
    }
}
