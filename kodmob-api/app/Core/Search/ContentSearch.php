<?php

namespace App\Core\Search;

use App\Core\Content\EditableContent;
use App\Core\Support\PanelUrl;

/** Bir içerik türünün kayıtları (Types::editable()); sonuç düzenleyiciyi açar. */
final class ContentSearch implements SearchProvider
{
    public function __construct(private readonly EditableContent&Searchable $type, private readonly string $icon) {}

    public function key(): string
    {
        return $this->type->name();
    }

    public function label(): string
    {
        return $this->type->labels()[0];
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function capability(): string
    {
        return $this->type->capability();
    }

    public function search(string $term, int $limit): array
    {
        return array_map(fn (array $r) => new SearchHit(
            $r['id'], $r['title'], $r['subtitle'], PanelUrl::to('editor', ['type' => $this->type->name(), 'id' => $r['id']]), $r['badge'],
        ), $this->type->searchRecords($term, $limit));
    }
}
