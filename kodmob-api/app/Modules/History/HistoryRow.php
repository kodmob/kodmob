<?php

namespace App\Modules\History;

use App\Core\Database\Entity;

/** `events` satırı (yalnız okuma; gösterim HistoryRepository::present). */
final class HistoryRow extends Entity
{
    private function __construct(private readonly array $row) {}

    public static function fromRow(array $row): static
    {
        return new self($row);
    }

    public function toRow(): array
    {
        return $this->row;
    }

    public function toArray(): array
    {
        return HistoryRepository::present([$this->row])[0];
    }
}
