<?php

namespace App\Modules\Domains;

use App\Core\Settings\SettingsService;
use App\Modules\Domains\Check\CheckResult;
use App\Modules\Domains\Check\DohResolver;
use App\Modules\Domains\Check\RdapBootstrap;
use App\Modules\Domains\Check\RdapClient;

/**
 * Bir grup adın boşta olup olmadığı. Sıra:
 *
 * 1. Kural: geçersiz ad hiç sorulmaz (`invalid`).
 * 2. Önbellek: son 24 saatte kesinleşmiş sonuç varsa o (`cache`).
 * 3. DNS (DoH, paralel): ad sunucusu yetkilendirilmişse kesin kayıtlı (`dns`).
 * 4. RDAP: kalanlar kayıt kuruluşuna sorulur (`rdap`). 404 → boş, 200 → kayıtlı
 *    (silinme sürecindeyse `expiring`). Hız sınırı / geçici hata → `retry`.
 *
 * RDAP'ı olmayan uzantıda DNS'te kaydı olmayan ad `unknown` olur ("muhtemelen boş").
 */
final class DomainChecker
{
    private const EXPIRING_STATES = ['pending delete', 'pendingdelete', 'redemption period', 'redemptionperiod', 'pending restore', 'pendingrestore'];

    /**
     * Kayıt kuruluşunca ayrılmış adlar (RDAP bunlara 403 döner; boş sanılmasın).
     * Kaynak: register.si/en/reserved-domain-names/.
     */
    private const RESERVED = [
        'si' => ['rs', 'si', '112', '113'],
    ];

    /**
     * Süresi dolan adın karantinada (pending delete) kaldığı gün; sonra düşer
     * ve herkes kaydedebilir. Not alanında tahmini düşme tarihi verilir.
     */
    private const QUARANTINE_DAYS = [
        'si' => 30,
    ];

    public function __construct(
        private readonly DomainRepository $repository = new DomainRepository,
        private readonly DohResolver $dns = new DohResolver,
        private readonly ?RdapClient $rdap = null,
    ) {}

    /**
     * `$rdap = false` (hız sınırı beklenirken): yalnızca kural, önbellek ve DNS;
     * RDAP gereken adlar `retry` (kaynak `queue`) döner ve sonra sorulur.
     *
     * @param  list<DomainName>  $domains
     * @return list<CheckResult> girdiyle aynı sırada
     */
    public function check(array $domains, bool $useCache = true, bool $rdap = true): array
    {
        $results = [];
        $open = [];
        foreach ($domains as $domain) {
            $error = DomainName::labelError($domain->label);
            if ($error !== null) {
                $results[$domain->fqdn()] = new CheckResult($domain, CheckResult::INVALID, 'rule', note: $error);
            } elseif (in_array($domain->label, self::RESERVED[$domain->tld] ?? [], true)) {
                $results[$domain->fqdn()] = new CheckResult($domain, CheckResult::INVALID, 'rule', note: 'Kayıt kuruluşunca ayrılmış');
            } else {
                $open[$domain->fqdn()] = $domain;
            }
        }

        if ($useCache && $open) {
            foreach ($this->repository->recent(array_values($open)) as $fqdn => $cached) {
                $results[$fqdn] = $cached;
                unset($open[$fqdn]);
            }
        }

        $dnsState = $open ? $this->dns->probe(array_keys($open)) : [];
        $rdapTargets = [];
        foreach ($open as $fqdn => $domain) {
            if (($dnsState[$fqdn] ?? 'error') === 'delegated') {
                $results[$fqdn] = new CheckResult($domain, CheckResult::TAKEN, 'dns', note: 'DNS\'te ad sunucusu var');

                continue;
            }
            $base = RdapBootstrap::url($domain->tld);
            if ($base === null) {
                $results[$fqdn] = ($dnsState[$fqdn] ?? '') === 'nx'
                    ? new CheckResult($domain, CheckResult::UNKNOWN, 'dns', note: 'DNS kaydı yok; bu uzantının RDAP servisi olmadığından kesin değil (muhtemelen boş)')
                    : new CheckResult($domain, CheckResult::UNKNOWN, 'dns', note: 'Bu uzantının RDAP servisi yok; doğrulanamadı');

                continue;
            }
            if (! $rdap) {
                $results[$fqdn] = new CheckResult($domain, CheckResult::RETRY, 'queue', note: 'RDAP sırası bekleniyor');

                continue;
            }
            $rdapTargets[$fqdn] = $base;
        }

        foreach ($this->rdap()->lookup($rdapTargets) as $fqdn => $answer) {
            $results[$fqdn] = $this->fromRdap($open[$fqdn], $answer);
        }

        $ordered = [];
        foreach ($domains as $domain) {
            $ordered[] = $results[$domain->fqdn()] ?? new CheckResult($domain, CheckResult::RETRY, 'rdap', note: 'Yanıt alınamadı');
        }

        return $ordered;
    }

