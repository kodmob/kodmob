<?php

namespace App\Modules\Domains\Generators;

/**
 * Kısa adların tam taraması: verilen uzunluklardaki bütün kombinasyonlar
 * (`aa`, `ab` … `zz`, `aaa` …). İsteğe bağlı sabit ön ek / son ek.
 *
 *     new ShortGenerator(['si'], [2, 3], 'letters')   // 676 + 17.576 ad
 */
final class ShortGenerator extends NameGenerator
{
    public const CHARSETS = [
        'letters' => 'abcdefghijklmnopqrstuvwxyz',
        'alnum' => 'abcdefghijklmnopqrstuvwxyz0123456789',
        'digits' => '0123456789',
        'consonants' => 'bcdfghjklmnpqrstvwxyz',
    ];

    private const LABELS = ['letters' => 'harf', 'alnum' => 'harf + rakam', 'digits' => 'rakam', 'consonants' => 'ünsüz'];

    private string $alphabet;

    /** @var list<array{length:int,start:int,count:int}> */
    private array $buckets = [];

    private int $count = 0;

    /** @param list<int> $lengths */
    public function __construct(array $tlds, private readonly array $lengths, private readonly string $charset = 'letters', private readonly string $prefix = '', private readonly string $suffix = '')
    {
        parent::__construct($tlds);
        $this->alphabet = self::CHARSETS[$charset] ?? self::CHARSETS['letters'];
        $base = strlen($this->alphabet);
        foreach ($lengths as $length) {
            $count = $base ** $length;
            $this->buckets[] = ['length' => $length, 'start' => $this->count, 'count' => $count];
            $this->count += $count;
        }
    }

    public function labelCount(): int
    {
        return $this->count;
    }

    protected function labelAt(int $index): string
    {
        $base = strlen($this->alphabet);
        foreach ($this->buckets as $bucket) {
            if ($index < $bucket['start'] + $bucket['count']) {
                $n = $index - $bucket['start'];
                $chars = '';
                for ($i = 0; $i < $bucket['length']; $i++) {
                    $chars = $this->alphabet[$n % $base].$chars;
                    $n = intdiv($n, $base);
                }

                return $this->prefix.$chars.$this->suffix;
            }
        }

        return '';
    }

    public function describe(): string
    {
        $lengths = count($this->lengths) > 1 && max($this->lengths) - min($this->lengths) === count($this->lengths) - 1
            ? min($this->lengths).'–'.max($this->lengths)
            : implode(', ', $this->lengths);
        $affix = ($this->prefix !== '' ? ' · ön ek "'.$this->prefix.'"' : '').($this->suffix !== '' ? ' · son ek "'.$this->suffix.'"' : '');

        return sprintf('%s · %s karakter (%s)%s', $this->tldText(), $lengths, self::LABELS[$this->charset] ?? $this->charset, $affix);
    }
}
