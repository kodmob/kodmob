<?php

namespace App\Modules\History;

use App\Core\Activity\EventGroups;
use App\Core\Exceptions\DomainException;
use App\Core\Security\Capability;
use App\Modules\Auth\Models\User;

/**
 * İşlem Geçmişi görünürlüğü:
 *
 * - `mine` (İşlemlerim, varsayılan): yalnızca kullanıcının kendi işlemleri. Herkes görür.
 * - `all` (Tüm İşlemler): `km_view_all_history` yetkisi olan herkesin bütün işlemlerini;
 *   diğerleri yetkilerinin ilgili olduğu grupların (EventGroups::DEPARTMENTS)
 *   işlemlerini ve kendi işlemlerini görür.
 */
final class HistoryScope
{
    public const MINE = 'mine';

    public const ALL = 'all';

    private function __construct(
        public readonly string $scope,
        public readonly int $userId,
        /** null: sınırsız; boş dizi: yalnızca kendi işlemleri. */
        public readonly ?array $groups,
    ) {}

    public static function for(User $user, string $scope): self
    {
        if ($scope !== self::ALL) {
            return new self(self::MINE, (int) $user->id, []);
        }

        return new self(self::ALL, (int) $user->id, $user->can(Capability::VIEW_ALL_HISTORY) ? null : EventGroups::forCaps($user->capabilities()));
    }

    /** @return array{0:string,1:array}|null SQL koşulu ve değerleri; sınırsızsa null */
    public function sql(string $alias = 't'): ?array
    {
        if ($this->scope === self::MINE) {
            return ["{$alias}.user_id = ?", [$this->userId]];
        }
        if ($this->groups === null) {
            return null;
        }
        if (! $this->groups) {
            return ["{$alias}.user_id = ?", [$this->userId]];
        }

        return ["({$alias}.user_id = ? OR {$alias}.group_key IN (".implode(',', array_fill(0, count($this->groups), '?')).'))', array_merge([$this->userId], $this->groups)];
    }

    public function allows(array $row): bool
    {
        if ((int) ($row['user_id'] ?? 0) === $this->userId || ($this->scope === self::ALL && $this->groups === null)) {
            return true;
        }

        return $this->scope === self::ALL && in_array((string) ($row['group_key'] ?? ''), $this->groups ?? [], true);
    }

    public function assertAllows(array $row): void
    {
        if (! $this->allows($row)) {
            throw new DomainException('km_forbidden', 'Bu işlemi görme yetkiniz yok.', 403);
        }
    }

    public function toArray(): array
    {
        return ['key' => $this->scope, 'groups' => $this->groups];
    }
}
