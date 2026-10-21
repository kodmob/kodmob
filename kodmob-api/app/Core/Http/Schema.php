<?php

namespace App\Core\Http;

use App\Core\Exceptions\ValidationException;

/**
 * Bildirimsel istek şeması (docs/done-sozlesme-raporu.md §4.2): alanlar (Field) ve Türkçe hata mesajları. Aynı tanım
 * sunucuda doğrular ve OpenAPI'ye JSON Schema verir (Core\Contracts\RequestSchemas). Biçim burada; iş kuralları
 * (benzersizlik, çakışma) serviste kalır ve hataları aynı listeye eklenir:
 *
 *   [$values, $errors] = self::schema()->check($input);
 *   if (! isset($errors['email']) && $taken) { $errors['email'] = 'Bu e-posta kayıtlı.'; }
 *   Schema::fail($errors);
 */
final class Schema
{
    /** @param array<string,Field> $fields */
    private function __construct(private readonly array $fields) {}

    /** @param array<string,Field> $fields */
    public static function object(array $fields): self
    {
        return new self($fields);
    }

    /** Alan ekler (ör. yalnızca oluşturmada istenen şifre). */
    public function with(array $fields): self
    {
        return new self($this->fields + $fields);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:array<string,mixed>,1:array<string,string>} temizlenmiş değerler ve alan hataları
     */
    public function check(array $input): array
    {
        $values = [];
        $errors = [];
        foreach ($this->fields as $name => $field) {
            [$value, $error] = $field->check($input[$name] ?? null, array_key_exists($name, $input));
            $values[$name] = $value;
            if ($error !== null) {
                $errors[$name] = $error;
            }
        }

        return [$values, $errors];
    }

    /** @return array<string,mixed> temizlenmiş değerler; hata varsa ValidationException */
    public function validate(array $input): array
    {
        [$values, $errors] = $this->check($input);
        self::fail($errors);

        return $values;
    }

    /** @param array<string,string> $errors */
    public static function fail(array $errors): void
    {
        if ($errors) {
            throw ValidationException::fields($errors);
        }
    }

    /** JSON Schema (OpenAPI istek gövdesi). */
    public function json(): array
    {
        $required = array_keys(array_filter($this->fields, static fn (Field $f) => $f->required()));

        return array_filter([
            'type' => 'object',
            'required' => $required ?: null,
            'properties' => array_map(static fn (Field $f) => $f->json(), $this->fields),
        ], static fn ($v) => $v !== null);
    }
}
