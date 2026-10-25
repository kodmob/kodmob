<?php

namespace App\Core\Exceptions;

use App\Core\Http\MessageCatalog;

/**
 * Bir iş kuralının ihlal edildiğini bildiren istisnaların tabanı.
 *
 * Hata biçimleyici (Core\Http\ErrorRenderer) bu istisnayı `{ code, message,
 * details }` gövdeli HTTP yanıtına çevirir. `code` ön yüzdeki hata haritasının
 * anahtarıdır (kodmob-frontend/src/domain/errors.js), `message` yöneticiye gösterilebilecek
 * Türkçe metindir: ne oldu ve ne yapmalı.
 */
class DomainException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 422,
        private readonly array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }

    public function toArray(): array
    {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            // Mesajın kodu (çok dilli panel çeviriyi bununla bulur; MessageCatalog).
            'message_code' => MessageCatalog::code($this->getMessage()),
            'details' => (object) $this->details,
        ];
    }
}
