<?php

namespace App\Modules\Auth\Http;

use App\Core\Activity\Activity;
use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Security\Roles;
use App\Core\Sites\SiteRegistry;
use App\Core\Sites\Sites;
use App\Core\Support\Clock;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\PasswordPolicy;
use App\Modules\Auth\Services\Sessions;
use App\Modules\Auth\Services\TwoFactor;
use App\Modules\Auth\Services\UserPreferences;
use App\Modules\I18n\ContentTranslator;
use App\Modules\Security\RequireTwoFactor;
use App\Modules\Seo\Seo;
use App\Modules\Site\ProjectIcon;
use App\Modules\System\PanelLanguage;
use App\Modules\Users\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Oturumdaki kullanıcı (Profilim):
 *
 *   GET    /me                          kullanıcı, rol, yetkiler, rolün yan menüsü
 *   PUT    /me                          { display_name, email, phone }
 *   PUT    /me/password                 { current_password, password, password_confirmation }
 *   GET    /me/sessions                 açık oturumlarım (bu cihaz işaretli)
 *   DELETE /me/sessions/{id}            bir oturumumu kapat
 *   POST   /me/sessions/end-others      bu cihaz dışındakileri kapat
 */
final class ProfileController extends Controller
{
    public function me(Request $request): array
    {
        $user = $request->user();
        $role = Roles::get((string) $user->role);

        return [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'phone' => (string) $user->phone,
            'avatar_url' => UserRepository::avatar((string) $user->email),
            'is_admin' => $user->isAdmin(),
            'role' => ['slug' => (string) $user->role, 'label' => $role['label'] ?? (string) $user->role],
            'capabilities' => $user->capabilities(),
            // Açık isteğe bağlı modüller (Core\Modules); panel yetkiye bakar, bu tanılama ve uyarılar için.
            'modules' => Modules::keys(),
            // Profilim > Yetkilerim: okunur adlarıyla, gruplu.
            'capability_groups' => self::groups($user->capabilities()),
            'menu' => $role['menu'] ?? null,
            'password_changed_at' => Clock::local($user->getRawOriginal('password_changed_at')),
            'password_min' => PasswordPolicy::minLength(),
            // Düzenleyicideki SEO kutusu ve SEO sayfası için.
            'seo' => Seo::state(),
            // Jarvis ile çeviri kullanılabilir mi (Claude anahtarı var mı); panel düğmeleri buna göre.
            'translator' => ContentTranslator::available(),
            // Dil kaydı (panelde içerik dilleri ve adları).
            // `live`: sitede şu an yayında (tek dilli modda yalnızca varsayılan dil).
            'languages' => array_map(static fn (array $l) => array_intersect_key($l, array_flip(['code', 'name', 'native', 'short', 'active', 'default', 'dir', 'flag'])) + ['live' => in_array($l['code'], Locales::active(), true)], Languages::all()),
            // Çok dilli panel: kişinin dili (yoksa Türkçe) ve kurulu diller (Profilim'deki seçim).
            'panel_language' => PanelLanguage::exists($pl = (string) UserPreferences::get($user, 'panel_language')) ? ($pl ?: 'tr') : 'tr',
            'panel_languages' => PanelLanguage::choices(),
            'version' => (string) config('kodmob.version'),
            // Proje seçici (menünün üstü): bu proje; ana projede, projeleri yöneten kişiye diğerleri.
            'project' => ['key' => Sites::key(), 'name' => SiteManifest::siteName(), 'main' => Sites::isMain(), 'icon' => ($v = ProjectIcon::version()) !== null ? '/api/v1/icon/32.png?v='.$v : null],
            'projects' => self::projects($request, $user),
            // İki adımlı doğrulama (Profilim): açık mı, kalan yedek kod.
            'two_factor' => ['enabled' => TwoFactor::enabled($user), 'recovery_left' => TwoFactor::enabled($user) ? TwoFactor::remainingRecoveryCodes($user) : 0, 'required' => RequireTwoFactor::pending($user)],
        ];
    }

    /**
     * Proje seçicinin listesi: yalnızca ana projede ve projeleri (alt siteler) yönetebilene. Panel adresi
     * SiteRegistry::panelUrl. Geçiş tek oturumla (Handoff; hedefte aynı e-postalı yönetici varsa).
     *
     * @return list<array{key:string,name:string,panel:string}>
     */
    private static function projects(Request $request, User $user): array
    {
        if (! Sites::isMain() || ! in_array(Capability::MANAGE_SITES, $user->capabilities(), true)) {
            return [];
        }
        $out = [];
        foreach (SiteRegistry::list(false) as $site) {
            $panel = SiteRegistry::panelUrl($site, $request->getScheme(), (int) $request->getPort());
            if ($panel !== '') {
                $out[] = ['key' => (string) $site['key'], 'name' => (string) $site['name'], 'panel' => $panel];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ iki adımlı doğrulama

    /** 1. adım (şifre onayıyla): yeni anahtar; uygulamaya QR ya da elle eklenir. Henüz açık değil. */
    public function twoFactorSetup(Request $request): array
    {
        $user = $this->confirmPassword($request);
        $secret = TwoFactor::newSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_step = null;
        $user->save();
        Activity::skip();

        return ['secret' => $secret, 'otpauth_url' => TwoFactor::otpauthUrl($user, $secret)];
    }

    /** 2. adım: uygulamadaki kod doğruysa açılır; yedek kodlar bir kez gösterilir. */
    public function twoFactorConfirm(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        if ((string) $user->two_factor_secret === '' || TwoFactor::enabled($user)) {
            throw ValidationException::field('code', 'Önce kurulumu başlatın.');
        }
        if (! TwoFactor::verifyCode($user, (string) ($this->input($request)['code'] ?? ''))) {
            throw ValidationException::field('code', 'Kod hatalı ya da süresi geçmiş; uygulamadaki güncel kodu girin.');
        }
        $user->two_factor_confirmed_at = Clock::now();
        $user->save();
        $codes = TwoFactor::newRecoveryCodes($user);
        Activity::type('profile.two_factor_enabled');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);

        return ['enabled' => true, 'recovery_codes' => $codes];
    }

    /** Şifre onayıyla kapatılır. */
    public function twoFactorDisable(Request $request): array
    {
        $user = $this->confirmPassword($request);
        TwoFactor::disable($user);
        Activity::type('profile.two_factor_disabled');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);

        return ['enabled' => false];
    }

    /** Yeni yedek kodlar (eskiler geçersiz), şifre onayıyla. */
    public function twoFactorRecovery(Request $request): array
    {
        $user = $this->confirmPassword($request);
        if (! TwoFactor::enabled($user)) {
            throw ValidationException::field('password', 'İki adımlı doğrulama kapalı.');
        }
        Activity::type('profile.two_factor_recovery');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);

        return ['recovery_codes' => TwoFactor::newRecoveryCodes($user)];
    }

