<?php

namespace App\Core\Search;

/**
 * Bir modülün panel aramasına katkısı (talepler, kullanıcılar, yönlendirmeler…). Modül
 * `search()` ile verir (Core\Module); içerik türleri kayıttan kendiliğinden gelir (ContentSearch).
 */
interface SearchProvider
{
    /** Sonuç grubunun anahtarı: `briefs`. */
    public function key(): string;

    /** Grup başlığı: `Talepler`. */
    public function label(): string;

    /** Panel ikonu (tv/src/shell/icons.html): `inbox`. */
    public function icon(): string;

    /** Görmek için gereken yetki; `a|b` ise biri yeter. */
    public function capability(): string;

    /** @return list<SearchHit> */
    public function search(string $term, int $limit): array;
}
