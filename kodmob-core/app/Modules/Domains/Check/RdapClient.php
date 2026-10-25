<?php

namespace App\Modules\Domains\Check;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * RDAP (WHOIS'in JSON halefi) sorguları: kayıt kuruluşunun resmî, ücretsiz ve
 * anahtarsız servisi. Sunucu başına düşük eşzamanlılıkla sorulur; 429 (hız
 * sınırı) gelince `Retry-After` kadar beklenir.
 *
 * Dönüş (fqdn başına): ['code' => 200|404|429|…|0, 'data' => array|null, 'retry_after' => saniye]
 */
final class RdapClient
{
    public function __construct(private readonly int $concurrency = 2, private readonly ?Client $client = null) {}

    /**
     * @param  array<string,string>  $targets  fqdn → RDAP temel adresi
     * @return array<string,array{code:int,data:?array,retry_after:int}>
     */
    public function lookup(array $targets): array
    {
        if (! $targets) {
            return [];
        }
        $client = $this->client ?? new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => false,
            'allow_redirects' => ['max' => 3],
            'headers' => ['Accept' => 'application/rdap+json, application/json', 'User-Agent' => 'KodmobJarvis/1.0 (+https://kodmob.com)'],
        ]);
        $requests = static function () use ($targets) {
            foreach ($targets as $fqdn => $base) {
                yield $fqdn => new Request('GET', $base.'domain/'.rawurlencode($fqdn));
            }
        };
        $out = [];
        $pool = new Pool($client, $requests(), [
            'concurrency' => max(1, $this->concurrency),
            'fulfilled' => static function (ResponseInterface $response, string $fqdn) use (&$out) {
                $code = $response->getStatusCode();
                $retry = (int) $response->getHeaderLine('Retry-After');
                $data = $code === 200 ? json_decode((string) $response->getBody(), true) : null;
                $out[$fqdn] = ['code' => $code, 'data' => is_array($data) ? $data : null, 'retry_after' => $retry];
            },
            'rejected' => static function ($reason, string $fqdn) use (&$out) {
                $out[$fqdn] = ['code' => 0, 'data' => null, 'retry_after' => 0];
            },
        ]);
        $pool->promise()->wait();

        return $out;
    }

    /**
     * 200 yanıtından özet: kayıt/bitiş tarihi, kayıt firması, durumlar.
     *
     * @return array{registered:?string,expires:?string,changed:?string,registrar:?string,status:list<string>}
     */
    public static function summarize(array $data): array
    {
        $registered = $expires = $changed = null;
        foreach ((array) ($data['events'] ?? []) as $event) {
            $date = isset($event['eventDate']) ? substr((string) $event['eventDate'], 0, 10) : null;
            if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            match (strtolower((string) ($event['eventAction'] ?? ''))) {
                'registration' => $registered = $date,
                'expiration' => $expires = $date,
                'last changed' => $changed = $date,
                default => null,
            };
        }
        $registrar = null;
        foreach ((array) ($data['entities'] ?? []) as $entity) {
            if (in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                foreach ((array) ($entity['vcardArray'][1] ?? []) as $field) {
                    if (($field[0] ?? '') === 'fn' && ! empty($field[3])) {
                        $registrar = mb_substr((string) $field[3], 0, 160);
                        break 2;
                    }
                }
                $registrar = isset($entity['handle']) ? mb_substr((string) $entity['handle'], 0, 160) : null;
            }
        }
        $status = array_values(array_map(static fn ($s) => strtolower((string) $s), (array) ($data['status'] ?? [])));

        return ['registered' => $registered, 'expires' => $expires, 'changed' => $changed, 'registrar' => $registrar, 'status' => $status];
    }
}
