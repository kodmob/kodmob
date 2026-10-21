<?php

namespace App\Modules\Domains\Skills;

use App\Core\Http\ListQuery;
use App\Modules\Domains\DomainRepository;
use App\Modules\Jarvis\Skills\Skill;

/** Kayıtlı domain sonuçlarında arama (tarama ya da anlık kontrollerden). */
final class DomainResultsSkill implements Skill
{
    public function name(): string
    {
        return 'domain_results';
    }

    public function title(): string
    {
        return 'Domain sonuçları';
    }

    public function description(): string
    {
        return 'Daha önce kontrol edilmiş/taranmış alan adlarını süzer: job_id (bir tarama), status (available, expiring, found = ikisi, taken, unknown), '
            .'length (etiket uzunluğu), tld, q (ad içinde geçen metin). En çok 100 sonuç; kısadan uzuna sıralı. Toplam sayı da döner.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'job_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['available', 'expiring', 'found', 'taken', 'unknown']],
                'length' => ['type' => 'integer'],
                'tld' => ['type' => 'string'],
                'q' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $input): array
    {
        $filters = array_filter([
            'job_id' => isset($input['job_id']) ? (string) (int) $input['job_id'] : null,
            'status' => $input['status'] ?? 'found',
            'length' => isset($input['length']) ? (string) (int) $input['length'] : null,
            'tld' => isset($input['tld']) ? ltrim(strtolower((string) $input['tld']), '.') : null,
        ], static fn ($v) => $v !== null && $v !== '');
        $limit = max(1, min(100, (int) ($input['limit'] ?? 50)));
        $result = (new DomainRepository)->paginate(new ListQuery(1, $limit, 'length', 'asc', (string) ($input['q'] ?? ''), $filters));

        return [
            'total' => $result->total,
            'items' => array_map(static fn ($r) => array_intersect_key($r, array_flip(['domain', 'status', 'length', 'registered_at', 'expires_at', 'note', 'job_id'])), $result->items),
        ];
    }
}
