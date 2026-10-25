<?php

namespace App\Modules\Users;

use App\Core\Database\Entity;

/** `users` satırı (liste için; kullanıcı işlemleri Eloquent User modelindedir). */
final class UserRow extends Entity
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
