<?php

namespace App\Modules\Domains\Generators;

use App\Core\Support\Text;
use App\Modules\Domains\DomainName;

/**
 * Jenerik adlar: kelime listeleri ve ön ek / son ek birleşimleri.
 *
 * Her kelime için sırasıyla: kelime, her ön ek + kelime, kelime + her son ek
 * (`cloud`, `getcloud`, `cloudhub` …). Türkçe harfler ASCII'ye çevrilir;
 * `maxLength`'i aşan ya da kurala uymayan aday atlanır (taramada "geçersiz"
 * sayılır, sonuçlara yazılmaz).
 */
final class WordGenerator extends NameGenerator
{
    /** @var list<string> */
    private array $words;

    /** @var list<string> */
    private array $variants;

    /**
     * @param  list<string>  $words
     * @param  list<string>  $prefixes
     * @param  list<string>  $suffixes
     */
    public function __construct(array $tlds, array $words, private readonly array $prefixes = [], private readonly array $suffixes = [], private readonly int $maxLength = 0, private readonly string $label = '')
    {
        parent::__construct($tlds);
        $this->words = array_values(array_unique(array_filter(array_map([DomainName::class, 'ascii'], $words))));
        // Varyant: '' (yalın), 'p:<ön ek>', 's:<son ek>'
        $this->variants = array_merge(
            [''],
            array_map(static fn ($p) => 'p:'.$p, $prefixes),
            array_map(static fn ($s) => 's:'.$s, $suffixes)
        );
    }

    public function labelCount(): int
    {
        return count($this->words) * count($this->variants);
    }

    protected function labelAt(int $index): string
    {
        $perWord = count($this->variants);
        $word = $this->words[intdiv($index, $perWord)] ?? '';
        $variant = $this->variants[$index % $perWord];
        $label = match (substr($variant, 0, 2)) {
            'p:' => substr($variant, 2).$word,
            's:' => $word.substr($variant, 2),
            default => $word,
        };

        // Uzun aday "geçersiz" olarak işaretlensin diye bozulur (DomainName kuralı).
        return $this->maxLength > 0 && strlen($label) > $this->maxLength ? '-'.$label : $label;
    }

    public function describe(): string
    {
        $affix = [];
        if ($this->prefixes) {
            $affix[] = count($this->prefixes).' ön ek';
        }
        if ($this->suffixes) {
            $affix[] = count($this->suffixes).' son ek';
        }

        return sprintf('%s · %s%s kelime%s%s', $this->tldText(), $this->label !== '' ? $this->label.' · ' : '', Text::number(count($this->words)), $affix ? ' · '.implode(', ', $affix) : '', $this->maxLength ? ' · en çok '.$this->maxLength.' karakter' : '');
    }
}
