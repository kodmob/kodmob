<?php

namespace App\Core\Search;

/**
 * Panel aramasında tek sonuç. `url` panel adresidir (PanelUrl::to); tıklanınca oraya gidilir.
 * `badge`: kısa durum (Taslak, Pasif, Yeni…), yoksa boş.
 */
final class SearchHit
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $url,
        public readonly string $badge = '',
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'subtitle' => $this->subtitle, 'url' => $this->url, 'badge' => $this->badge];
    }
}
