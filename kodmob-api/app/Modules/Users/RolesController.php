<?php

namespace App\Modules\Users;

use App\Core\Activity\Activity;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Security\Capability;
use App\Core\Security\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Roller ve izinler (yalnızca Yönetici: `km_manage_roles`):
 *
 *   GET    /roles                   roller (kullanıcı sayılarıyla) + yetki grupları
 *   POST   /roles                   { label, description, copy_from? | caps? } yeni rol
 *   PUT    /roles/{slug}            { label, description } ad ve açıklama
 *   PUT    /roles/{slug}/caps       { caps: [] | null } işlem yetkileri (null: varsayılana dön)
 *   PUT    /roles/{slug}/menu       { groups: [] | null } yan menü (null: varsayılan menü)
 *   DELETE /roles/{slug}            kullanıcısı olmayan eklenmiş rolü siler
 *
 * Panelde "Sayfalar" sekmesi sayfaların gerektirdiği yetkileri, "İşlem
 * yetkileri" sekmesi bütün yetkileri gösterir; ikisi de `caps`'i değiştirir.
 */
final class RolesController extends Controller
{
    public function index(): array
    {
        $counts = DB::table('users')->selectRaw('role, status, COUNT(*) AS n')->groupBy('role', 'status')->get();
        $users = [];
        foreach ($counts as $c) {
            $users[$c->role][$c->status ?: 'active'] = (int) $c->n;
        }
        $groups = [];
        foreach (Capability::all() as $key => $cap) {
            $groups[$cap['group']][] = ['key' => $key, 'label' => $cap['label'], 'locked' => in_array($key, Capability::LOCKED, true)];
        }

        return [
            'roles' => array_values(array_map(static fn (array $r) => $r + [
                'users' => ($users[$r['slug']]['active'] ?? 0),
                'passive_users' => ($users[$r['slug']]['passive'] ?? 0),
            ], Roles::all())),
            'capabilities' => array_map(static fn ($label, $items) => ['label' => $label, 'items' => $items], array_keys($groups), array_values($groups)),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $role = Roles::create($this->input($request));
        Activity::type('roles.created');
        Activity::subject('role', 0, $role['label']);
        Activity::with(['slug' => $role['slug'], 'fields' => []]);

        return $this->created($role);
    }

    public function update(Request $request, string $slug): array
    {
        $role = Roles::rename($slug, $this->input($request));
        Activity::type('roles.renamed');
        Activity::subject('role', 0, $role['label']);
        Activity::with(['slug' => $slug, 'fields' => []]);

        return $role;
    }

    public function caps(Request $request, string $slug): array
    {
        $input = $this->input($request);
        if (! array_key_exists('caps', $input) || ($input['caps'] !== null && ! is_array($input['caps']))) {
            throw ValidationException::field('caps', 'Yetki listesi gerekli.');
        }
        $before = Roles::get($slug)['caps'] ?? [];
        $role = Roles::setCaps($slug, $input['caps']);
        $labels = Capability::all();
        $name = static fn (array $keys) => array_values(array_map(static fn ($k) => $labels[$k]['label'] ?? $k, $keys));
        Activity::type('roles.updated');
        Activity::subject('role', 0, $role['label']);
        Activity::with([
            'slug' => $slug,
            'added' => $name(array_diff($role['caps'], $before)),
            'removed' => $name(array_diff($before, $role['caps'])),
            'reset' => $input['caps'] === null,
            'fields' => [],
        ]);

        return $role;
    }

    public function menu(Request $request, string $slug): array
    {
        $input = $this->input($request);
        $groups = $input['groups'] ?? null;
        if ($groups !== null && ! is_array($groups)) {
            throw ValidationException::field('groups', 'Menü grupları gerekli.');
        }
        $role = Roles::setMenu($slug, $groups);
        Activity::type('roles.menu_updated');
        Activity::subject('role', 0, $role['label']);
        Activity::with(['slug' => $slug, 'reset' => $groups === null, 'fields' => []]);

        return $role;
    }

    public function destroy(string $slug): Response
    {
        $label = Roles::get($slug)['label'] ?? $slug;
        Roles::delete($slug);
        Activity::type('roles.deleted');
        Activity::subject('role', 0, $label);
        Activity::with(['slug' => $slug]);

        return $this->noContent();
    }
}
