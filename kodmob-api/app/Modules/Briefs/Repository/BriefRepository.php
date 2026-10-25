<?php

namespace App\Modules\Briefs\Repository;

use App\Core\Database\TableRepository;
use App\Modules\Briefs\Domain\Brief;

/**
 * @method Brief get(int $id)
 */
final class BriefRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'briefs';
    }

    protected function entityClass(): string
    {
        return Brief::class;
    }

    protected function sortable(): array
    {
        return ['id' => 't.id', 'created_at' => 't.created_at', 'name' => 't.name'];
    }

    protected function filterable(): array
    {
        return [
            'status' => ['column' => 't.status', 'type' => 'in'],
            'locale' => ['column' => 't.locale', 'type' => 'in'],
            'source' => ['column' => 't.source', 'type' => 'in'],
            'created_at_from' => ['column' => 't.created_at', 'type' => 'from'],
            'created_at_to' => ['column' => 't.created_at', 'type' => 'to'],
        ];
    }

    protected function searchable(): array
    {
        return ['t.name', 't.email', 't.company', 't.message'];
    }

    protected function facetable(): array
    {
        return ['status', 'source', 'locale'];
    }

    /** Hız sınırı: aynı IP özetinden son `$minutes` dakikadaki talep sayısı. */
    public function countRecentByIp(string $ipHash, int $minutes): int
    {
        $since = now()->subMinutes($minutes)->format('Y-m-d H:i:s');

        return (int) $this->db->table('briefs')->where('ip_hash', $ipHash)->where('created_at', '>=', $since)->count();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->db->table('briefs')->where('status', $status)->count();
    }

    /**
     * Saklama süresi dolan talepler: `$before` tarihinden önce gelenler.
     *
     * @return list<int>
     */
    public function idsCreatedBefore(string $before, int $limit = 500): array
    {
        return $this->db->table('briefs')->where('created_at', '<', $before)->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Talepleri ve olay defterindeki izlerini (`brief` olayları ad içerir)
     * kalıcı olarak siler. Çağıran transaction içinde çalıştırmalıdır.
     *
     * @param  list<int>  $ids
     */
    public function deleteWithEvents(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids) {
            return 0;
        }
        $events = $this->db->table('events')->where('subject_type', 'brief')->whereIn('subject_id', $ids)->pluck('id')->all();
        if ($events) {
            $this->db->table('event_links')->whereIn('event_id', $events)->delete();
            $this->db->table('events')->whereIn('id', $events)->delete();
        }

        return $this->db->table('briefs')->whereIn('id', $ids)->delete();
    }
}