    private function confirmPassword(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        if (! Hash::check((string) ($this->input($request)['password'] ?? ''), $user->password)) {
            throw ValidationException::field('password', 'Şifreniz hatalı.');
        }

        return $user;
    }

    public function update(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $input = $this->input($request);
        $errors = [];
        $name = mb_substr(Text::field($input['display_name'] ?? $user->display_name), 0, 120);
        if (mb_strlen($name) < 2) {
            $errors['display_name'] = 'Ad en az 2 karakter olmalı.';
        }
        $email = Text::email($input['email'] ?? $user->email);
        if ($email === '') {
            $errors['email'] = 'Geçerli bir e-posta adresi yazın.';
        } elseif (User::query()->where('email', $email)->where('id', '<>', $user->id)->exists()) {
            $errors['email'] = 'Bu e-posta başka bir kullanıcıda kayıtlı.';
        }
        $phone = trim(mb_substr(preg_replace('/[^\d+() \-]/', '', (string) ($input['phone'] ?? $user->phone)), 0, 30));
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        $changes = [];
        foreach (['display_name' => ['Ad', $name], 'email' => ['E-posta', $email], 'phone' => ['Telefon', $phone]] as $key => [$label, $value]) {
            if ((string) $user->{$key} !== $value) {
                $changes[$key] = ['label' => $label, 'from' => (string) $user->{$key}, 'to' => $value];
            }
        }
        $user->fill(['display_name' => $name, 'email' => $email, 'phone' => $phone])->save();
        Activity::type('profile.updated');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);
        Activity::with(['changes' => $changes, 'fields' => []]);
        if (! $changes) {
            Activity::skip();
        }

        return $this->me($request);
    }

    /** Şifre değişir; bu cihaz dışındaki oturumlar kapanır. */
    public function password(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $input = $this->input($request);
        if (! Hash::check((string) ($input['current_password'] ?? ''), $user->password)) {
            throw ValidationException::field('current_password', 'Şu anki şifreniz hatalı.');
        }
        $password = (string) ($input['password'] ?? '');
        PasswordPolicy::assert($password, (string) $user->username);
        if ($password !== (string) ($input['password_confirmation'] ?? '')) {
            throw ValidationException::field('password_confirmation', 'Şifreler aynı değil.');
        }
        if (Hash::check($password, $user->password)) {
            throw ValidationException::field('password', 'Yeni şifre eskisiyle aynı olamaz.');
        }
        $user->password = $password;
        $user->password_changed_at = Clock::now();
        $user->save();
        $ended = Sessions::endAll((int) $user->id, $this->sid($request));
        Activity::type('profile.password_changed');
        Activity::subject('user', (int) $user->id, (string) $user->display_name);
        Activity::with(['sessions' => $ended]);

        return ['changed' => true, 'ended_sessions' => $ended];
    }

    public function sessions(Request $request): array
    {
        return ['items' => Sessions::list((int) $request->user()->id, $this->sid($request))];
    }

    public function endSession(Request $request, int $id): array
    {
        $ended = Sessions::end($id, (int) $request->user()->id);
        Activity::type('sessions.ended');
        Activity::subject('session', $id, $ended['device']);
        Activity::with(['device' => $ended['device'], 'ip' => $ended['ip']]);

        return ['ended' => 1];
    }

    public function endOthers(Request $request): array
    {
        $count = Sessions::endAll((int) $request->user()->id, $this->sid($request));
        Activity::type('sessions.ended_others');
        Activity::subject('user', (int) $request->user()->id, (string) $request->user()->display_name);
        Activity::with(['count' => $count]);

        return ['ended' => $count];
    }

    /** Bu istek hangi oturumdan (cihazdan) geliyor. */
    private function sid(Request $request): string
    {
        return (string) ($request->attributes->get('jwt')['sid'] ?? '');
    }

    /** @param list<string> $caps @return list<array{label:string,items:list<string>}> */
    private static function groups(array $caps): array
    {
        $out = [];
        foreach (Capability::all() as $key => $cap) {
            if (in_array($key, $caps, true)) {
                $out[$cap['group']][] = $cap['label'];
            }
        }

        return array_map(static fn ($label, $items) => ['label' => $label, 'items' => $items], array_keys($out), array_values($out));
    }
}
