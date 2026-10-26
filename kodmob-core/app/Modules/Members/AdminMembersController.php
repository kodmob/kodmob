<?php

namespace App\Modules\Members;

use App\Core\Activity\Activity;
use App\Core\Database\Paged;
use App\Core\Database\Sql;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel › Gelenler › Üyeler (kişisel veri; yalnızca `km_view_members`):
 *
 *   GET    /admin/members?search=&status=&page=   { items, total, page, pages }
 *   PUT    /admin/members/{id}   { status: active | disabled }   (km_manage_members)
 *   DELETE /admin/members/{id}                                  (km_manage_members; oturumları da)
 */
final class AdminMembersController extends Controller
{
    public function index(Request $request): array
    {
        $query = DB::table('members');
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $pattern = Sql::pattern($search);
            $query->where(static fn ($q) => $q->whereRaw(Sql::like('name'), [$pattern])->orWhereRaw(Sql::like('email'), [$pattern]));
        }
        if (in_array($request->query('status'), ['active', 'disabled'], true)) {
            $query->where('status', $request->query('status'));
        }

        return Paged::of($query->orderByDesc('id'), (int) $request->query('page', 1), 25, static fn ($r) => [
            'id' => (int) $r->id, 'name' => (string) $r->name, 'email' => (string) $r->email, 'status' => (string) $r->status,
            'verified' => $r->email_verified_at !== null, 'google' => $r->google_id !== null,
            'created_at' => Clock::local((string) $r->created_at), 'last_login_at' => Clock::local($r->last_login_at),
        ]);
    }

    public function update(Request $request, int $id): array
    {
        $member = Members::find($id) ?? throw ValidationException::field('id', 'Üye bulunamadı.');
        $status = (string) ($this->input($request)['status'] ?? '');
        if (! in_array($status, ['active', 'disabled'], true)) {
            throw ValidationException::field('status', 'Geçersiz durum.');
        }
        DB::table('members')->where('id', $id)->update(['status' => $status, 'updated_at' => Clock::now()]);
        if ($status === 'disabled') {
            DB::table('member_sessions')->where('member_id', $id)->delete();
        }
        Activity::subject('member', $id, $member['email']);

        return ['ok' => true, 'status' => $status];
    }

    public function destroy(int $id): array
    {
        $member = Members::find($id) ?? throw ValidationException::field('id', 'Üye bulunamadı.');
        Members::purge($id);
        Activity::subject('member', $id, $member['email']);

        return ['ok' => true];
    }
}
