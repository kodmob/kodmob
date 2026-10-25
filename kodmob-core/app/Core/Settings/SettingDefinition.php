<?php

namespace App\Core\Settings;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Secret;
use App\Core\Support\Text;

/**
 * Tek bir ayarın tanımı: tür, varsayılan, sınırlar ve arayüz metinleri.
 * Modüller tanımlarını `settings()` ile verir (Core\Module); değerler
 * `settings` tablosunda, tanımlar kodda durur.
 */
final class SettingDefinition
{
    public const TYPES = ['int', 'float', 'bool', 'string'];

    /**
     * Panelde özel alan: `color` metin (#rrggbb ya da boş), `image` sayı (medya kimliği, 0 = yok), `secret` metin
     * (API anahtarı gibi: APP_KEY ile mühürlü saklanır, panele boş döner, işlem geçmişine ve yedeğe girmez).
     */
    public const FORMATS = ['', 'color', 'image', 'secret'];

    public const SCOPES = ['', 'project', 'system'];

    /** Kapsamı verilmemiş tanımda sistem sayılan gruplar (Sistem Ayarları; Güvenlik kendi sayfasında). */
    public const SYSTEM_GROUPS = ['Güvenlik', 'Sistem sağlığı'];

    /**
     * @param  array<string,string>  $options  metin ayarında izin verilen değer => etiket
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly int|float|bool|string $default,
        public readonly string $label,
        public readonly string $group = 'Genel',
        public readonly string $help = '',
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly string $unit = '',
        public readonly array $options = [],
        public readonly string $format = '',
        public readonly string $scope = ''
    ) {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException("Geçersiz ayar kapsamı: {$key} ({$scope})");
        }
        if (! preg_match('/^[a-z][a-z0-9_.]*$/', $key)) {
            throw new \InvalidArgumentException('Geçersiz ayar anahtarı: '.$key);
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Geçersiz ayar türü: '.$type);
        }
        if (! in_array($format, self::FORMATS, true) || (in_array($format, ['color', 'secret'], true) && $type !== 'string') || ($format === 'image' && $type !== 'int')) {
            throw new \InvalidArgumentException("Geçersiz ayar biçimi: {$key} ({$type}, {$format})");
        }
    }

    /**
     * Ham değeri (formdan, JSON'dan) türüne çevirir ve sınırları doğrular.
     *
     * @throws ValidationException
     */
    public function cast(mixed $raw): int|float|bool|string
    {
        switch ($this->type) {
            case 'bool':
                $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    throw ValidationException::field($this->key, $this->label.': evet ya da hayır olmalı.');
                }

                return $value;
            case 'int':
                $value = filter_var(is_string($raw) ? trim($raw) : $raw, FILTER_VALIDATE_INT);
                if ($value === false) {
                    throw ValidationException::field($this->key, $this->label.': tam sayı olmalı.');
                }
                break;
            case 'float':
                $normalized = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;
                $value = filter_var($normalized, FILTER_VALIDATE_FLOAT);
                if ($value === false) {
                    throw ValidationException::field($this->key, $this->label.': sayı olmalı.');
                }
                break;
            default:
                $text = Text::field($raw);
                if ($this->options && ! array_key_exists($text, $this->options)) {
                    throw ValidationException::field($this->key, $this->label.': geçersiz seçim.');
                }
                if ($this->format === 'color' && $text !== '') {
                    if (! preg_match('/^#?([0-9a-f]{6})$/i', $text, $m)) {
                        throw ValidationException::field($this->key, $this->label.': #1F5EFF gibi altı haneli renk kodu olmalı.');
                    }
                    $text = '#'.strtolower($m[1]);
                }

                return $text;
        }

        if ($this->min !== null && $value < $this->min) {
            throw ValidationException::field($this->key, trim(sprintf('%s en az %s %s olmalı.', $this->label, $this->min, $this->unit)));
        }
        if ($this->max !== null && $value > $this->max) {
            throw ValidationException::field($this->key, trim(sprintf('%s en fazla %s %s olmalı.', $this->label, $this->max, $this->unit)));
        }

        return $value;
    }

    public function encode(int|float|bool|string $value): string
    {
        if ($this->format === 'secret') {
            return (string) $value === '' ? '' : Secret::seal((string) $value);
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    public function decode(string $stored): int|float|bool|string
    {
        return match ($this->type) {
            'bool' => $stored === '1',
            'int' => (int) $stored,
            'float' => (float) $stored,
            default => $this->format === 'secret' ? Secret::open($stored) : $stored,
        };
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'default' => $this->default,
            'label' => $this->label,
            'group' => $this->group,
            'help' => $this->help,
            'min' => $this->min,
            'max' => $this->max,
            'unit' => $this->unit,
            'options' => (object) $this->options,
            'format' => $this->format,
            'scope' => $this->scope(),
        ];
    }

    /**
     * Ayarın yeri: `project` (Proje Ayarları: kimlik, iletişim, modüllerin ayarları) ya da `system` (Sistem
     * Ayarları: kurulumun tamamı). Tanımda verilmezse çekirdeğin sistem grupları sistemdir.
     */
    public function scope(): string
    {
        return $this->scope !== '' ? $this->scope : (in_array($this->group, self::SYSTEM_GROUPS, true) ? 'system' : 'project');
    }
}
