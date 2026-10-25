<?php

namespace App\Core\Exceptions;

/**
 * Kaydın mevcut durumu istenen işleme izin vermiyor (ör. arşivdeki talebi
 * yeniden arşivleme). HTTP 409.
 */
class ConflictException extends DomainException
{
    public function __construct(string $errorCode, string $message, array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($errorCode, $message, 409, $details, $previous);
    }
}
