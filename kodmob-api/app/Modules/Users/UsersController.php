<?php

namespace App\Modules\Users;

use App\Core\Activity\Activity;
use App\Core\Exceptions\NotFoundException;
use App\Core\Http\Controller;
use App\Core\Http\ListQuery;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Security\Roles;
use App\Core\Sms\SmsSettings;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\PasswordPolicy;
use App\Modules\Auth\Services\Sessions;
use App\Modules\Auth\Services\TwoFactor;
use App\Modules\History\HistoryRepository;
use App\Modules\History\HistoryScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kullanıcılar (ortak çoklu kullanıcı yönetimi):
 *
 *   GET  /users?q=&status=&role=&sort=     liste (ortak tablo)            km_view_users
 *   GET  /users/options                    roller, durumlar, şifre kuralı  km_view_users
 *   GET  /users/{id}                       detay + oturumlar + son işlemler km_view_users
 *   POST /users                            ekle                            km_manage_users
 *   PUT  /users/{id}                       düzenle (şifre isteğe bağlı)     km_manage_users
 *   POST /users/{id}/deactivate|activate   pasife al / aktifleştir          km_manage_users
 *   POST /users/{id}/sessions/end          bütün oturumlarını kapat         km_manage_users
 *   POST /users/sms, /users/mail           SMS / e-posta gönder (MessageController) km_message_users
 */
final class UsersController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): array
    {
        return (new UserRepository)->page(ListQuery::fromRequest($request, 25), (int) $request->user()->id)->toArray();
    }

    public function options(): array
    {
        return [
            'roles' => array_values(array_map(static fn ($r) => ['value' => $r['slug'], 'label' => $r['label'], 'description' => $r['description']], Roles::all())),
            'statuses' => [['value' => User::ACTIVE, 'label' => 'Aktif'], ['value' => User::PASSIVE, 'label' => 'Pasif']],
            'password_min' => PasswordPolicy::minLength(),
            // Kullanıcılar sayfasındaki SMS düğmeleri yalnızca SMS ayarlıyken görünür (Ayarlar > SMS).
            'messaging' => ['sms' => Modules::enabled('sms') && SmsSettings::enabled(), 'mail' => true],
        ];
    }

    public function show(Request $request, int $id): array
    {
        $user = $this->find($id);
        $actor = $request->user();
        $history = $actor->can(Capability::VIEW_ALL_HISTORY) || $actor->id === $user->id
            ? (new HistoryRepository)->scoped(HistoryScope::for($actor, HistoryScope::ALL))
                ->page(new ListQuery(1, 15, 'created_at', 'desc', '', ['user_id' => (string) $user->id]))->items
            : [];

        return $this->present($user, $actor) + [
            'role_caps' => Roles::capsOf((string) $user->role),
            'session_list' => $actor->can(Capability::MANAGE_USERS) || $actor->id === $user->id ? Sessions::list((int) $user->id) : [],
            'history' => $history,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->users->create($this->input($request), $request->user());
        Activity::type('users.created');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);
        Activity::with(['role' => Roles::get($user->role)['label'] ?? $user->role, 'fields' => []]);

        return $this->created($this->present($user, $request->user()));
    }

    public function update(Request $request, int $id): array
    {
        $user = $this->find($id);
        $changes = $this->users->update($user, $this->input($request), $request->user());
        Activity::type('users.updated');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);
        Activity::with(['changes' => $changes, 'fields' => []]);
        if (! $changes) {
            Activity::skip();
        }

        return $this->present($user->refresh(), $request->user());
    }

    public function deactivate(Request $request, int $id): array
    {
        $user = $this->find($id);
        $ended = $this->users->deactivate($user, $request->user());
        Activity::type('users.deactivated');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);
        Activity::with(['sessions' => $ended]);

        return $this->present($user->refresh(), $request->user());
    }

    public function activate(Request $request, int $id): array
    {
        $user = $this->find($id);
        $this->users->activate($user, $request->user());
        Activity::type('users.activated');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);

        return $this->present($user->refresh(), $request->user());
    }

    public function endSessions(Request $request, int $id): array
    {
        $user = $this->find($id);
        $this->users->assertCanManage($request->user(), $user);
        $sid = (string) ($request->attributes->get('jwt')['sid'] ?? '');
        $count = Sessions::endAll((int) $user->id, $user->id === $request->user()->id ? $sid : '');
        Activity::type('users.sessions_ended');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);
        Activity::with(['count' => $count]);

        return ['ended' => $count];
    }

    /**
     * İki adımlı doğrulamayı sıfırlar (telefonu kaybolan kullanıcı): kapanır, oturumları kapatılır;
     * kullanıcı şifresiyle girip Profilim'den yeniden açar.
     */
    public function resetTwoFactor(Request $request, int $id): array
    {
        $user = $this->find($id);
        $this->users->assertCanManage($request->user(), $user);
        TwoFactor::disable($user);
        $ended = Sessions::endAll((int) $user->id, $user->id === $request->user()->id ? (string) ($request->attributes->get('jwt')['sid'] ?? '') : '');
        Activity::type('users.two_factor_reset');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);
        Activity::with(['sessions' => $ended]);

        return $this->present($user->refresh(), $request->user());
    }

    private function find(int $id): User
    {
        return User::query()->find($id) ?? throw NotFoundException::message('Kullanıcı bulunamadı.');
    }

    private function present(User $user, User $actor): array
    {
        $row = $user->getAttributes();
        foreach (['last_login_at', 'password_changed_at', 'deactivated_at', 'created_at'] as $key) {
            $row[$key] = $user->getRawOriginal($key);
        }

        return UserRepository::present($row, (int) $actor->id, (object) ['n' => Sessions::count((int) $user->id), 'seen' => null]);
    }
}
