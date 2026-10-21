<?php

namespace App\Modules\Users;

use App\Core\Database\TableRepository;
use App\Core\Http\ListQuery;
use App\Core\Http\ListResult;
use App\Core\Security\Roles;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Kullanıcılar listesi (ortak tablo): arama ad / kullanıcı adı / e-posta /
 * telefon; sekmeler `status` (aktif / pasif), süzgeç `role`.
 */
final class UserRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'users';
    }

    protected function entityClass(): string
    {
        return UserRow::class;
    }

    protected function sortable(): array
    {
        return [
            'id' => 't.id',
            'display_name' => 't.display_name',
            'username' => 't.username',
            'role' => 't.role',
            'last_login_at' => 't.last_login_at',
            'created_at' => 't.created_at',
        ];
    }

    protected function defaultSort(): string
    {
        return 'display_name';
    }

    protected function filterable(): array
    {
        return [
            'status' => ['column' => 't.status', 'type' => 'in'],
            'role' => ['column' => 't.role', 'type' => 'in'],
        ];
    }

    protected function searchable(): array
    {
        return ['t.display_name', 't.username', 't.email', 't.phone'];
    }

    protected function facetable(): array
    {
        return ['status', 'role'];
    }

    protected function listRow(array $row): array
    {
        return $row;
    }

    /** Sayfa; oturum sayıları tek sorguda eklenir. */
    public function page(ListQuery $query, int $currentUserId): ListResult
    {
        $result = $this->paginate($query);
        $ids = array_map(static fn ($r) => (int) $r['id'], $result->items);
        $sessions = $ids ? DB::table('refresh_tokens')->whereIn('user_id', $ids)->where('expires_at', '>', Clock::now())
            ->selectRaw('user_id, COUNT(*) AS n, MAX(last_seen_at) AS seen')->groupBy('user_id')->get()->keyBy('user_id') : collect();

        return $result->map(static fn (array $row) => self::present($row, $currentUserId, $sessions[$row['id']] ?? null));
    }

    public static function present(array $row, int $currentUserId, ?object $sessions = null): array
    {
        $role = Roles::get((string) $row['role']);

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'display_name' => (string) $row['display_name'],
            'email' => (string) $row['email'],
            'phone' => (string) ($row['phone'] ?? ''),
            'role' => (string) $row['role'],
            'role_label' => $role['label'] ?? (string) $row['role'],
            'status' => (string) ($row['status'] ?: 'active'),
            'last_login_at' => Clock::local($row['last_login_at'] ?? null),
            'last_seen_at' => $sessions?->seen ? Clock::local((string) $sessions->seen) : null,
            'sessions' => (int) ($sessions->n ?? 0),
            'password_changed_at' => Clock::local($row['password_changed_at'] ?? null),
            'two_factor' => ! empty($row['two_factor_confirmed_at']),
            'deactivated_at' => Clock::local($row['deactivated_at'] ?? null),
            'created_at' => Clock::local($row['created_at'] ?? null),
            'avatar_url' => self::avatar((string) $row['email']),
            'is_self' => (int) $row['id'] === $currentUserId,
        ];
    }

    public static function avatar(string $email): string
    {
        return 'https://www.gravatar.com/avatar/'.md5(strtolower(trim($email))).'?s=96&d=mp';
    }
}
