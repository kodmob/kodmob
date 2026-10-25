<?php

namespace App\Core\Http;

use JsonSerializable;

/**
 * Liste yanıtı: `{ items, total, totals, facets, page, per_page, pages }`.
 *
 * - `totals`: filtreye uyan **bütün** kayıtların toplamları (yalnızca bu sayfanın değil).
 * - `facets`: sekme sayıları, ör. `{ status: { new: 12, read: 3 } }`. Sayılar o
 *   sekmenin kendi filtresi hariç tutularak hesaplanır; bir sekme seçiliyken
 *   diğer sekmelerin sayısı da görünür.
 */
final class ListResult implements JsonSerializable
{
    /**
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,int|float>  $totals
     * @param  array<string,array<string,int>>  $facets
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly ListQuery $query,
        public readonly array $totals = [],
        public readonly array $facets = []
    ) {}

    /** Öğeleri dönüştürür (ör. Entity → API dizisi), sayılar aynı kalır. */
    public function map(callable $fn): self
    {
        return new self(array_map($fn, $this->items), $this->total, $this->query, $this->totals, $this->facets);
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'total' => $this->total,
            'totals' => (object) $this->totals,
            'facets' => (object) $this->facets,
            'page' => $this->query->page,
            'per_page' => $this->query->perPage,
            'pages' => (int) ceil($this->total / max(1, $this->query->perPage)),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
