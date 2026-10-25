<?php

namespace App\Modules\Domains\Check;

use App\Core\Support\Options;
use Illuminate\Support\Facades\Http;

/**
 * Hangi uzantının RDAP sunucusu nerede: IANA'nın resmî önyükleme listesi
 * (data.iana.org/rdap/dns.json). Haftada bir yenilenir; ulaşılamazsa eldeki
 * liste kullanılır. RDAP'ı olmayan uzantılar (ör. .tr) yalnızca DNS ile
 * değerlendirilir ve sonuç "kesin değil" olur.
 */
final class RdapBootstrap
{
    private const OPTION = 'jarvis_rdap_bootstrap';

    private const URL = 'https://data.iana.org/rdap/dns.json';

    private const TTL = 604800;

    /** Önyükleme listesine ulaşılamazsa en azından bunlar çalışsın. */
    private const FALLBACK = [
        'si' => 'https://rdap.register.si/',
        'com' => 'https://rdap.verisign.com/com/v1/',
        'net' => 'https://rdap.verisign.com/net/v1/',
    ];

    /** @var array<string,string>|null */
    private static ?array $map = null;

    public static function url(string $tld): ?string
    {
        $map = self::map();

        return $map[$tld] ?? null;
    }

    /** @return array<string,string> tld → temel adres (sonu /) */
    public static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }
        $stored = Options::get(self::OPTION);
        if (is_array($stored) && ! empty($stored['map']) && ($stored['fetched'] ?? 0) > time() - self::TTL) {
            return self::$map = $stored['map'];
        }
        $fresh = self::fetch();
        if ($fresh) {
            Options::set(self::OPTION, ['fetched' => time(), 'map' => $fresh]);

            return self::$map = $fresh;
        }

        return self::$map = (is_array($stored) && ! empty($stored['map'])) ? $stored['map'] : self::FALLBACK;
    }

    /** @return array<string,string> */
    private static function fetch(): array
    {
        try {
            $response = Http::timeout(15)->acceptJson()->get(self::URL);
        } catch (\Throwable) {
            return [];
        }
        if ($response->status() !== 200) {
            return [];
        }
        $data = $response->json();
        $map = [];
        foreach ((array) ($data['services'] ?? []) as $service) {
            [$tlds, $urls] = [(array) ($service[0] ?? []), (array) ($service[1] ?? [])];
            $url = '';
            foreach ($urls as $candidate) {
                if (str_starts_with((string) $candidate, 'https://')) {
                    $url = (string) $candidate;
                    break;
                }
            }
            $url = $url ?: (string) ($urls[0] ?? '');
            if ($url === '') {
                continue;
            }
            foreach ($tlds as $tld) {
                $map[strtolower((string) $tld)] = rtrim($url, '/').'/';
            }
        }
        ksort($map);

        return $map;
    }
}
