<?php

namespace App\Modules\History;

use App\Core\Activity\EventGroups;
use App\Core\Http\Controller;
use App\Core\Http\ListQuery;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;

/**
 * İşlem Geçmişi uçları (panele giren herkes; ne göreceği `scope` ile):
 *
 *   GET /history?scope=mine|all&group=&user_id=&created_at_from=&created_at_to=&q=   sayfalı liste (kmGrid)
 *   GET /history/stats?scope=&created_at_from=&created_at_to=                         göstergeler
 *   GET /history/options                                                              gruplar ve kullanıcılar
 *   GET /history/{id}                                                                 detay + aynı kaydın diğer işlemleri
 */
final class HistoryController extends Controller
{
    public function index(Request $request): array
    {
        $scope = $this->scope($request);
        $result = (new HistoryRepository)->scoped($scope)->page(ListQuery::fromRequest($request, 50));

        return $result->toArray() + ['scope' => $scope->toArray()];
    }

    public function stats(Request $request): array
    {
        return (new HistoryRepository)->scoped($this->scope($request))->stats(
            $request->query('created_at_from'),
            $request->query('created_at_to'),
        );
    }

    public function options(Request $request): array
    {
        $all = HistoryScope::for($request->user(), HistoryScope::ALL);

        return [
            // Tüm İşlemler sekmesi: herkesin (groups = null) ya da bölümünün işlemleri; boşsa sekme gösterilmez.
            'all' => ['available' => $all->groups === null || $all->groups !== [], 'groups' => $all->groups],
            'groups' => EventGroups::options(),
            'users' => User::query()->orderBy('display_name')->get(['id', 'display_name', 'username'])
                ->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->display_name ?: $u->username])->all(),
        ];
    }

    public function show(Request $request, int $id): array
    {
        return (new HistoryRepository)->scoped($this->scope($request, HistoryScope::ALL))->detail($id);
    }

    private function scope(Request $request, ?string $default = null): HistoryScope
    {
        return HistoryScope::for($request->user(), (string) $request->query('scope', $default ?? HistoryScope::MINE));
    }
}
