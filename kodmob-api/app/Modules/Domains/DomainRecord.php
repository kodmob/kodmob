<?php

namespace App\Modules\Domains;

use App\Core\Database\Entity;

/** `km_jarvis_domains` satırı (okuma modeli; yazma DomainRepository::upsert ile toplu). */
final class DomainRecord extends Entity
{
    private function __construct(private readonly array $row) {}

    public static function fromRow(array $row): static
    {
        return new self($row);
    }

    public function toRow(): array
    {
        $row = $this->row;
        unset($row['id']);

        return $row;
    }

    public function toArray(): array
    {
        return DomainRepository::present($this->row);
    }
}
