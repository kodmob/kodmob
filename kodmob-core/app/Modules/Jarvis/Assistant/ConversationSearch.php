<?php

namespace App\Modules\Jarvis\Assistant;

use App\Core\Search\SearchHit;
use App\Core\Search\SearchProvider;
use App\Core\Search\SearchQuery;
use App\Core\Security\Capability;
use App\Core\Support\PanelUrl;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Panel araması: kişinin kendi Jarvis sohbetlerinin başlığı. */
final class ConversationSearch implements SearchProvider
{
    public function key(): string
    {
        return 'jarvis';
    }

    public function label(): string
    {
        return 'Jarvis sohbetleri';
    }

    public function icon(): string
    {
        return 'sparkles';
    }

    public function capability(): string
    {
        return Capability::USE_JARVIS;
    }

    public function search(string $term, int $limit): array
    {
        $rows = SearchQuery::anyLike(DB::table('jarvis_conversations')->where('user_id', (int) Auth::id()), ['title'], $term)
            ->orderByDesc('updated_at')->limit($limit)->get(['id', 'title', 'updated_at']);

        return $rows->map(static fn ($r) => new SearchHit(
            (int) $r->id, (string) $r->title, 'Sohbet · '.substr((string) $r->updated_at, 0, 10), PanelUrl::to('jarvis', ['id' => (int) $r->id]),
        ))->all();
    }
}
