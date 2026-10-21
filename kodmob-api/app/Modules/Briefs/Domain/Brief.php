<?php

namespace App\Modules\Briefs\Domain;

use App\Core\Database\Entity;
use App\Core\Exceptions\ConflictException;
use App\Core\Support\Clock;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Briefs\Service\SubmitBrief;

/**
 * Ziyaretçiden gelen proje brifi ya da iletişim talebi.
 *
 * Durum akışı: new → read → archived (arşivden read'e geri alınabilir).
 * Alanların doğrulaması `Service\SubmitBrief`'tedir; entity yalnızca geçerli
 * durum geçişlerine izin verir.
 */
final class Brief extends Entity
{
    public const NEW = 'new';

    public const READ = 'read';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::NEW => 'Yeni', self::READ => 'Okundu', self::ARCHIVED => 'Arşiv'];

    /**
     * @param  list<string>  $services
     * @param  array<string,mixed>|null  $estimate
     */
    private function __construct(
        private string $locale,
        private string $name,
        private string $email,
        private ?string $phone,
        private ?string $company,
        private array $services,
        private ?string $budget,
        private ?string $timeline,
        private string $message,
        private ?array $estimate,
        private string $source,
        private string $status,
        private string $ipHash,
        private ?string $userAgent,
        private string $createdAt,
        private string $updatedAt
    ) {}

    /**
     * @param  list<string>  $services
     * @param  array<string,mixed>|null  $estimate
     */
    public static function submit(
        string $locale,
        string $name,
        string $email,
        ?string $phone,
        ?string $company,
        array $services,
        ?string $budget,
        ?string $timeline,
        string $message,
        ?array $estimate,
        string $source,
        string $ipHash,
        ?string $userAgent
    ): self {
        $now = Clock::now();

        return new self($locale, $name, $email, $phone, $company, $services, $budget, $timeline, $message, $estimate, $source, self::NEW, $ipHash, $userAgent, $now, $now);
    }

    public function markRead(): void
    {
        if ($this->status === self::NEW) {
            $this->transition(self::READ);
        }
    }

    public function archive(): void
    {
        if ($this->status === self::ARCHIVED) {
            throw new ConflictException('km_brief_archived', 'Talep zaten arşivde.');
        }
        $this->transition(self::ARCHIVED);
    }

    public function reopen(): void
    {
        if ($this->status !== self::ARCHIVED) {
            throw new ConflictException('km_brief_not_archived', 'Yalnızca arşivdeki talep geri alınabilir.');
        }
        $this->transition(self::READ);
    }

    private function transition(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = Clock::now();
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function company(): ?string
    {
        return $this->company;
    }

    /** @return list<string> */
    public function services(): array
    {
        return $this->services;
    }

    public function budget(): ?string
    {
        return $this->budget;
    }

    public function timeline(): ?string
    {
        return $this->timeline;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return array<string,mixed>|null */
    public function estimate(): ?array
    {
        return $this->estimate;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public static function fromRow(array $row): static
    {
        return new self(
            (string) $row['locale'],
            (string) $row['name'],
            (string) $row['email'],
            $row['phone'] !== null ? (string) $row['phone'] : null,
            $row['company'] !== null ? (string) $row['company'] : null,
            json_decode((string) $row['services'], true) ?: [],
            $row['budget'] !== null ? (string) $row['budget'] : null,
            $row['timeline'] !== null ? (string) $row['timeline'] : null,
            (string) $row['message'],
            $row['estimate'] !== null ? (json_decode((string) $row['estimate'], true) ?: null) : null,
            (string) $row['source'],
            (string) $row['status'],
            (string) $row['ip_hash'],
            $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }

    public function toRow(): array
    {
        return [
            'locale' => $this->locale,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'services' => Text::json(array_values($this->services)),
            'budget' => $this->budget,
            'timeline' => $this->timeline,
            'message' => $this->message,
            'estimate' => $this->estimate !== null ? Text::json($this->estimate) : null,
            'source' => $this->source,
            'status' => $this->status,
            'ip_hash' => $this->ipHash,
            'user_agent' => $this->userAgent,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'locale' => $this->locale,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'services' => $this->services,
            // Panel için hizmet adları (sitenin sözlüğünden, varsayılan dilde).
            'service_labels' => array_map(static fn ($s) => SiteManifest::vocab(SubmitBrief::VOCAB)[$s] ?? $s, $this->services),
            'budget' => $this->budget,
            'timeline' => $this->timeline,
            'message' => $this->message,
            'estimate' => $this->estimate,
            'source' => $this->source,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
