<?php

namespace App\Core\Exceptions;

/**
 * İstek verisi geçersiz. `details.fields` alan adı → Türkçe hata metni taşır;
 * ön yüz formu bu haritayla alanların altına hata yazar.
 */
class ValidationException extends DomainException
{
    /**
     * @param  array<string,string>  $fields
     */
    public static function fields(array $fields, string $message = 'Formdaki hatalı alanları düzeltin.'): self
    {
        return new self('km_validation_failed', $message, 422, ['fields' => $fields]);
    }

    public static function field(string $field, string $error): self
    {
        return self::fields([$field => $error], $error);
    }
}
