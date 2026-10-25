<?php

namespace App\Core\Database;

/**
 * Kimliği olan domain nesnesi. Durum değişiklikleri alan atamasıyla değil,
 * kuralı uygulayan metotlarla yapılır (ör. `$brief->archive()`); böylece
 * geçersiz bir duruma düşmek mümkün olmaz.
 *
 * Kalıcılık repository'nin işidir: `fromRow()` veritabanı satırından nesne
 * kurar, `toRow()` yazılacak sütunları verir, `toArray()` API gösterimidir.
 */
abstract class Entity
{
    private ?int $id = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }

    /** Yalnızca repository çağırır: satır yüklenirken ya da ilk INSERT'ten sonra. */
    final public function assignId(int $id): void
    {
        if ($this->id !== null && $this->id !== $id) {
            throw new \LogicException(static::class.' kimliği zaten atanmış.');
        }
        $this->id = $id;
    }

    /** @param array<string,mixed> $row */
    abstract public static function fromRow(array $row): static;

    /** @return array<string,mixed> `id` hariç sütun => değer */
    abstract public function toRow(): array;

    /** @return array<string,mixed> */
    abstract public function toArray(): array;
}
