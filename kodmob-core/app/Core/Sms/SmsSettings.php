<?php

namespace App\Core\Sms;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Options;
use App\Core\Support\Secret;
use App\Core\Support\Text;

/**
 * SMS ayarları (Sistem Ayarları › SMS): seçili sağlayıcı ve her sağlayıcının kendi alanları (SmsProvider::fields()).
 *
 *   sms_settings = { provider: "netgsm", values: { netgsm: { username, password: "enc:…", sender, … }, log: { … } } }
 *
 * - `off`: kapalı (varsayılan; panelde SMS düğmeleri görünmez). Sağlayıcılar SmsProviders kaydından (NetGSM ve deneme
 *   çatıda; diğerleri araç paketi). Paketi kaldırılmış sağlayıcı seçiliyse yönetici ne yapacağını görür.
 * - Gizli alanlar (`secret`) APP_KEY ile mühürlü saklanır, panele geri gönderilmez; boş gelen gizli alan eskisini korur,
 *   `clear` listesindekiler silinir.
 * - 3.0'ın düz NetGSM ayarları (username, password, sender, iys_filter, app_name) okunurken `values.netgsm`'e taşınır.
 */
final class SmsSettings
{
    private const OPTION = 'sms_settings';

    /** Kayıtlı ayar (gizliler açık): sağlayıcı ve her sağlayıcının değerleri. @return array{provider:string,values:array<string,array<string,string>>} */
    public static function get(): array
    {
        $stored = Options::get(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $values = is_array($stored['values'] ?? null) ? $stored['values'] : [];
        // 3.0: NetGSM alanları düz saklanıyordu.
        if (! isset($values['netgsm']) && isset($stored['username'])) {
            $values['netgsm'] = array_intersect_key($stored, array_flip(['username', 'password', 'sender', 'iys_filter', 'app_name']));
        }
        foreach ($values as $provider => $fields) {
            $values[$provider] = array_map(static fn ($v) => is_string($v) && str_starts_with($v, 'enc:') ? Secret::open($v) : (string) $v, is_array($fields) ? $fields : []);
        }

        return ['provider' => (string) ($stored['provider'] ?? 'off'), 'values' => $values];
    }

    /** Panelde SMS düğmeleri görünsün mü: sağlayıcı seçili, kurulu ve zorunlu alanlar dolu. */
    public static function enabled(): bool
    {
        return self::missing(self::get()) === null;
    }

    /** Ayarlı sağlayıcının göndericisi; kapalı ya da eksikse yöneticiye ne yapacağını söyleyen hata. */
    public static function gateway(): SmsGateway
    {
        $s = self::get();
        $missing = self::missing($s);
        if ($missing !== null) {
            throw new DomainException('km_sms_off', $missing, 422);
        }
        $provider = SmsProviders::get($s['provider']);

        return $provider->gateway(self::values($provider, $s));
    }

    /** Panelde gösterilen hal: sağlayıcılar ve alanları, seçilinin değerleri (gizliler yok, yalnızca dolu mu). */
    public static function view(): array
    {
        $s = self::get();
        $providers = [];
        foreach (SmsProviders::all() as $key => $provider) {
            $values = self::values($provider, $s);
            $providers[] = [
                'key' => $key, 'label' => $provider->label(), 'help' => $provider->help(),
                'fields' => array_map(static fn (array $f) => $f + ['required' => false, 'options' => [], 'help' => '', 'placeholder' => '', 'default' => ''], $provider->fields()),
                'values' => (object) array_filter($values, static fn ($k) => ! self::secret($provider, $k), ARRAY_FILTER_USE_KEY),
                'secrets' => (object) array_map(static fn ($k) => $values[$k] !== '', array_combine(self::secretKeys($provider), self::secretKeys($provider)) ?: []),
            ];
        }
        $stale = $s['provider'] !== 'off' && SmsProviders::get($s['provider']) === null;

        return [
            'provider' => $stale ? 'off' : $s['provider'],
            'providers' => $providers,
            'enabled' => self::missing($s) === null,
            'problem' => $s['provider'] === 'off' ? null : self::missing($s),
        ];
    }

    /**
     * Kaydeder: `{ provider, values: { alan: değer }, clear: [gizli alan] }`. Verilmeyen alan değişmez; zorunlu alanlar
     * ve seçenekler denetlenir.
     */
    public static function save(array $input): array
    {
        $stored = Options::get(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $s = self::get();
        $key = array_key_exists('provider', $input) ? (string) $input['provider'] : $s['provider'];
        $provider = $key === 'off' ? null : SmsProviders::get($key);
        if ($key !== 'off' && $provider === null) {
            throw ValidationException::field('provider', 'Geçersiz SMS sağlayıcısı.');
        }
        $all = $s['values'];
        if ($provider !== null) {
            $values = self::values($provider, $s);
            $incoming = is_array($input['values'] ?? null) ? $input['values'] : [];
            $clear = array_map('strval', (array) ($input['clear'] ?? []));
            $errors = [];
            foreach ($provider->fields() as $field) {
                $name = $field['key'];
                if (in_array($name, $clear, true) && $field['type'] === 'secret') {
                    $values[$name] = '';
                } elseif (array_key_exists($name, $incoming)) {
                    $value = (string) $incoming[$name];
                    if ($field['type'] === 'secret' && $value === '') {
                        continue;
                    }
                    $value = $field['type'] === 'secret' ? mb_substr($value, 0, 500) : mb_substr(Text::field($value), 0, (int) ($field['max'] ?? 200));
                    if (($field['options'] ?? []) && ! array_key_exists($value, $field['options'])) {
                        $errors[$name] = $field['label'].': geçersiz seçim.';
                    }
                    $values[$name] = $value;
                }
                if (($field['required'] ?? false) && $field['type'] !== 'secret' && ($values[$name] ?? '') === '') {
                    $errors[$name] = $field['message'] ?? $field['label'].' gerekli.';
                }
            }
            if ($errors) {
                throw ValidationException::fields($errors);
            }
            $all[$key] = $values;
        }
        // Gizliler mühürlenerek yazılır; 3.0'ın düz alanları kalkar.
        $sealed = [];
        foreach ($all as $providerKey => $values) {
            $p = SmsProviders::get((string) $providerKey);
            $sealed[$providerKey] = array_map(static fn ($v) => (string) $v, $values);
            foreach ($p ? self::secretKeys($p) : [] as $secret) {
                if (isset($sealed[$providerKey][$secret])) {
                    $sealed[$providerKey][$secret] = Secret::seal($sealed[$providerKey][$secret]);
                }
            }
            if ($p === null) {
                // Kurulu olmayan sağlayıcının saklı değerleri olduğu gibi (zaten mühürlü) korunur.
                $sealed[$providerKey] = (array) ($stored['values'][$providerKey] ?? $values);
            }
        }
        Options::set(self::OPTION, ['provider' => $key, 'values' => $sealed]);

        return self::view();
    }

    /** Sağlayıcının bütün alanları, kayıtlı ya da varsayılan değerleriyle. @return array<string,string> */
    private static function values(SmsProvider $provider, array $s): array
    {
        $saved = $s['values'][$provider->key()] ?? [];
        $out = [];
        foreach ($provider->fields() as $field) {
            $out[$field['key']] = (string) ($saved[$field['key']] ?? ($field['default'] ?? ''));
        }

        return $out;
    }

    /** @return list<string> */
    private static function secretKeys(SmsProvider $provider): array
    {
        return array_values(array_map(static fn ($f) => $f['key'], array_filter($provider->fields(), static fn ($f) => $f['type'] === 'secret')));
    }

    private static function secret(SmsProvider $provider, string $key): bool
    {
        return in_array($key, self::secretKeys($provider), true);
    }

    private static function missing(array $s): ?string
    {
        if ($s['provider'] === 'off') {
            return 'SMS kapalı: Sistem Ayarları › SMS\'ten bir sağlayıcı seçin.';
        }
        $provider = SmsProviders::get($s['provider']);
        if ($provider === null) {
            return "SMS sağlayıcısı \"{$s['provider']}\" kurulu değil: geliştirme bilgisayarında `php artisan kodmob:add {$s['provider']}` (Araç Pazarı › Keşfet), ya da başka bir sağlayıcı seçin.";
        }
        $values = self::values($provider, $s);
        foreach ($provider->fields() as $field) {
            if (($field['required'] ?? false) && ($values[$field['key']] ?? '') === '') {
                return $provider->label().' '.mb_strtolower($field['label']).' eksik (Sistem Ayarları › SMS).';
            }
        }

        return null;
    }
}
