<?php

namespace App\Modules\Seo\Services;

use App\Core\Search\SearchHit;
use App\Core\Search\SearchProvider;
use App\Core\Search\SearchQuery;
use App\Core\Security\Capability;
use App\Core\Support\PanelUrl;
use Illuminate\Support\Facades\DB;

/** Panel araması: yönlendirmenin kaynağı, hedefi ya da notu. Sonuç Yönlendirmeler'i o adresle süzer. */
final class RedirectSearch implements SearchProvider
{
    public function key(): string
    {
        return 'redirects';
    }

    public function label(): string
    {
        return 'Yönlendirmeler';
    }

    public function icon(): string
    {
        return 'arrow-up-right';
    }

    public function capability(): string
    {
        return Capability::MANAGE_SEO;
    }

    public function search(string $term, int $limit): array
    {
        $rows = SearchQuery::anyLike(DB::table('seo_redirects'), ['source', 'target', 'note'], $term)
            ->orderByDesc('id')->limit($limit)->get(['id', 'source', 'target', 'code', 'active']);

        return $rows->map(static fn ($r) => new SearchHit(
            (int) $r->id,
            (string) $r->source,
            $r->code.($r->target !== '' ? ' → '.$r->target : ''),
            PanelUrl::to('seo', ['tab' => 'redirects'], ['q' => (string) $r->source]),
            $r->active ? '' : 'Kapalı',
        ))->all();
    }
}
