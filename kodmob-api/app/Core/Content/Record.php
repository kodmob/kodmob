<?php

namespace App\Core\Content;

/**
 * `contents` tablosundaki bir kayıt (proje, hizmet, referans). Veri tek JSON
 * sütunundadır: [ ortak alanlar…, 'i18n' => [ 'tr' => [...], 'en' => …, 'de' => … ] ].
 */
final class Record
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $status,
        public readonly int $position,
        public readonly array $data,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['status'],
            (int) $row['position'],
            json_decode((string) $row['data'], true) ?: [],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
