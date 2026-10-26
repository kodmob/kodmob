<?php

namespace App\Modules\Members;

use App\Core\Content\Locales;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Mail\SendMail;
use App\Core\Mail\SiteMail;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\Auth\Services\PasswordPolicy;
use App\Modules\Site\Render\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Site üyeliği: kayıt, giriş, oturum (HttpOnly çerez), e-posta doğrulama, şifre yenileme, profil, hesabı silme.
 * Panel kullanıcılarından ayrıdır (tablo `members`, oturum `member_sessions`); panele giremez.
 *
 * - Oturum anahtarı rastgele 48 haneli, yalnızca özeti saklanır; çerez `km_member` yalnızca /api/v1/members altına
 *   gider (SameSite=Lax, HttpOnly, https'te Secure). 30 gün.
 * - Doğrulama ve yenileme anahtarları tek kullanımlık, süreli (24 saat / 1 saat), özetleri saklanır.
 * - Şifre panel kurallarıyla (PasswordPolicy: uzunluk, harf + rakam, sızmış şifre denetimi).
 * - E-postalar sitenin şablonuyla (SiteMail), üyenin dilinde (Türkçe ya da İngilizce metin).
 * - Hesap silinince üye ve oturumları silinir; siparişler yasal saklama için kalır, üyeye bağlı değildir (e-postayla
 *   eşleşir; hesap silinince "Siparişlerim" de gider).
 */
final class Members
{
    public const COOKIE = 'km_member';

    public const SESSION_DAYS = 30;

    // ------------------------------------------------------------ kayıt ve giriş

    /** @return array<string,mixed> üye */
    public static function register(array $input, Request $request): array
    {
        $name = mb_substr(Text::field($input['name'] ?? ''), 0, 120);
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $errors = [];
        // Bot: tuzak alan dolu ya da form 3 saniyeden kısa sürede gönderildi.
        $started = (int) ($input['started_at'] ?? 0);
        if (trim((string) ($input['website'] ?? '')) !== '' || ($started > 0 && (int) (microtime(true) * 1000) - $started < 3000)) {
            throw new DomainException('km_member_rejected', 'Kayıt alınamadı. Sayfayı yenileyip tekrar deneyin.', 422);
        }
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Adınızı yazın.';
        }
        if (! Text::isEmail($email)) {
            $errors['email'] = 'Geçerli bir e-posta adresi yazın.';
        } elseif (DB::table('members')->where('email', $email)->exists()) {
            $errors['email'] = 'Bu e-posta ile zaten üye olunmuş. Giriş yapın ya da şifrenizi yenileyin.';
        }
        if (empty($input['consent'])) {
            $errors['consent'] = 'Üye olmak için onay kutusunu işaretleyin.';
        }
        if (! isset($errors['email'])) {
            try {
                PasswordPolicy::assert($password, $email);
            } catch (ValidationException $e) {
                $errors += (array) ($e->details()['fields'] ?? []);
            }
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        $id = (int) DB::table('members')->insertGetId([
            'name' => $name, 'email' => $email, 'password' => Hash::make($password), 'status' => 'active',
            'locale' => self::locale($input['locale'] ?? ''), 'consent_at' => Clock::now(), 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
        self::sendVerification($id);

        return self::find($id);
    }

    /** @return array<string,mixed> üye */
    public static function login(array $input): array
    {
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $row = DB::table('members')->where('email', $email)->first();
        if (! $row || ! $row->password || ! Hash::check((string) ($input['password'] ?? ''), (string) $row->password)) {
            throw ValidationException::field('password', 'E-posta ya da şifre hatalı.');
        }
        if ($row->status !== 'active') {
            throw new DomainException('km_member_disabled', 'Bu üyelik kullanıma kapalı.', 403);
        }
        DB::table('members')->where('id', $row->id)->update(['last_login_at' => Clock::now()]);

        return self::find((int) $row->id);
    }

    /** Google ile giriş: Google kimliğiyle ya da (doğrulanmış) e-postayla eşleşir; yoksa üye olur. */
    public static function fromGoogle(string $googleId, string $email, string $name, string $locale): array
    {
        $email = mb_strtolower($email);
        $row = DB::table('members')->where('google_id', $googleId)->first() ?? DB::table('members')->where('email', $email)->first();
        if ($row) {
            if ($row->status !== 'active') {
                throw new DomainException('km_member_disabled', 'Bu üyelik kullanıma kapalı.', 403);
            }
            DB::table('members')->where('id', $row->id)->update(array_filter([
                'google_id' => $googleId, 'email_verified_at' => $row->email_verified_at ?: Clock::now(), 'last_login_at' => Clock::now(),
            ]));

            return self::find((int) $row->id);
        }
        $id = (int) DB::table('members')->insertGetId([
            'name' => mb_substr(Text::field($name ?: (string) strtok($email, '@')), 0, 120), 'email' => $email, 'google_id' => $googleId, 'status' => 'active',
            'locale' => self::locale($locale), 'email_verified_at' => Clock::now(), 'consent_at' => Clock::now(), 'last_login_at' => Clock::now(),
            'created_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);

        return self::find($id);
    }

    // ------------------------------------------------------------ oturum

    /** Yeni oturum; çerezi döner. */
    public static function startSession(int $memberId, Request $request): Cookie
    {
        $token = bin2hex(random_bytes(24));
        DB::table('member_sessions')->insert([
            'member_id' => $memberId, 'token_hash' => hash('sha256', $token), 'ip' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'created_at' => Clock::now(),
            'expires_at' => Clock::later('+'.self::SESSION_DAYS.' days'),
        ]);

        return self::cookie($token, $request);
    }

    /** Çerezdeki oturumun üyesi (yoksa ya da süresi dolduysa null). */
    public static function current(Request $request): ?array
    {
        $token = (string) $request->cookie(self::COOKIE, '');
        if (strlen($token) !== 48) {
            return null;
        }
        $session = DB::table('member_sessions')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', Clock::now())->first();
        $member = $session ? self::find((int) $session->member_id) : null;

        return $member && $member['status'] === 'active' ? $member : null;
    }

    public static function endSession(Request $request): Cookie
    {
        $token = (string) $request->cookie(self::COOKIE, '');
        if ($token !== '') {
            DB::table('member_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }

        return self::cookie('', $request, true);
    }

    public static function cookie(string $value, Request $request, bool $forget = false): Cookie
    {
        $expires = $forget ? 1 : Clock::moment()->addDays(self::SESSION_DAYS);

        return new Cookie(self::COOKIE, $value, $expires, '/api/v1/members', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    // ------------------------------------------------------------ doğrulama ve şifre

    public static function sendVerification(int $memberId): void
    {
        $member = self::find($memberId);
        if ($member === null || $member['verified']) {
            return;
        }
        $token = self::token($memberId, 'verify', 24 * 60);
        $en = $member['locale'] === 'en';
        SendMail::to($member['email'], new SiteMail(
            $en ? 'Verify your email address' : 'E-posta adresinizi doğrulayın',
            $en ? ['Hello '.$member['name'].',', 'Please confirm your email address to complete your membership. The link is valid for 24 hours.']
                : ['Merhaba '.$member['name'].',', 'Üyeliğinizi tamamlamak için e-posta adresinizi doğrulayın. Bağlantı 24 saat geçerlidir.'],
            [],
            ['label' => $en ? 'Verify email' : 'E-postamı doğrula', 'url' => self::pageUrl($member['locale'], ['dogrula' => $token])],
            mailLocale: $member['locale'] ?: null,
        ), 'member.verify');
    }

    public static function verify(string $token): array
    {
        $memberId = self::redeem($token, 'verify');
        DB::table('members')->where('id', $memberId)->update(['email_verified_at' => Clock::now(), 'updated_at' => Clock::now()]);

        return self::find($memberId);
    }

    /** Şifre yenileme bağlantısı (adres kayıtlı değilse sessizce bir şey yapmaz: kimin üye olduğu anlaşılmasın). */
    public static function forgot(string $email): void
    {
        $row = DB::table('members')->where('email', mb_strtolower(trim($email)))->where('status', 'active')->first();
        if (! $row) {
            return;
        }
        $token = self::token((int) $row->id, 'reset', 60);
        $en = $row->locale === 'en';
        SendMail::to((string) $row->email, new SiteMail(
            $en ? 'Reset your password' : 'Şifrenizi yenileyin',
            $en ? ['Hello '.$row->name.',', 'Use the button below to set a new password. The link is valid for 1 hour. If you did not ask for this, ignore this email.']
                : ['Merhaba '.$row->name.',', 'Yeni şifre belirlemek için aşağıdaki düğmeyi kullanın. Bağlantı 1 saat geçerlidir. Siz istemediyseniz bu e-postayı yok sayın.'],
            [],
            ['label' => $en ? 'Set new password' : 'Yeni şifre belirle', 'url' => self::pageUrl((string) $row->locale, ['sifirla' => $token])],
            mailLocale: $row->locale ?: null,
        ), 'member.reset');
    }

    public static function reset(string $token, string $password): array
    {
        $hash = hash('sha256', $token);
        $row = DB::table('member_tokens')->where('token_hash', $hash)->where('purpose', 'reset')->where('expires_at', '>', Clock::now())->first();
        if (! $row) {
            throw ValidationException::field('token', 'Bağlantı geçersiz ya da süresi dolmuş. Yeniden isteyin.');
        }
        $member = self::find((int) $row->member_id);
        PasswordPolicy::assert($password, (string) $member['email']);
        self::redeem($token, 'reset');
        // Yenileme bağlantısı e-postaya gittiği için adres de doğrulanmış sayılır.
        DB::table('members')->where('id', $row->member_id)->update(['password' => Hash::make($password), 'updated_at' => Clock::now()]);
        DB::table('members')->where('id', $row->member_id)->whereNull('email_verified_at')->update(['email_verified_at' => Clock::now()]);
        // Bütün oturumlar kapanır (şifre ele geçmiş olabilir).
        DB::table('member_sessions')->where('member_id', $row->member_id)->delete();

        return self::find((int) $row->member_id);
    }

    public static function changePassword(array $member, string $current, string $password): void
    {
        $hash = (string) DB::table('members')->where('id', $member['id'])->value('password');
        if ($hash === '' || ! Hash::check($current, $hash)) {
            throw ValidationException::field('current', 'Şu anki şifre hatalı.');
        }
        PasswordPolicy::assert($password, (string) $member['email']);
        DB::table('members')->where('id', $member['id'])->update(['password' => Hash::make($password), 'updated_at' => Clock::now()]);
    }

    public static function updateProfile(array $member, array $input): array
    {
        $name = mb_substr(Text::field($input['name'] ?? ''), 0, 120);
        if (mb_strlen($name) < 2) {
            throw ValidationException::field('name', 'Adınızı yazın.');
        }
        DB::table('members')->where('id', $member['id'])->update(['name' => $name, 'updated_at' => Clock::now()]);

        return self::find((int) $member['id']);
    }

    /** Hesabı siler (KVKK): şifreli hesapta şifre, her durumda "SİL" / "DELETE" onayı. */
    public static function delete(array $member, array $input): void
    {
        $hash = (string) DB::table('members')->where('id', $member['id'])->value('password');
        if ($hash !== '' && ! Hash::check((string) ($input['password'] ?? ''), $hash)) {
            throw ValidationException::field('password', 'Şifre hatalı.');
        }
        if (! in_array(mb_strtoupper(trim((string) ($input['confirm'] ?? ''))), ['SİL', 'SIL', 'DELETE', 'LÖSCHEN'], true)) {
            throw ValidationException::field('confirm', 'Onaylamak için SİL yazın.');
        }
        self::purge((int) $member['id']);
    }

    /** Üyeyi ve ona bağlı her şeyi (oturumlar, anahtarlar, anlık bildirim abonelikleri) siler; siparişler kalır. */
    public static function purge(int $id): void
    {
        DB::table('member_sessions')->where('member_id', $id)->delete();
        DB::table('member_tokens')->where('member_id', $id)->delete();
        DB::table('push_subscriptions')->where('owner_type', 'member')->where('owner_id', $id)->delete();
        DB::table('members')->where('id', $id)->delete();
    }

    // ------------------------------------------------------------ okuma

    public static function find(int $id): ?array
    {
        $row = DB::table('members')->where('id', $id)->first();

        return $row ? [
            'id' => (int) $row->id, 'name' => (string) $row->name, 'email' => (string) $row->email, 'status' => (string) $row->status,
            'locale' => (string) $row->locale, 'verified' => $row->email_verified_at !== null, 'has_password' => (bool) $row->password,
            'google' => $row->google_id !== null, 'created_at' => Clock::local((string) $row->created_at), 'last_login_at' => Clock::local($row->last_login_at),
        ] : null;
    }

    /** Ziyaretçiye giden hali (iç alanlar yok). */
    public static function present(array $member): array
    {
        return array_intersect_key($member, array_flip(['name', 'email', 'verified', 'has_password', 'google']));
    }

    /** Üyenin hesap sayfası adresi (dilinde), sorgu ile. */
    public static function pageUrl(string $locale, array $query = []): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $path = '/hesap/';
        try {
            $router = Router::load(Paths::siteRoot());
            $path = $router->url('account', $locale !== '' ? $locale : Locales::default()) ?: $path;
        } catch (\Throwable) {
            // Derleme yoksa varsayılan adres.
        }

        return $base.$path.($query ? '?'.http_build_query($query) : '');
    }

    private static function locale(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, Locales::all(), true) ? $value : '';
    }

    /** Tek kullanımlık anahtar (özeti saklanır); aynı amaçlı eski anahtarlar silinir. */
    private static function token(int $memberId, string $purpose, int $minutes): string
    {
        $token = bin2hex(random_bytes(24));
        DB::table('member_tokens')->where('member_id', $memberId)->where('purpose', $purpose)->delete();
        DB::table('member_tokens')->insert([
            'member_id' => $memberId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token), 'created_at' => Clock::now(),
            'expires_at' => Clock::later("+{$minutes} minutes"),
        ]);

        return $token;
    }

    private static function redeem(string $token, string $purpose): int
    {
        $row = DB::table('member_tokens')->where('token_hash', hash('sha256', $token))->where('purpose', $purpose)->where('expires_at', '>', Clock::now())->first();
        if (! $row) {
            throw ValidationException::field('token', 'Bağlantı geçersiz ya da süresi dolmuş.');
        }
        DB::table('member_tokens')->where('id', $row->id)->delete();

        return (int) $row->member_id;
    }
}