    /** @param array{code:int,data:?array,retry_after:int} $answer */
    private function fromRdap(DomainName $domain, array $answer): CheckResult
    {
        switch ($answer['code']) {
            case 404:
                return new CheckResult($domain, CheckResult::AVAILABLE, 'rdap', note: 'Kayıt kuruluşunda kaydı yok');
            case 200:
                $info = RdapClient::summarize($answer['data'] ?? []);
                $expiring = (bool) array_intersect(array_map(static fn ($s) => str_replace(' ', '', $s), $info['status']), array_map(static fn ($s) => str_replace(' ', '', $s), self::EXPIRING_STATES));
                $lapsed = $info['expires'] !== null && $info['expires'] < date('Y-m-d');
                $note = $expiring ? 'Silinme sürecinde' : ($lapsed ? 'Süresi geçmiş' : null);
                // Karantina, süresi dolduysa bitişten; erken silindiyse (bitiş ileride) silinmeye
                // alındığı günden (son değişiklik) başlar.
                $from = $lapsed ? $info['expires'] : ($expiring ? ($info['changed'] ?? null) : null);
                if ($from && isset(self::QUARANTINE_DAYS[$domain->tld])) {
                    $note .= ' · tahmini düşme '.gmdate('Y-m-d', strtotime($from.' +'.self::QUARANTINE_DAYS[$domain->tld].' days'));
                }

                return new CheckResult(
                    $domain,
                    $expiring || $lapsed ? CheckResult::EXPIRING : CheckResult::TAKEN,
                    'rdap',
                    $info['registered'],
                    $info['expires'],
                    $info['registrar'],
                    $info['status'] ? mb_substr(implode(', ', $info['status']), 0, 255) : null,
                    $note,
                );
            case 400:
            case 422:
                return new CheckResult($domain, CheckResult::INVALID, 'rdap', note: 'Kayıt kuruluşu adı geçersiz saydı');
            case 429:
                return new CheckResult($domain, CheckResult::RETRY, 'rdap', note: 'Hız sınırı', retryAfter: max(60, $answer['retry_after']), throttled: true);
            case 403:
                // Ayrılmış ad ya da erişim engeli: birkaç kez denenir; tükenince "belirsiz".
                return new CheckResult($domain, CheckResult::RETRY, 'rdap', note: 'RDAP erişimi reddetti (ayrılmış olabilir)');
            default:
                return new CheckResult($domain, CheckResult::RETRY, 'rdap', note: $answer['code'] ? 'RDAP '.$answer['code'] : 'RDAP\'a ulaşılamadı', retryAfter: $answer['code'] >= 500 ? 20 : 0);
        }
    }

    private function rdap(): RdapClient
    {
        return $this->rdap ?? new RdapClient((int) SettingsService::instance()->get(DomainsModule::RDAP_CONCURRENCY));
    }
}
