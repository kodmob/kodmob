<?php

namespace App\Modules\Briefs;

use App\Core\Search\SearchHit;
use App\Core\Search\SearchProvider;
use App\Core\Search\SearchQuery;
use App\Core\Security\Capability;
use App\Core\Support\PanelUrl;
use App\Modules\Briefs\Domain\Brief;
use Illuminate\Support\Facades\DB;

/** Panel araması: talep sahibinin adı, e-postası, firması ya da telefonu. */
final class BriefSearch implements SearchProvider
{
    public function key(): string
    {
        return 'briefs';
    }

    public function label(): string
    {
        return 'Talepler';
    }

    public function icon(): string
    {
        return 'inbox';
    }

    public function capability(): string
    {
        return Capability::VIEW_BRIEFS;
    }

    public function search(string $term, int $limit): array
    {
        $rows = SearchQuery::anyLike(DB::table('briefs'), ['name', 'email', 'company', 'phone'], $term)
            ->orderByDesc('id')->limit($limit)->get(['id', 'name', 'email', 'company', 'status']);

        return $rows->map(static fn ($r) => new SearchHit(
            (int) $r->id,
            (string) $r->name,
            trim($r->email.($r->company ? ' · '.$r->company : '')),
            PanelUrl::to('briefs', ['id' => (int) $r->id]),
            $r->status === Brief::READ ? '' : (Brief::STATUSES[$r->status] ?? ''),
        ))->all();
    }
}
