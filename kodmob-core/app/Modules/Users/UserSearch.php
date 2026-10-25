<?php

namespace App\Modules\Users;

use App\Core\Search\SearchHit;
use App\Core\Search\SearchProvider;
use App\Core\Search\SearchQuery;
use App\Core\Security\Capability;
use App\Core\Security\Roles;
use App\Core\Support\PanelUrl;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/** Panel araması: ad, kullanıcı adı, e-posta ya da telefon. */
final class UserSearch implements SearchProvider
{
    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Kullanıcılar';
    }

    public function icon(): string
    {
        return 'user';
    }

    public function capability(): string
    {
        return Capability::VIEW_USERS;
    }

    public function search(string $term, int $limit): array
    {
        $rows = SearchQuery::anyLike(DB::table('users'), ['display_name', 'username', 'email', 'phone'], $term)
            ->orderBy('display_name')->limit($limit)->get(['id', 'display_name', 'username', 'role', 'status']);

        return $rows->map(static fn ($r) => new SearchHit(
            (int) $r->id,
            (string) $r->display_name,
            $r->username.' · '.(Roles::get((string) $r->role)['label'] ?? $r->role),
            PanelUrl::to('users', ['id' => (int) $r->id]),
            $r->status === User::PASSIVE ? 'Pasif' : '',
        ))->all();
    }
}
