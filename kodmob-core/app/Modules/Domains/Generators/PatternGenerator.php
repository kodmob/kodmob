<?php

namespace App\Modules\Domains\Generators;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Text;

/**
 * Kalıptan ad üretir; 4–5 karakterli, telaffuz edilebilir adlar için.
 *
 * Kalıp harfleri (büyük harf = küme, küçük harf/rakam/tire = sabit). Varsayılan
 * ünsüzler q, w, x olmadan 18 harf (Türkçe ve İngilizce okunuşa uygun):
 *   C ünsüz · V ünlü · L herhangi harf · D rakam · A harf ya da rakam
 *
 *     CVCV   → bana, kodi, lume …        (18·5·18·5 = 8.100)
 *     CVCVC  → kodas, lumin …            (145.800)
 *     CVCio  → bakio, lumio …            (sabit son ek)
 *
 * Büyük kalıplarda `limit` ile örneklem alınır. `shuffle` açıkken sıra,
 * tohuma bağlı bir permütasyondur (a·i + b mod N): örneklem alfabenin başına
 * yığılmaz, her yerden gelir; aynı tohum hep aynı sırayı verir (iş kaldığı
 * yerden sürer).
 */
final class PatternGenerator extends NameGenerator
{
    public const MAX_PATTERN = 16;

    private const DEFAULT_CONSONANTS = 'bcdfghjklmnprstvyz';

    private const DEFAULT_VOWELS = 'aeiou';

    /** @var list<array{sets:list<string>,start:int,count:int}> */
    private array $buckets = [];

    private int $space = 0;

    private int $count;

    private int $mulA = 1;

    private int $addB = 0;

    /** @param list<string> $patterns */
    public function __construct(
        array $tlds,
        private readonly array $patterns,
        string $consonants = '',
        string $vowels = '',
        private readonly int $limit = 0,
        private readonly bool $shuffle = true,
        int $seed = 1
    ) {
        parent::__construct($tlds);
        $sets = [
            'C' => self::letters($consonants) ?: self::DEFAULT_CONSONANTS,
            'V' => self::letters($vowels) ?: self::DEFAULT_VOWELS,
            'L' => 'abcdefghijklmnopqrstuvwxyz',
            'D' => '0123456789',
            'A' => 'abcdefghijklmnopqrstuvwxyz0123456789',
        ];
        foreach ($patterns as $pattern) {
            $tokens = [];
            $count = 1;
            foreach (str_split($pattern) as $char) {
                if (isset($sets[$char])) {
                    $tokens[] = $sets[$char];
                    $count *= strlen($sets[$char]);
                } elseif (preg_match('/^[a-z0-9-]$/', $char)) {
                    $tokens[] = $char;
                } else {
                    throw ValidationException::field('patterns', sprintf('Kalıpta geçersiz karakter: "%s" (%s). Kullanılabilir: C V L D A ve küçük harf/rakam/tire.', $char, $pattern));
                }
            }
            $this->buckets[] = ['sets' => $tokens, 'start' => $this->space, 'count' => $count];
            $this->space += $count;
        }
        $this->count = $limit > 0 ? min($limit, $this->space) : $this->space;
        if ($shuffle && $this->space > 1) {
            $this->mulA = self::coprimeNear($this->space, (int) round($this->space * 0.6180339887) + abs($seed) % 97);
            $this->addB = abs($seed * 7919) % $this->space;
        }
    }

    public function labelCount(): int
    {
        return $this->count;
    }

    /** Kalıpların toplam uzayı (örneklemden önce). */
    public function space(): int
    {
        return $this->space;
    }

    protected function labelAt(int $index): string
    {
        $n = $this->shuffle && $this->space > 1 ? ($this->mulA * $index + $this->addB) % $this->space : $index;
        foreach ($this->buckets as $bucket) {
            if ($n < $bucket['start'] + $bucket['count']) {
                $rest = $n - $bucket['start'];
                $chars = [];
                for ($i = count($bucket['sets']) - 1; $i >= 0; $i--) {
                    $set = $bucket['sets'][$i];
                    $size = strlen($set);
                    if ($size === 1) {
                        $chars[$i] = $set;

                        continue;
                    }
                    $chars[$i] = $set[$rest % $size];
                    $rest = intdiv($rest, $size);
                }
                ksort($chars);

                return implode('', $chars);
            }
        }

        return '';
    }

    public function describe(): string
    {
        $sample = $this->count < $this->space ? sprintf(' · %s adlık örneklem', Text::number($this->count)) : '';

        return sprintf('%s · kalıp %s%s', $this->tldText(), implode(', ', $this->patterns), $sample);
    }

    private static function letters(string $value): string
    {
        return implode('', array_unique(str_split(preg_replace('/[^a-z]/', '', strtolower($value)) ?: '')));
    }

    /** `$near`'a en yakın, `$n` ile aralarında asal sayı (permütasyon çarpanı). */
    private static function coprimeNear(int $n, int $near): int
    {
        $near = max(1, $near % $n);
        for ($d = 0; $d < $n; $d++) {
            foreach ([$near + $d, $near - $d] as $candidate) {
                if ($candidate > 0 && $candidate < $n && self::gcd($candidate, $n) === 1) {
                    return $candidate;
                }
            }
        }

        return 1;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
