<?php

namespace App\Core\Ai;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Options;
use App\Core\Support\Secret;

/**
 * Yapay zekâ ayarları (`ai_settings` seçeneği; Ayarlar > Yapay zekâ):
 *
 *   { provider: "anthropic", providers: { anthropic: { api_key, model }, openai: {...}, google: {...} },
 *     effort, web_search, max_steps }
 *
 * Her sağlayıcının anahtarı ayrı ve şifreli saklanır (Secret), hiçbir uçtan geri dönmez; panel yalnızca
 * son 4 karakteri görür. Etkin sağlayıcı varsayılan olarak Claude. Eski Jarvis ayarları (`jarvis_ai`:
 * tek Claude anahtarı) ilk okumada Claude sağlayıcısına taşınır.
 */
final class AiSettings
{
    private const OPTION = 'ai_settings';

    private const LEGACY = 'jarvis_ai';

    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    private const DEFAULTS = ['provider' => 'anthropic', 'providers' => [], 'effort' => 'medium', 'web_search' => true, 'max_steps' => 10];

    public static function get(): array
    {
        $stored = Options::get(self::OPTION);
        if (! is_array($stored)) {
            $stored = self::fromLegacy(Options::get(self::LEGACY));
        }
        $s = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $providers = [];
        foreach (Ai::all() as $key => $provider) {
            $p = is_array($s['providers'][$key] ?? null) ? $s['providers'][$key] : [];
            $providers[$key] = [
                'api_key' => Secret::open($p['api_key'] ?? ''),
                'model' => (string) (($p['model'] ?? '') ?: $provider->defaultModel()),
            ];
        }
        $s['providers'] = $providers;
        if (! isset($providers[$s['provider']])) {
            $s['provider'] = self::DEFAULTS['provider'];
        }

        return $s;
    }

    /** Etkin sağlayıcının anahtarı ve modeli. */
    public static function active(): array
    {
        $s = self::get();

        return ['provider' => $s['provider']] + $s['providers'][$s['provider']];
    }

    /** Panelde gösterilen hal (anahtarsız). */
    public static function view(): array
    {
        $s = self::get();

        return [
            'provider' => $s['provider'],
            'configured' => $s['providers'][$s['provider']]['api_key'] !== '',
            'providers' => array_values(array_map(static function (AiProvider $provider) use ($s) {
                $p = $s['providers'][$provider->key()];

                return [
                    'key' => $provider->key(),
                    'label' => $provider->label(),
                    'configured' => $p['api_key'] !== '',
                    'key_hint' => $p['api_key'] !== '' ? '…'.substr($p['api_key'], -4) : '',
                    'key_placeholder' => $provider->keyPlaceholder(),
                    'model' => $p['model'],
                    'models' => array_map(static fn ($id, $label) => ['id' => $id, 'label' => $label], array_keys($provider->models()), $provider->models()),
                    'supports' => ['web_search' => $provider->supports('web_search'), 'effort' => $provider->supports('effort')],
                ];
            }, Ai::all())),
            'effort' => $s['effort'],
            'efforts' => self::EFFORTS,
            'web_search' => (bool) $s['web_search'],
            'max_steps' => (int) $s['max_steps'],
        ];
    }

    /**
     * Verilmeyen alan değişmez. `providers.<anahtar>.api_key` boş gelirse eski anahtar kalır,
     * `clear_key: true` siler. Model listede olmasa da kabul edilir (sağlayıcının yeni modeli).
     */
    public static function save(array $input): array
    {
        $s = self::get();
        if (array_key_exists('provider', $input)) {
            if (! isset($s['providers'][$input['provider']])) {
                throw ValidationException::field('provider', 'Bilinmeyen yapay zekâ sağlayıcısı.');
            }
            $s['provider'] = (string) $input['provider'];
        }
        foreach ((array) ($input['providers'] ?? []) as $key => $values) {
            if (! isset($s['providers'][$key]) || ! is_array($values)) {
                continue;
            }
            if (! empty($values['clear_key'])) {
                $s['providers'][$key]['api_key'] = '';
            } elseif (isset($values['api_key']) && trim((string) $values['api_key']) !== '') {
                $apiKey = trim((string) $values['api_key']);
                if (strlen($apiKey) < 20 || preg_match('/\s/', $apiKey)) {
                    throw ValidationException::field("providers.{$key}.api_key", 'Bu bir API anahtarına benzemiyor (boşluksuz, en az 20 karakter).');
                }
                $s['providers'][$key]['api_key'] = $apiKey;
            }
            if (isset($values['model'])) {
                $model = trim((string) $values['model']);
                if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{1,100}$/', $model)) {
                    throw ValidationException::field("providers.{$key}.model", 'Geçersiz model adı.');
                }
                $s['providers'][$key]['model'] = $model;
            }
        }
        if (isset($input['effort'])) {
            if (! in_array($input['effort'], self::EFFORTS, true)) {
                throw ValidationException::field('effort', 'Geçersiz düşünme düzeyi.');
            }
            $s['effort'] = (string) $input['effort'];
        }
        if (isset($input['web_search'])) {
            $s['web_search'] = filter_var($input['web_search'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($input['max_steps'])) {
            $s['max_steps'] = max(1, min(25, (int) $input['max_steps']));
        }

        $stored = $s;
        foreach ($stored['providers'] as $key => $p) {
            $stored['providers'][$key]['api_key'] = Secret::seal($p['api_key']);
        }
        Options::set(self::OPTION, $stored);

        return self::view();
    }

    /** Eski Jarvis ayarı (tek Claude anahtarı) → yeni biçim; anahtar şifreli haliyle taşınır. */
    private static function fromLegacy(mixed $legacy): array
    {
        if (! is_array($legacy)) {
            return [];
        }

        return [
            'provider' => 'anthropic',
            'providers' => ['anthropic' => ['api_key' => (string) ($legacy['api_key'] ?? ''), 'model' => (string) ($legacy['model'] ?? '')]],
        ] + array_intersect_key($legacy, array_flip(['effort', 'web_search', 'max_steps']));
    }
}
