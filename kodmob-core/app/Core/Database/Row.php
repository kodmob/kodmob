<?php

namespace App\Core\Database;

/** Ham satır (yalnızca liste okuması; gösterim deponun `listRow`'unda). */
final class Row extends Entity
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
        return $this->row;
    }
}
