<?php

namespace App\Modules\Briefs\Service;

use App\Core\Content\Locales;
use App\Core\Events\Event;
use App\Core\Events\EventBus;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Briefs\Domain\Brief;
use App\Modules\Briefs\Repository\BriefRepository;
use Illuminate\Support\Facades\DB;

/**
 * Ziyaretçi talebini doğrular, kaydeder ve bildirim e-postalarını gönderir.
 *
 * Spam koruması (captcha yok):
 * - Tuzak alan (`website`): görünmez; doluysa bot sayılır.
 * - Süre: form açıldıktan 3 sn içinde gönderilen talep bot sayılır.
 *   Bu iki durumda talep kaydedilmez ama bota başarılı yanıt döner.
 * - Hız sınırı: aynı IP özetinden saatte en fazla 5 talep (429).
 *
 * Alan hataları `details.fields` ile döner; ön yüz kendi dilindeki metni
 * alan anahtarına göre gösterir (sunucu metni Türkçedir).
 */
final class SubmitBrief
{
    /** Hizmet anahtarları: sitenin sözlüğünden (kodmob-frontend/src/i18n → brief.services). */
    public const VOCAB = 'briefServices';

    public const SOURCES = ['brief', 'calculator', 'contact'];

    private const MIN_SECONDS = 3;

    private const LIMIT_PER_HOUR = 5;

    public function __construct(
        private readonly BriefRepository $briefs = new BriefRepository,
        private readonly EventBus $events = new EventBus
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{id:int|null,received:bool}
     */
    public function handle(array $input, string $ip, ?string $userAgent): array
    {
        if ($this->looksLikeBot($input)) {
            $this->events->publish(new Event('brief.spam_blocked', 'brief', 0, ['reason' => 'honeypot_or_speed']));

            return ['id' => null, 'received' => true];
        }

        $ipHash = hash_hmac('sha256', $ip, (string) config('app.key'));
        if ($this->briefs->countRecentByIp($ipHash, 60) >= self::LIMIT_PER_HOUR) {
            throw new DomainException('km_rate_limited', 'Çok sayıda talep gönderildi. Lütfen bir süre sonra tekrar deneyin.', 429);
        }

        $clean = $this->validate($input);
        $brief = Brief::submit(
            $clean['locale'],
            $clean['name'],
            $clean['email'],
            $clean['phone'],
            $clean['company'],
            $clean['services'],
            $clean['budget'],
            $clean['timeline'],
            $clean['message'],
            $clean['estimate'],
            $clean['source'],
            $ipHash,
            $userAgent !== null ? mb_substr($userAgent, 0, 255) : null
        );

        DB::transaction(function () use ($brief) {
            $this->briefs->save($brief);
            $this->events->publish(new Event('brief.received', 'brief', (int) $brief->id(), [
                'name' => $brief->name(),
                'company' => $brief->company(),
                'services' => $brief->services(),
                'locale' => $brief->locale(),
            ]));
        });
        // E-postalar arka planda (Background): ziyaretçi SMTP'yi beklemez; gönderilemese de
        // talep kaybolmaz ve panelde görünür.
        (new BriefMailer)->send($brief);

        return ['id' => (int) $brief->id(), 'received' => true];
    }

    private function looksLikeBot(array $input): bool
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return true;
        }
        $startedAt = (int) ($input['started_at'] ?? 0); // ms, form açıldığında

        return $startedAt > 0 && (microtime(true) * 1000 - $startedAt) < self::MIN_SECONDS * 1000;
    }

    /** @return array<string,mixed> */
    private function validate(array $input): array
    {
        $errors = [];
        $name = Text::field($input['name'] ?? '');
        $email = Text::email($input['email'] ?? '');
        $message = Text::textarea($input['message'] ?? '');
        $services = array_values(array_unique(array_filter(array_map('strval', (array) ($input['services'] ?? [])), static fn ($s) => mb_strlen($s) <= 40 && SiteManifest::inVocab(self::VOCAB, $s))));
        $services = array_slice($services, 0, 12);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Adınızı yazın.';
        }
        if ($email === '') {
            $errors['email'] = 'Geçerli bir e-posta adresi yazın.';
        }
        // Hizmet seçimi proje brifinde ve hesaplayıcıda zorunlu; iletişim formunda yok.
        if (! $services && ($input['source'] ?? '') !== 'contact') {
            $errors['services'] = 'En az bir hizmet seçin.';
        }
        if (mb_strlen($message) < 20 || mb_strlen($message) > 5000) {
            $errors['message'] = 'Mesajınızı 20–5000 karakterle yazın.';
        }
        if (empty($input['consent'])) {
            $errors['consent'] = 'KVKK metnini onaylayın.';
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }

        $optional = static fn (string $key, int $max) => ($value = mb_substr(Text::field($input[$key] ?? ''), 0, $max)) !== '' ? $value : null;
        $estimate = is_array($input['estimate'] ?? null) ? $this->cleanEstimate($input['estimate']) : null;
        $source = in_array($input['source'] ?? '', self::SOURCES, true) ? $input['source'] : 'brief';

        return [
            'locale' => Locales::normalize((string) ($input['locale'] ?? '')),
            'name' => $name,
            'email' => $email,
            'phone' => $optional('phone', 40),
            'company' => $optional('company', 160),
            'services' => $services,
            'budget' => $optional('budget', 60),
            'timeline' => $optional('timeline', 60),
            'message' => $message,
            'estimate' => $estimate,
            'source' => $source,
        ];
    }

    /** Hesaplayıcı özeti: yalnızca beklenen, düz alanlar. */
    private function cleanEstimate(array $estimate): array
    {
        return [
            'min' => (int) ($estimate['min'] ?? 0),
            'max' => (int) ($estimate['max'] ?? 0),
            'currency' => preg_replace('/[^A-Z]/', '', strtoupper((string) ($estimate['currency'] ?? 'TRY'))) ?: 'TRY',
            'weeks' => Text::field($estimate['weeks'] ?? ''),
            'items' => array_slice(array_map(static fn ($item) => Text::field(is_scalar($item) ? $item : ''), (array) ($estimate['items'] ?? [])), 0, 30),
        ];
    }
}
