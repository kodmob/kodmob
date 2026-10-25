<?php

namespace App\Core\Events;

use App\Core\Activity\EventGroups;
use App\Core\Support\Clock;
use App\Core\Support\Text;

/**
 * İzlenebilirlik zincirinin bir halkası: "kim, ne zaman, neye, ne yaptı".
 *
 * - `type`: noktalı olay adı, ör. `brief.received`, `jarvis.job_finished`.
 * - `subjectType` / `subjectId`: olayın asıl nesnesi, ör. `brief` / 7.
 * - `related`: zincirdeki diğer nesneler, ör. `['service' => 2]`.
 * - `payload`: olay anındaki değerler. Geçmiş değiştirilmediği için olay kendi
 *   başına okunabilir olmalıdır.
 */
final class Event
{
    /**
     * @param  array<string,int|list<int>>  $related
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly array $payload = [],
        public readonly array $related = [],
        public readonly ?int $userId = null,
        public readonly ?string $occurredAt = null,
        public readonly ?int $id = null,
        /** Kaydın okunur adı (ör. proje başlığı); kayıt sonradan silinse de geçmişte okunur. */
        public readonly string $subjectLabel = '',
        public readonly string $ip = '',
    ) {
        if (! preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $type)) {
            throw new \InvalidArgumentException('Olay adı `nesne.eylem` biçiminde olmalı: '.$type);
        }
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['event_type'],
            (string) $row['subject_type'],
            (int) $row['subject_id'],
            json_decode((string) $row['payload'], true) ?: [],
            json_decode((string) ($row['related'] ?? ''), true) ?: [],
            $row['user_id'] !== null ? (int) $row['user_id'] : null,
            (string) $row['created_at'],
            (int) $row['id'],
            (string) ($row['subject_label'] ?? ''),
            (string) ($row['ip'] ?? ''),
        );
    }

    public function toRow(): array
    {
        return [
            'event_type' => $this->type,
            'group_key' => EventGroups::of($this->type),
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_label' => mb_substr($this->subjectLabel, 0, 190),
            'related' => $this->related ? Text::json($this->related) : null,
            'payload' => Text::json((object) $this->payload),
            'user_id' => $this->userId,
            'ip' => $this->ip,
            'created_at' => $this->occurredAt ?? Clock::now(),
        ];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_label' => $this->subjectLabel,
            'related' => (object) $this->related,
            'payload' => (object) $this->payload,
            'user_id' => $this->userId,
            'created_at' => Clock::atom($this->occurredAt),
        ];
    }
}
