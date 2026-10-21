<?php

namespace App\Modules\Domains;

use App\Core\Database\TableRepository;
use App\Core\Support\Clock;
use App\Modules\Domains\Check\CheckResult;

/**
 * Domain kontrol sonuçları. Liste (kmGrid) filtreleri: `job_id`, `status`,
 * `length`, `tld`, `q` (ad içinde arama); sekme sayıları için durum, uzunluk ve
 * uzantı facet'leri döner.
 */
final class DomainRepository extends TableRepository
{
    /** Bu süreden yeni sonuç önbellek sayılır, yeniden sorulmaz. */
    public const CACHE_HOURS = 24;

    protected function tableName(): string
    {
        return 'jarvis_domains';
    }

    protected function entityClass(): string
    {
        return DomainRecord::class;
    }

    protected function sortable(): array
    {
        return [
            'id' => 't.id',
            'domain' => 't.domain',
            'label' => 't.label',
            'length' => 't.length',
            'status' => 't.status',
            'tld' => 't.tld',
            'checked_at' => 't.checked_at',
            'registered_at' => 't.registered_at',
            'expires_at' => 't.expires_at',
        ];
    }

    protected function filterable(): array
    {
        return [
            'job_id' => ['column' => 't.job_id', 'type' => 'int'],
            'status' => ['column' => 't.status', 'type' => 'in', 'aliases' => ['found' => "t.status IN ('available','expiring')"]],
            'length' => ['column' => 't.length', 'type' => 'in'],
            'tld' => ['column' => 't.tld', 'type' => 'in'],
            'source' => ['column' => 't.source', 'type' => 'in'],
            'registered_from' => ['column' => 't.registered_at', 'type' => 'from'],
        ];
    }

    protected function searchable(): array
    {
        return ['t.domain'];
    }

    protected function facetable(): array
    {
        return ['status', 'length', 'tld'];
    }

    protected function listRow(array $row): array
    {
        return self::present($row);
    }

    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'job_id' => (int) $row['job_id'],
            'domain' => $row['domain'],
            'label' => $row['label'],
            'tld' => $row['tld'],
            'length' => (int) $row['length'],
            'status' => $row['status'],
            'source' => $row['source'],
            'registered_at' => $row['registered_at'],
            'expires_at' => $row['expires_at'],
            'registrar' => $row['registrar'],
            'rdap_status' => $row['rdap_status'],
            'note' => $row['note'],
            'checked_at' => Clock::local($row['checked_at']),
        ];
    }

    /**
     * Sonuçları yazar; aynı işte aynı ad varsa günceller.
     *
     * @param  list<CheckResult>  $results
     */
    public function upsert(int $jobId, array $results, array $attempts = []): void
    {
        $results = array_values(array_filter($results, static fn (CheckResult $r) => in_array($r->status, CheckResult::FINAL, true)));
        if (! $results) {
            return;
        }
        $now = Clock::now();
        $rows = [];
        foreach ($results as $r) {
            $rows[$r->domain->fqdn()] = [
                'job_id' => $jobId,
                'domain' => $r->domain->fqdn(),
                'label' => $r->domain->label,
                'tld' => $r->domain->tld,
                'length' => strlen($r->domain->label),
                'status' => $r->status,
                'source' => $r->source,
                'registered_at' => $r->registeredAt ?: null,
                'expires_at' => $r->expiresAt ?: null,
                'registrar' => (string) $r->registrar,
                'rdap_status' => (string) $r->rdapStatus,
                'note' => (string) $r->note,
                'attempts' => (int) ($attempts[$r->domain->fqdn()] ?? 1),
                'checked_at' => $now,
            ];
        }
        $this->db->table('jarvis_domains')->upsert(
            array_values($rows),
            ['job_id', 'domain'],
            ['status', 'source', 'registered_at', 'expires_at', 'registrar', 'rdap_status', 'note', 'attempts', 'checked_at']
        );
    }

    /**
     * Son CACHE_HOURS içindeki kesin sonuçlar (herhangi bir işten).
     *
     * @param  list<DomainName>  $domains
     * @return array<string,CheckResult>
     */
    public function recent(array $domains): array
    {
        if (! $domains) {
            return [];
        }
        $byFqdn = [];
        foreach ($domains as $domain) {
            $byFqdn[$domain->fqdn()] = $domain;
        }
        $rows = $this->db->table('jarvis_domains')
            ->whereIn('domain', array_keys($byFqdn))
            ->where('checked_at', '>=', now()->subHours(self::CACHE_HOURS)->format('Y-m-d H:i:s'))
            ->whereIn('status', ['available', 'taken', 'expiring'])
            ->orderBy('checked_at')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->domain] = new CheckResult(
                $byFqdn[$row->domain],
                $row->status,
                'cache',
                $row->registered_at,
                $row->expires_at,
                $row->registrar ?: null,
                $row->rdap_status ?: null,
                $row->note ?: null,
            );
        }

        return $out;
    }

    /** @return array{status:array<string,int>,length:array<string,int>} */
    public function stats(int $jobId): array
    {
        $out = ['status' => [], 'length' => []];
        foreach (['status', 'length'] as $column) {
            foreach ($this->db->table('jarvis_domains')->where('job_id', $jobId)->selectRaw("{$column} AS v, COUNT(*) AS c")->groupBy($column)->get() as $row) {
                $out[$column][(string) $row->v] = (int) $row->c;
            }
        }

        return $out;
    }

    /** @return list<array> boş ve silinmekte olan adlar (kısadan uzuna) */
    public function found(int $jobId, int $limit = 50): array
    {
        return $this->db->table('jarvis_domains')->where('job_id', $jobId)->whereIn('status', ['available', 'expiring'])
            ->orderBy('length')->orderBy('domain')->limit($limit)->get()
            ->map(fn ($row) => self::present((array) $row))->all();
    }

    /**
     * @param  list<string>  $statuses
     * @return list<string> işin bu durumlardaki adları (eskiden yeniye)
     */
    public function byStatus(int $jobId, array $statuses, int $limit): array
    {
        return $this->db->table('jarvis_domains')->where('job_id', $jobId)->whereIn('status', $statuses)
            ->orderBy('checked_at')->orderBy('id')->limit($limit)->pluck('domain')->map(fn ($d) => (string) $d)->all();
    }

    /** @param list<string> $domains */
    public function deleteDomains(int $jobId, array $domains): void
    {
        foreach (array_chunk($domains, 500) as $chunk) {
            $this->db->table('jarvis_domains')->where('job_id', $jobId)->whereIn('domain', $chunk)->delete();
        }
    }

    public function deleteJob(int $jobId): void
    {
        $this->db->table('jarvis_domains')->where('job_id', $jobId)->delete();
    }
}
