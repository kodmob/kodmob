<?php

namespace App\Modules\Newsletter;

use App\Core\Content\Locales;
use App\Core\Events\Event;
use App\Core\Events\EventBus;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Mail\SendMail;
use App\Core\Mail\SiteMail;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;

/**
 * Bülten aboneleri (`newsletter_subscribers`). Abonelik her zaman çift onaylıdır: form ya da panel kaydı "onay
 * bekliyor" açar ve onay e-postası gönderir; bağlantıya tıklanınca abone listeye girer. Onayın tarihi ve IP özeti
 * ticari ileti onayının ispatı olarak saklanır.
 *
 * Bağlantılar sitenin `newsletter` sayfasına gider (`?confirm=…`, `?unsubscribe=…`); sayfa sunucuya POST eder
 * (e-posta tarayıcılarının bağlantı ön izlemesi aboneliği onaylamasın ya da sonlandırmasın diye).
 *
 * Spam koruması talep formuyla aynı: tuzak alan (`website`), 3 sn'den hızlı gönderim, IP özetinden saatte 5
 * abonelik; aynı adrese onay e-postası 10 dakikada bir. Zaten abone olan adres de aynı yanıtı alır (listede kim
 * olduğu dışarıdan anlaşılmasın).
 */
final class Subscribers
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const UNSUBSCRIBED = 'unsubscribed';

    public const SOURCES = ['block', 'page', 'panel'];

    private const TABLE = 'newsletter_subscribers';

    private const MIN_SECONDS = 3;

    private const LIMIT_PER_HOUR = 5;

    private const RESEND_MINUTES = 10;

    /** Onaylanmayan abonelik bu kadar gün sonra silinir. */
    public const PENDING_DAYS = 7;

    public function __construct(private readonly EventBus $events = new EventBus) {}

    /**
     * Sitedeki form: onay bekleyen abonelik + onay e-postası.
     *
     * @param  array<string,mixed>  $input  email, name?, locale, consent, source, website (tuzak), started_at (ms)
     * @return array{pending:true}
     */
    public function subscribe(array $input, string $ip): array
    {
        if ($this->looksLikeBot($input)) {
            return ['pending' => true];
        }
        $errors = [];
        $email = Text::email($input['email'] ?? '');
        if ($email === '' || mb_strlen($email) > 190) {
            $errors['email'] = 'Geçerli bir e-posta adresi yazın.';
        }
        if (empty($input['consent'])) {
            $errors['consent'] = 'Onay kutusunu işaretleyin.';
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        $ipHash = hash_hmac('sha256', $ip, (string) config('app.key'));
        $since = Clock::carbon(Clock::now())->subHour()->format('Y-m-d H:i:s');
        if (DB::table(self::TABLE)->where('ip_hash', $ipHash)->where('updated_at', '>=', $since)->count() >= self::LIMIT_PER_HOUR) {
            throw new DomainException('km_rate_limited', 'Çok sayıda abonelik isteği gönderildi. Lütfen bir süre sonra tekrar deneyin.', 429);
        }
        $source = in_array($input['source'] ?? '', self::SOURCES, true) ? (string) $input['source'] : 'block';
        $this->open($email, $this->name($input), Locales::normalize((string) ($input['locale'] ?? '')), $source, $ipHash);

        return ['pending' => true];
    }

    /** Panel: adresi ekler; abone yine onay e-postasıyla listeye girer. */
    public function add(array $input): array
    {
        $email = Text::email($input['email'] ?? '');
        if ($email === '' || mb_strlen($email) > 190) {
            throw ValidationException::field('email', 'Geçerli bir e-posta adresi yazın.');
        }
        if (DB::table(self::TABLE)->where('email', $email)->where('status', self::ACTIVE)->exists()) {
            throw ValidationException::field('email', 'Bu adres zaten abone.');
        }
        $locale = Locales::valid((string) ($input['locale'] ?? '')) ? (string) $input['locale'] : Locales::default();
        $this->open($email, $this->name($input), $locale, 'panel', null, force: true);

        return $this->find((int) DB::table(self::TABLE)->where('email', $email)->value('id'));
    }

    /**
     * Onay bağlantısı. Onay bekleyen abone listeye girer; zaten abone olan için yine "abone"; abonelikten
     * çıkmış olanı eski onay bağlantısı geri almaz.
     *
     * @return array{status:string}
     */
    public function confirm(string $token): array
    {
        $row = $this->byToken($token);
        if ($row->status === self::PENDING) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['status' => self::ACTIVE, 'confirmed_at' => Clock::now(), 'updated_at' => Clock::now()]);
            $this->events->publish(new Event('newsletter.confirmed', 'newsletter_subscriber', (int) $row->id, ['email' => $row->email, 'locale' => $row->locale]));

            return ['status' => self::ACTIVE];
        }

        return ['status' => (string) $row->status];
    }

    /** Abonelikten çıkma (sayfadan ya da e-posta istemcisinin tek tık düğmesinden; RFC 8058). */
    public function unsubscribe(string $token): array
    {
        $row = $this->byToken($token);
        if ($row->status !== self::UNSUBSCRIBED) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['status' => self::UNSUBSCRIBED, 'unsubscribed_at' => Clock::now(), 'updated_at' => Clock::now()]);
            if ($row->status === self::ACTIVE) {
                $this->events->publish(new Event('newsletter.unsubscribed', 'newsletter_subscriber', (int) $row->id, ['email' => $row->email]));
            }
        }

        return ['status' => self::UNSUBSCRIBED];
    }

    /** @return array{items:list<array<string,mixed>>,counts:array<string,int>} */
    public function list(): array
    {
        $items = DB::table(self::TABLE)->orderByDesc('id')->get()->map(fn ($row) => $this->view($row))->all();
        $counts = array_fill_keys([self::PENDING, self::ACTIVE, self::UNSUBSCRIBED], 0);
        foreach (DB::table(self::TABLE)->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->n;
        }

        return ['items' => $items, 'counts' => $counts];
    }

    public function find(int $id): array
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if (! $row) {
            throw NotFoundException::for('newsletter_subscriber', $id, 'Abone bulunamadı.');
        }

        return $this->view($row);
    }

    /** Kalıcı silme (KVKK silme isteği): abonelik ve onay kaydı gider. */
    public function delete(int $id): string
    {
        $email = (string) $this->find($id)['email'];
        DB::table(self::TABLE)->where('id', $id)->delete();

        return $email;
    }

    /** Günlük: onaylanmadan bekleyen abonelikler silinir. */
    public function purgePending(): int
    {
        $before = Clock::carbon(Clock::now())->subDays(self::PENDING_DAYS)->format('Y-m-d H:i:s');

        return DB::table(self::TABLE)->where('status', self::PENDING)->where('updated_at', '<', $before)->delete();
    }

    /** Sitedeki bülten sayfasının adresi (`?confirm=` / `?unsubscribe=` eklenir); profilde yoksa ana sayfa. */
    public static function pageUrl(string $locale): string
    {
        $path = Router::load(Paths::siteRoot())?->url('newsletter', $locale) ?? '/';

        return rtrim((string) config('app.url'), '/').$path;
    }

    // ------------------------------------------------------------ iç

    /**
     * Onay bekleyen kaydı açar ya da yeniler (yeni anahtar) ve onay e-postasını gönderir. Zaten abone olan adrese
     * bir şey yapılmaz; aynı adrese 10 dakikada bir e-posta (panelden eklemede beklenmez).
     */
    private function open(string $email, ?string $name, string $locale, string $source, ?string $ipHash, bool $force = false): void
    {
        $row = DB::table(self::TABLE)->where('email', $email)->first();
        if ($row && $row->status === self::ACTIVE) {
            return;
        }
        $now = Clock::now();
        if (! $force && $row && $row->status === self::PENDING && Clock::carbon((string) $row->updated_at)->diffInMinutes(Clock::carbon($now), true) < self::RESEND_MINUTES) {
            return;
        }
        $token = bin2hex(random_bytes(24));
        $values = ['name' => $name, 'locale' => $locale, 'status' => self::PENDING, 'token' => $token, 'source' => $source, 'ip_hash' => $ipHash, 'updated_at' => $now, 'confirmed_at' => null, 'unsubscribed_at' => null];
        if ($row) {
            DB::table(self::TABLE)->where('id', $row->id)->update($values);
        } else {
            DB::table(self::TABLE)->insert(['email' => $email, 'created_at' => $now] + $values);
        }
        $site = SiteManifest::siteName($locale);
        $lines = [
            $name ? NewsletterText::get('confirm.hello', $locale, ['name' => $name]) : NewsletterText::get('confirm.helloAnon', $locale),
            NewsletterText::get('confirm.body', $locale, ['site' => $site]),
            NewsletterText::get('confirm.ignore', $locale),
        ];
        SendMail::to($email, new SiteMail(
            NewsletterText::get('confirm.subject', $locale).' · '.$site,
            $lines,
            action: ['label' => NewsletterText::get('confirm.action', $locale), 'url' => self::pageUrl($locale).'?confirm='.$token],
            mailLocale: $locale,
        ), 'newsletter.confirm');
    }

    private function byToken(string $token): object
    {
        $row = preg_match('/^[a-f0-9]{48}$/', $token) ? DB::table(self::TABLE)->where('token', $token)->first() : null;
        if (! $row) {
            throw new DomainException('km_newsletter_link', 'Bağlantı geçersiz ya da süresi dolmuş.', 404);
        }

        return $row;
    }

    private function name(array $input): ?string
    {
        $name = mb_substr(Text::field($input['name'] ?? ''), 0, 120);

        return $name !== '' ? $name : null;
    }

    private function looksLikeBot(array $input): bool
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return true;
        }
        $startedAt = (int) ($input['started_at'] ?? 0);

        return $startedAt > 0 && (microtime(true) * 1000 - $startedAt) < self::MIN_SECONDS * 1000;
    }

    private function view(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'email' => (string) $row->email,
            'name' => $row->name,
            'locale' => (string) $row->locale,
            'status' => (string) $row->status,
            'source' => (string) $row->source,
            'created_at' => Clock::atom((string) $row->created_at),
            'confirmed_at' => Clock::atom($row->confirmed_at),
            'unsubscribed_at' => Clock::atom($row->unsubscribed_at),
        ];
    }
}
