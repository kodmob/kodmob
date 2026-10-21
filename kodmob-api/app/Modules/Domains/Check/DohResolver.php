<?php

namespace App\Modules\Domains\Check;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * DNS ön elemesi: DNS-over-HTTPS (Cloudflare, olmazsa Google) ile NS sorgusu.
 * Ücretsiz, anahtarsız ve paralel çalışır; kayıt kuruluşunu yormaz.
 *
 * - `delegated`: ad sunuculara yetkilendirilmiş → kesin kayıtlı
 * - `nx`:        NXDOMAIN → büyük olasılıkla boş (RDAP doğrular)
 * - `nodata`:    yanıt var ama NS yok → RDAP'a sorulur
 * - `error`:     ulaşılamadı → RDAP'a sorulur
 *
 * Kayıtlı ama ad sunucusu olmayan alanlar da vardır; bu yüzden "nx" tek başına
 * "boş" sayılmaz.
 */
final class DohResolver
{
    private const PROVIDERS = [
        'https://cloudflare-dns.com/dns-query',
        'https://dns.google/resolve',
    ];

    private const CONCURRENCY = 16;

    public function __construct(private readonly ?Client $client = null) {}

    /**
     * @param  list<string>  $fqdns
     * @return array<string,string> fqdn → delegated|nx|nodata|error
     */
    public function probe(array $fqdns): array
    {
        $results = array_fill_keys($fqdns, 'error');
        $pending = $fqdns;
        foreach (self::PROVIDERS as $provider) {
            if (! $pending) {
                break;
            }
            $answered = $this->query($provider, $pending);
            foreach ($answered as $fqdn => $status) {
                $results[$fqdn] = $status;
            }
            $pending = array_keys(array_filter($results, static fn ($s) => $s === 'error'));
        }

        return $results;
    }

    /** @return array<string,string> */
    private function query(string $provider, array $fqdns): array
    {
        $client = $this->client ?? new Client([
            'timeout' => 6,
            'connect_timeout' => 4,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/dns-json', 'User-Agent' => 'KodmobJarvis/1.0 (+https://kodmob.com)'],
        ]);
        $requests = static function () use ($provider, $fqdns) {
            foreach ($fqdns as $fqdn) {
                yield $fqdn => new Request('GET', $provider.'?name='.rawurlencode($fqdn).'&type=NS');
            }
        };
        $out = [];
        $pool = new Pool($client, $requests(), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled' => static function (ResponseInterface $response, string $fqdn) use (&$out) {
                $data = $response->getStatusCode() === 200 ? json_decode((string) $response->getBody(), true) : null;
                if (! is_array($data) || ! isset($data['Status'])) {
                    return;
                }
                if ((int) $data['Status'] === 3) {
                    $out[$fqdn] = 'nx';

                    return;
                }
                if ((int) $data['Status'] !== 0) {
                    return;
                }
                foreach ((array) ($data['Answer'] ?? []) as $answer) {
                    if ((int) ($answer['type'] ?? 0) === 2) {
                        $out[$fqdn] = 'delegated';

                        return;
                    }
                }
                $out[$fqdn] = 'nodata';
            },
            'rejected' => static function () {
                // Sağlayıcıya ulaşılamadı: sıradaki sağlayıcı dener.
            },
        ]);
        $pool->promise()->wait();

        return $out;
    }
}
