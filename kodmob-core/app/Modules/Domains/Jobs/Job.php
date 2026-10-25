<?php

namespace App\Modules\Domains\Jobs;

use App\Core\Database\Entity;
use App\Core\Exceptions\ConflictException;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use Illuminate\Support\Facades\Auth;

/**
 * Parça parça ilerleyen uzun iş. Durumlar:
 *
 *     queued → running → done | failed
 *                ↕ paused      ↘ cancelled
 *
 * `state` işleyicinin kaldığı yeri tutar (imleç, bekleyen yeniden denemeler,
 * geri çekilme zamanı). Sayaçlar: `processed` (bakılan), `found` (işe yarayan
 * sonuç, ör. boş domain), `failed` (sonuçlanamayan).
 */
final class Job extends Entity
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    private function __construct(
        private string $kind,
        private string $title,
        private string $status,
        private array $input,
        private array $state,
        private int $total,
        private int $processed,
        private int $found,
        private int $failed,
        private ?string $error,
        private ?int $createdBy,
        private string $createdAt,
        private string $updatedAt,
        private ?string $startedAt,
        private ?string $finishedAt,
    ) {}

    public static function create(string $kind, string $title, array $input, int $total, array $state = []): self
    {
        $now = Clock::now();

        return new self($kind, mb_substr($title, 0, 190), self::QUEUED, $input, $state, $total, 0, 0, 0, null, Auth::id() ? (int) Auth::id() : null, $now, $now, null, null);
    }

    public static function fromRow(array $row): static
    {
        return new self(
            (string) $row['kind'],
            (string) $row['title'],
            (string) $row['status'],
            json_decode((string) $row['input'], true) ?: [],
            $row['state'] ? (json_decode((string) $row['state'], true) ?: []) : [],
            (int) $row['total'],
            (int) $row['processed'],
            (int) $row['found'],
            (int) $row['failed'],
            $row['error'] ?: null,
            $row['created_by'] ? (int) $row['created_by'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['started_at'] ?: null,
            $row['finished_at'] ?: null,
        );
    }

    public function toRow(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'status' => $this->status,
            'input' => Text::json((object) $this->input),
            'state' => Text::json((object) $this->state),
            'total' => $this->total,
            'processed' => $this->processed,
            'found' => $this->found,
            'failed' => $this->failed,
            'error' => $this->error,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'kind' => $this->kind,
            'title' => $this->title,
            'status' => $this->status,
            'input' => (object) $this->input,
            'total' => $this->total,
            'processed' => $this->processed,
            'found' => $this->found,
            'failed' => $this->failed,
            'progress' => $this->total > 0 ? round(min(1, $this->processed / $this->total) * 100, 1) : ($this->isFinished() ? 100 : 0),
            'waiting_until' => isset($this->state['backoff_until']) && $this->state['backoff_until'] > time() ? gmdate('c', (int) $this->state['backoff_until']) : null,
            'error' => $this->error,
            'created_at' => Clock::local($this->createdAt),
            'updated_at' => Clock::local($this->updatedAt),
            'started_at' => $this->startedAt ? Clock::local($this->startedAt) : null,
            'finished_at' => $this->finishedAt ? Clock::local($this->finishedAt) : null,
        ];
    }

    // ------------------------------------------------------------ okuma

    public function kind(): string
    {
        return $this->kind;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function input(): array
    {
        return $this->input;
    }

    public function state(): array
    {
        return $this->state;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function processed(): int
    {
        return $this->processed;
    }

    public function found(): int
    {
        return $this->found;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function isRunnable(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::DONE, self::FAILED, self::CANCELLED], true);
    }

    // ------------------------------------------------------------ işleyici

    public function setState(array $state): void
    {
        $this->state = $state;
        $this->touch();
    }

    public function count(int $processed, int $found = 0, int $failed = 0): void
    {
        $this->processed += $processed;
        $this->found += $found;
        $this->failed += $failed;
        $this->touch();
    }

    public function begin(): void
    {
        if ($this->status === self::QUEUED) {
            $this->status = self::RUNNING;
            $this->startedAt = Clock::now();
        }
        $this->touch();
    }

    public function complete(): void
    {
        $this->status = self::DONE;
        $this->finishedAt = Clock::now();
        $this->touch();
    }

    public function fail(string $error): void
    {
        $this->status = self::FAILED;
        $this->error = mb_substr($error, 0, 500);
        $this->finishedAt = Clock::now();
        $this->touch();
    }

    // ------------------------------------------------------------ kullanıcı

    public function pause(): void
    {
        if (! $this->isRunnable()) {
            throw new ConflictException('km_job_not_running', 'Yalnızca çalışan ya da sırada bekleyen iş duraklatılabilir.');
        }
        $this->status = self::PAUSED;
        $this->touch();
    }

    /** Duraklatılmış ya da hatayla durmuş iş kaldığı yerden sürer. */
    public function resume(): void
    {
        if (! in_array($this->status, [self::PAUSED, self::FAILED], true)) {
            throw new ConflictException('km_job_not_resumable', 'Yalnızca duraklatılmış ya da hatayla durmuş iş sürdürülebilir.');
        }
        $this->status = $this->startedAt ? self::RUNNING : self::QUEUED;
        $this->error = null;
        $this->finishedAt = null;
        unset($this->state['db_errors'], $this->state['backoff_until']);
        $this->touch();
    }

    /**
     * `$count` öğe yeniden kuyruğa alındı (sonuçlanamayanlar ya da, `$found` ise,
     * yeniden doğrulanacak bulunanlar): sayaçlar geri alınır, iş yeniden çalışır.
     */
    public function reopen(int $count, array $state, bool $found = false): void
    {
        $this->processed = max(0, $this->processed - $count);
        if ($found) {
            $this->found = max(0, $this->found - $count);
        } else {
            $this->failed = max(0, $this->failed - $count);
        }
        $this->state = $state;
        $this->status = self::RUNNING;
        $this->error = null;
        $this->finishedAt = null;
        $this->touch();
    }

    public function cancel(): void
    {
        if ($this->isFinished()) {
            throw new ConflictException('km_job_finished', 'İş zaten bitmiş.');
        }
        $this->status = self::CANCELLED;
        $this->finishedAt = Clock::now();
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = Clock::now();
    }
}
