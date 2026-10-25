<?php

namespace App\Modules\Content;

use App\Core\Content\PageCache;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Options;

/**
 * Teklif hesaplayıcının fiyatları ve süreleri (Panel → Teklif hesaplayıcı).
 * Hesaplayıcı sayfası bunları sayfa verisiyle alır (`estimate`); seçenek
 * anahtarları sabittir, adları "Sayfa metinleri"nden değiştirilir.
 *
 * Saklama: `estimate` seçeneği (DEFAULTS ile aynı yapı). Varsayılanlar
 * kodmob-frontend/src/features/estimate-config.js ile aynıdır.
 */
final class EstimateConfig
{
    public const OPTION = 'estimate';

    public const CURRENCIES = ['TRY', 'EUR'];

    private const MAX_PRICE = 100000000;

    public const DEFAULTS = [
        'prices' => [
            'TRY' => [
                'types' => ['landing' => [25000, 45000], 'corporate' => [60000, 120000], 'ecommerce' => [150000, 300000], 'webapp' => [200000, 450000], 'mobile' => [250000, 500000], 'ai' => [150000, 350000]],
                'features' => ['cms' => 15000, 'multilang' => 20000, 'auth' => 25000, 'payments' => 30000, 'booking' => 35000, 'integrations' => 40000, 'seo' => 10000, 'support' => 36000],
                'round' => 5000,
            ],
            'EUR' => [
                'types' => ['landing' => [1500, 3000], 'corporate' => [3500, 7000], 'ecommerce' => [8000, 16000], 'webapp' => [12000, 25000], 'mobile' => [14000, 28000], 'ai' => [9000, 20000]],
                'features' => ['cms' => 900, 'multilang' => 1200, 'auth' => 1500, 'payments' => 1800, 'booking' => 2000, 'integrations' => 2500, 'seo' => 600, 'support' => 2400],
                'round' => 250,
            ],
        ],
        'design' => ['template' => 0.85, 'custom' => 1, 'premium' => 1.3],
        'weeks' => ['landing' => [1, 2], 'corporate' => [3, 6], 'ecommerce' => [6, 10], 'webapp' => [8, 16], 'mobile' => [10, 18], 'ai' => [6, 12]],
        'featureWeeks' => ['cms' => 1, 'multilang' => 1, 'auth' => 1, 'payments' => 1, 'booking' => 2, 'integrations' => 2, 'seo' => 0, 'support' => 0],
        'off' => ['types' => [], 'features' => []],
    ];

    public static function get(): array
    {
        $stored = Options::get(self::OPTION);
        if (! is_array($stored)) {
            return self::DEFAULTS;
        }
        try {
            return self::clean($stored, false);
        } catch (ValidationException) {
            return self::DEFAULTS;
        }
    }

    public static function save(array $input): array
    {
        $value = self::clean($input, true);
        Options::set(self::OPTION, $value);
        PageCache::flush();

        return $value;
    }

    /** Yedekten: doğrulamasız (aralık hatası varsayılana düşer). */
    public static function replace(array $input): void
    {
        Options::set(self::OPTION, self::clean($input, false));
    }

    /**
     * Gelen veriyi DEFAULTS yapısına oturtur; eksik değer varsayılandan gelir.
     * $strict: aralık hataları (en az > en çok, bütün türler gizli) reddedilir.
     *
     * @throws ValidationException alan yolu → hata (ör. "prices.TRY.types.landing")
     */
    public static function clean(array $input, bool $strict): array
    {
        $d = self::DEFAULTS;
        $errors = [];
        $int = static fn ($v, int $fallback, int $max) => is_numeric($v) ? max(0, min($max, (int) round((float) $v))) : $fallback;
        $pair = static function ($v, array $fallback, int $max, string $path) use ($int, &$errors): array {
            $v = is_array($v) ? array_values($v) : [];
            $pair = [$int($v[0] ?? null, $fallback[0], $max), $int($v[1] ?? null, $fallback[1], $max)];
            if ($pair[0] > $pair[1]) {
                $errors[$path] = 'En az değer en çoktan büyük olamaz.';
            }

            return $pair;
        };

        $out = ['prices' => []];
        foreach (self::CURRENCIES as $cur) {
            $src = (array) ($input['prices'][$cur] ?? []);
            $def = $d['prices'][$cur];
            $types = [];
            foreach ($def['types'] as $key => $fallback) {
                $types[$key] = $pair($src['types'][$key] ?? null, $fallback, self::MAX_PRICE, "prices.{$cur}.types.{$key}");
            }
            $features = [];
            foreach ($def['features'] as $key => $fallback) {
                $features[$key] = $int($src['features'][$key] ?? null, $fallback, self::MAX_PRICE);
            }
            $out['prices'][$cur] = ['types' => $types, 'features' => $features, 'round' => max(1, $int($src['round'] ?? null, $def['round'], self::MAX_PRICE))];
        }

        $out['design'] = [];
        foreach ($d['design'] as $key => $fallback) {
            $v = $input['design'][$key] ?? null;
            $out['design'][$key] = is_numeric($v) ? round(max(0.1, min(5, (float) $v)), 2) : $fallback;
        }
        $out['weeks'] = [];
        foreach ($d['weeks'] as $key => $fallback) {
            $out['weeks'][$key] = $pair($input['weeks'][$key] ?? null, $fallback, 104, "weeks.{$key}");
        }
        $out['featureWeeks'] = [];
        foreach ($d['featureWeeks'] as $key => $fallback) {
            $out['featureWeeks'][$key] = $int($input['featureWeeks'][$key] ?? null, $fallback, 52);
        }

        $off = (array) ($input['off'] ?? []);
        $out['off'] = [
            'types' => array_values(array_intersect(array_keys($d['weeks']), (array) ($off['types'] ?? []))),
            'features' => array_values(array_intersect(array_keys($d['featureWeeks']), (array) ($off['features'] ?? []))),
        ];
        if (count($out['off']['types']) === count($d['weeks'])) {
            $errors['off.types'] = 'En az bir proje türü sitede görünmeli.';
        }

        if ($errors && $strict) {
            throw ValidationException::fields($errors);
        }

        return $out;
    }
}
