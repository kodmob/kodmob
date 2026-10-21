<?php

namespace App\Modules\Domains\Generators;

use App\Modules\Domains\DomainName;

/**
 * Aday ad üreticisi. Bütün adayları bellekte tutmaz: her aday bir sıra
 * numarasından hesaplanır (`labelAt`). Böylece yüz binlerce adlık bir tarama
 * yalnızca bir imleç (`cursor`) saklayarak parça parça ilerler ve kaldığı
 * yerden sürer.
 *
 * Sıra: etiket önce, sonra uzantı — `ab.si`, `ab.com`, `ac.si`, `ac.com` …
 */
abstract class NameGenerator
{
    /** @param list<string> $tlds */
    public function __construct(protected readonly array $tlds) {}

    abstract public function labelCount(): int;

    abstract protected function labelAt(int $index): string;

    /** Panelde ve iş başlığında görünen kısa tarif. */
    abstract public function describe(): string;

    public function total(): int
    {
        return $this->labelCount() * count($this->tlds);
    }

    /** @return list<DomainName> */
    public function slice(int $offset, int $limit): array
    {
        $out = [];
        $perLabel = count($this->tlds);
        $end = min($this->total(), $offset + max(0, $limit));
        for ($i = max(0, $offset); $i < $end; $i++) {
            $out[] = new DomainName($this->labelAt(intdiv($i, $perLabel)), $this->tlds[$i % $perLabel]);
        }

        return $out;
    }

    /** @return list<string> ilk birkaç aday (önizleme) */
    public function preview(int $count = 12): array
    {
        return array_map(static fn (DomainName $d) => $d->fqdn(), $this->slice(0, $count));
    }

    /** @return list<string> */
    public function tlds(): array
    {
        return $this->tlds;
    }

    protected function tldText(): string
    {
        return implode(', ', array_map(static fn ($t) => '.'.$t, $this->tlds));
    }
}
