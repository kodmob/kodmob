<?php

namespace App\Core\Http;

use App\Core\Support\Text;
use Closure;

/**
 * İstek şemasının bir alanı (Schema): türü, zorunluluğu, sınırları, kalıbı, seçenekleri ve tek Türkçe hata mesajı.
 * Değer önce temizlenir (kırp, küçült, ayıkla), sonra denetlenir; şema aynı tanımdan JSON Schema verir.
 *
 *   Field::string('Ad en az 2 karakter olmalı.')->cut(120)->min(2)
 *   Field::enum(fn () => array_keys(Roles::all()), 'Rol seçin.')
 *   Field::boolean()->optional()->default(true)
 */
final class Field
{
    private bool $required = true;

    private ?int $min = null;

    private ?int $max = null;

    private ?int $cut = null;

    private ?string $pattern = null;

    private ?string $strip = null;

    private bool $lower = false;

    private bool $multiline = false;

    private mixed $default = null;

    private string $description = '';

    private function __construct(private readonly string $type, private readonly string $message, private readonly ?Closure $options = null) {}

    public static function string(string $message = ''): self
    {
        return new self('string', $message);
    }

    public static function email(string $message = ''): self
    {
        return new self('email', $message);
    }

    public static function integer(string $message = ''): self
    {
        return new self('integer', $message);
    }

    public static function boolean(): self
    {
        return (new self('boolean', ''))->optional();
    }

    /** @param  Closure(): list<string|int>  $options  izinli değerler (çağrıldığında: kayıt güncel olsun) */
    public static function enum(Closure $options, string $message = ''): self
    {
        return new self('enum', $message, $options);
    }

    public function optional(): self
    {
        $this->required = false;

        return $this;
    }

    /** Metinde en az uzunluk, sayıda en küçük değer. */
    public function min(int $min): self
    {
        $this->min = $min;

        return $this;
    }

    /** Metinde en çok uzunluk (aşan hata), sayıda en büyük değer. */
    public function max(int $max): self
    {
        $this->max = $max;

        return $this;
    }

    /** Uzun metni hata vermeden bu uzunlukta keser. */
    public function cut(int $length): self
    {
        $this->cut = $length;

        return $this;
    }

    public function pattern(string $regex): self
    {
        $this->pattern = $regex;

        return $this;
    }

    /** Bu kalıba uyan karakterleri siler (ör. telefonda rakam dışı). */
    public function strip(string $regex): self
    {
        $this->strip = $regex;

        return $this;
    }

    public function lower(): self
    {
        $this->lower = true;

        return $this;
    }

    public function multiline(): self
    {
        $this->multiline = true;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;

        return $this;
    }

    public function describe(string $text): self
    {
        $this->description = $text;

        return $this;
    }

    /**
     * @return array{0:mixed,1:?string} temizlenmiş değer ve hata (yoksa null)
     */
    public function check(mixed $raw, bool $present): array
    {
        if (! $present || $raw === null || $raw === '') {
            if ($this->required) {
                return [$this->blank(), $this->message ?: 'Bu alan gerekli.'];
            }
            if (! $present || $raw === null) {
                return [$this->default ?? $this->blank(), null];
            }
        }
        switch ($this->type) {
            case 'boolean':
                return [filter_var($raw, FILTER_VALIDATE_BOOLEAN), null];
            case 'integer':
                $value = filter_var($raw, FILTER_VALIDATE_INT);
                $ok = $value !== false && ($this->min === null || $value >= $this->min) && ($this->max === null || $value <= $this->max);

                return [$ok ? $value : 0, $ok ? null : ($this->message ?: 'Geçerli bir sayı yazın.')];
            case 'enum':
                $options = ($this->options)();
                $value = is_scalar($raw) ? $raw : '';
                foreach ($options as $option) {
                    if ((string) $option === (string) $value) {
                        return [$option, null];
                    }
                }

                return [$value, $this->message ?: 'Geçersiz seçim.'];
            case 'email':
                $value = Text::email($raw);

                return [$value, $value === '' || ($this->max !== null && mb_strlen($value) > $this->max) ? ($this->message ?: 'Geçerli bir e-posta adresi yazın.') : null];
            default:
                $value = is_scalar($raw) ? ($this->multiline ? Text::textarea($raw) : Text::field($raw)) : '';
                if ($this->strip !== null) {
                    $value = trim((string) preg_replace($this->strip, '', $value));
                }
                if ($this->lower) {
                    $value = mb_strtolower($value);
                }
                if ($this->cut !== null) {
                    $value = mb_substr($value, 0, $this->cut);
                }
                $length = mb_strlen($value);
                $bad = ($this->required && $value === '')
                    || ($this->min !== null && $length < $this->min)
                    || ($this->max !== null && $length > $this->max)
                    || ($this->pattern !== null && $value !== '' && ! preg_match($this->pattern, $value));

                return [$value, $bad ? ($this->message ?: 'Geçersiz değer.') : null];
        }
    }

    /** JSON Schema (OpenAPI 3.1). */
    public function json(): array
    {
        $schema = match ($this->type) {
            'boolean' => ['type' => 'boolean'],
            'integer' => array_filter(['type' => 'integer', 'minimum' => $this->min, 'maximum' => $this->max], static fn ($v) => $v !== null),
            'enum' => ['enum' => array_values(($this->options)())],
            'email' => array_filter(['type' => 'string', 'format' => 'email', 'maxLength' => $this->max], static fn ($v) => $v !== null),
            default => array_filter([
                'type' => 'string',
                'minLength' => $this->min,
                'maxLength' => $this->max ?? $this->cut,
                'pattern' => $this->pattern !== null ? self::jsonPattern($this->pattern) : null,
            ], static fn ($v) => $v !== null),
        };
        if ($this->default !== null) {
            $schema['default'] = $this->default;
        }
        $text = trim($this->description.' '.$this->message);
        if ($text !== '') {
            $schema['description'] = $text;
        }

        return $schema;
    }

    public function required(): bool
    {
        return $this->required;
    }

    private function blank(): mixed
    {
        return match ($this->type) {
            'boolean' => false,
            'integer' => 0,
            default => '',
        };
    }

    /** PCRE `/…/u` → ECMA kalıbı (ayraçlar ve bayraklar düşer). */
    private static function jsonPattern(string $regex): string
    {
        $delimiter = $regex[0];

        return substr($regex, 1, (int) strrpos($regex, $delimiter) - 1);
    }
}
