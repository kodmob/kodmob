<?php

namespace App\Modules\Domains\Jobs;

use App\Core\Database\TableRepository;

/**
 * @method Job get(int $id)
 * @method Job|null find(int $id)
 */
final class JobRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'jarvis_jobs';
    }

    protected function entityClass(): string
    {
        return Job::class;
    }

    protected function sortable(): array
    {
        return ['id' => 't.id', 'created_at' => 't.created_at', 'title' => 't.title', 'found' => 't.found', 'status' => 't.status'];
    }

    protected function filterable(): array
    {
        return [
            'status' => ['column' => 't.status', 'type' => 'in', 'aliases' => ['active' => "t.status IN ('queued','running','paused')"]],
            'kind' => ['column' => 't.kind', 'type' => 'eq'],
        ];
    }

    protected function searchable(): array
    {
        return ['t.title'];
    }

    protected function facetable(): array
    {
        return ['status'];
    }

    /** Sıradaki çalıştırılabilir iş (en eskisi). */
    public function nextRunnable(): ?Job
    {
        $row = $this->db->table('jarvis_jobs')->whereIn('status', [Job::RUNNING, Job::QUEUED])->orderBy('id')->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    public function hasRunnable(): bool
    {
        return $this->db->table('jarvis_jobs')->whereIn('status', [Job::QUEUED, Job::RUNNING])->exists();
    }

    /** @return list<Job> */
    public function active(int $limit = 20): array
    {
        return $this->db->table('jarvis_jobs')->whereIn('status', [Job::QUEUED, Job::RUNNING, Job::PAUSED])->orderBy('id')->limit($limit)->get()
            ->map(fn ($row) => $this->hydrate((array) $row))->all();
    }

    public function delete(int $id): void
    {
        $this->db->table('jarvis_jobs')->where('id', $id)->delete();
    }
}
