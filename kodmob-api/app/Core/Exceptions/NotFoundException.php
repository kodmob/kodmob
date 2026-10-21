<?php

namespace App\Core\Exceptions;

class NotFoundException extends DomainException
{
    public static function for(string $resource, int|string $id, string $message = ''): self
    {
        return new self('km_not_found', $message !== '' ? $message : 'Kayıt bulunamadı.', 404, ['resource' => $resource, 'id' => $id]);
    }

    public static function message(string $message): self
    {
        return new self('km_not_found', $message, 404);
    }
}
