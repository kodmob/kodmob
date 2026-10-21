<?php

namespace App\Modules\Domains\Generators;

use App\Core\Support\Text;
use App\Modules\Domains\DomainName;

/**
 * Açık liste: kullanıcının yapıştırdığı adlar (çoklu deneme) ya da domain
 * hilesi (domain hack) adayları. Uzantısız satır her seçili uzantıyla,
 * uzantılı satır (`kodmob.si`) yalnızca kendisiyle denenir.
 *
 * Domain hilesi (`hack`): uzantıyla biten kelime, uzantı adın parçası olacak
 * şekilde bölünür — `kahvesi` + `.si` → `kahve.si`, `bonsai` + `.ai` → `bons.ai`.
 */
final class ListGenerator extends NameGenerator
{
    /** @var list<DomainName> */
    private array $domains = [];

    /** @param list<string> $lines */
    public function __construct(array $tlds, array $lines, private readonly bool $hack = false, private readonly string $label = '')
    {
        parent::__construct($tlds);
        $seen = [];
        foreach ($lines as $line) {
            $candidates = $hack ? self::hacks($line, $tlds) : DomainName::parse($line, $tlds);
            foreach ($candidates as $domain) {
                $key = $domain->fqdn();
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $this->domains[] = $domain;
                }
            }
        }
    }

    /** @return list<DomainName> */
    private static function hacks(string $word, array $tlds): array
    {
        $word = str_replace('.', '', DomainName::ascii($word));
        $out = [];
        foreach ($tlds as $tld) {
            if (! str_contains($tld, '.') && strlen($word) > strlen($tld) && str_ends_with($word, $tld)) {
                $out[] = new DomainName(substr($word, 0, -strlen($tld)), $tld);
            }
        }

        return $out;
    }

    public function labelCount(): int
    {
        return count($this->domains);
    }

    public function total(): int
    {
        return count($this->domains);
    }

    public function slice(int $offset, int $limit): array
    {
        return array_slice($this->domains, max(0, $offset), max(0, $limit));
    }

    protected function labelAt(int $index): string
    {
        return $this->domains[$index]->label ?? '';
    }

    public function describe(): string
    {
        $tlds = array_values(array_unique(array_map(static fn (DomainName $d) => '.'.$d->tld, $this->domains)));

        return sprintf('%s%s · %s ad%s', implode(', ', array_slice($tlds, 0, 4)), count($tlds) > 4 ? '…' : '', Text::number(count($this->domains)), $this->hack ? ' · domain hilesi' : ($this->label !== '' ? ' · '.$this->label : ''));
    }
}
