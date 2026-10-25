<?php

namespace App\Modules\Newsletter;

use App\Core\Content\Locales;
use App\Core\Events\Event;
use App\Core\Events\EventBus;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Mail\MailLog;
use App\Core\Mail\MailSettings;
use App\Core\Support\Clock;
use App\Core\Support\HtmlSanitizer;
use App\Core\Support\Text;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Bültenler (`newsletter_campaigns`): taslak → gönderiliyor → (duraklatıldı) → gönderildi.
 *
 * Gönderim parça parça (`tick`): her dakika zamanlanmış görev (cron yoksa paneldeki bülten sayfası açıkken) onaylı
 * aboneleri kimlik sırasıyla dolaşır; saatlik sınır (SendQuota) dolunca bekler, imleç (`cursor`) kaldığı yeri
 * tutar. Başlangıçta en büyük abone kimliği (`last_id`) alınır: sonradan abone olan bu bülteni almaz, gönderim
 * sırasında abonelikten çıkan atlanır. Art arda 5 başarısız gönderim (e-posta ayarı bozuk, sunucu reddediyor)
 * bülteni durdurur ve imleci dizinin başına alır: ayar düzeltilip "Devam et" denince o aboneler yeniden denenir.
 */
final class Campaigns
{
    public const DRAFT = 'draft';

    public const SENDING = 'sending';

    public const PAUSED = 'paused';

    public const SENT = 'sent';

    private const TABLE = 'newsletter_campaigns';

    private const MAX_STREAK = 5;

    public function __construct(private readonly EventBus $events = new EventBus) {}

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return DB::table(self::TABLE)->orderByDesc('id')->get()->map(fn ($row) => $this->view($row))->all();
    }

    public function find(int $id): array
    {
        return $this->view($this->row($id));
    }

    /** Onaylı abone sayısı (hedef dilde; boş: hepsi). */
    public function audience(?string $locale): int
    {
        return $this->recipients($locale)->count();
    }

    /** @param array<string,mixed> $input subject, body (HTML), locale ('' = bütün diller) */
    public function save(?int $id, array $input, ?int $userId = null): array
    {
        if ($id !== null && $this->row($id)->status !== self::DRAFT) {
            throw new ConflictException('km_newsletter_locked', 'Gönderimi başlamış bülten değiştirilemez.');
        }
        $subject = mb_substr(Text::field($input['subject'] ?? ''), 0, 200);
        $body = HtmlSanitizer::clean((string) ($input['body'] ?? ''));
        $locale = (string) ($input['locale'] ?? '');
        $errors = [];
        if (mb_strlen($subject) < 3) {
            $errors['subject'] = 'Konuyu yazın.';
        }
        if (trim(Text::stripTags($body)) === '' && ! str_contains($body, '<img')) {
            $errors['body'] = 'Bültenin içeriğini yazın.';
        }
        if ($locale !== '' && ! Locales::valid($locale)) {
            $errors['locale'] = 'Geçersiz dil.';
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        $values = ['subject' => $subject, 'body' => $body, 'locale' => $locale !== '' ? $locale : null, 'updated_at' => Clock::now()];
        if ($id === null) {
            $id = (int) DB::table(self::TABLE)->insertGetId($values + ['status' => self::DRAFT, 'created_by' => $userId, 'created_at' => Clock::now()]);
        } else {
            DB::table(self::TABLE)->where('id', $id)->update($values);
        }

        return $this->find($id);
    }

    public function delete(int $id): string
    {
        $row = $this->row($id);
        if ($row->status === self::SENDING) {
            throw new ConflictException('km_newsletter_sending', 'Gönderilmekte olan bülten silinemez; önce duraklatın.');
        }
        DB::table(self::TABLE)->where('id', $id)->delete();

        return (string) $row->subject;
    }

    /** Gönderimi başlatır (taslak) ya da sürdürür (duraklatılmış). */
    public function start(int $id): array
    {
        $row = $this->row($id);
        if ($row->status === self::DRAFT) {
            $lastId = (int) $this->recipients($row->locale)->max('id');
            $total = $this->recipients($row->locale)->count();
            if ($total === 0) {
                throw ValidationException::field('locale', 'Bu dilde onaylı abone yok.');
            }
            DB::table(self::TABLE)->where('id', $id)->update(['status' => self::SENDING, 'total' => $total, 'last_id' => $lastId, 'cursor' => 0, 'started_at' => Clock::now(), 'updated_at' => Clock::now()]);
        } elseif ($row->status === self::PAUSED) {
            DB::table(self::TABLE)->where('id', $id)->update(['status' => self::SENDING, 'error' => null, 'updated_at' => Clock::now()]);
        } else {
            throw new ConflictException('km_newsletter_state', $row->status === self::SENT ? 'Bu bülten gönderildi.' : 'Bülten zaten gönderiliyor.');
        }

        return $this->find($id);
    }

    public function pause(int $id): array
    {
        if ($this->row($id)->status !== self::SENDING) {
            throw new ConflictException('km_newsletter_state', 'Bülten gönderilmiyor.');
        }
        DB::table(self::TABLE)->where('id', $id)->update(['status' => self::PAUSED, 'updated_at' => Clock::now()]);

        return $this->find($id);
    }

    /** Deneme: bülteni tek adrese gönderir (abone listesine bakmadan; sınıra sayılmaz). */
    public function test(int $id, string $email): void
    {
        $row = $this->row($id);
        $locale = $row->locale ?: Locales::default();
        MailSettings::apply();
        Mail::to($email)->send(new NewsletterMail('[Deneme] '.$row->subject, (string) $row->body, $locale));
    }

    /**
     * Bir gönderim parçası: gönderilmekte olan bültenler (eskisi önce), saatlik sınır ve süre içinde.
     *
     * @return int gönderilen e-posta sayısı
     */
    public function tick(int $maxMails = 40, int $maxSeconds = 45, ?int $only = null): int
    {
        $started = microtime(true);
        $done = 0;
        $ids = DB::table(self::TABLE)->where('status', self::SENDING)->when($only, static fn ($q) => $q->where('id', $only))->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            if (! $this->claim((int) $id)) {
                continue;
            }
            try {
                $done += $this->sendPart((int) $id, $maxMails - $done, $started + $maxSeconds);
            } finally {
                DB::table(self::TABLE)->where('id', $id)->update(['locked_until' => null]);
            }
            if ($done >= $maxMails || microtime(true) >= $started + $maxSeconds) {
                break;
            }
        }

        return $done;
    }

    // ------------------------------------------------------------ iç

    private function sendPart(int $id, int $budget, float $deadline): int
    {
        $sent = 0;
        MailSettings::apply();
        while ($budget > 0 && microtime(true) < $deadline) {
            $row = $this->row($id);
            if ($row->status !== self::SENDING) {
                break;
            }
            $remaining = $this->recipients($row->locale)->where('id', '>', $row->cursor)->where('id', '<=', $row->last_id);
            if (! (clone $remaining)->exists()) {
                $this->finish($row);
                break;
            }
            $quota = SendQuota::available();
            if ($quota < 1) {
                break;
            }
            $batch = $remaining->orderBy('id')->limit(min($budget, $quota, 10))->get(['id', 'email', 'locale', 'token']);
            foreach ($batch as $subscriber) {
                $state = $this->deliver($row, $subscriber);
                $budget--;
                if ($state === 'sent') {
                    $sent++;
                }
                if ($state === 'stopped' || microtime(true) >= $deadline) {
                    return $sent;
                }
                // Panelden duraklatıldıysa parçanın kalanı gitmez.
                $row = $this->row($id);
                if ($row->status !== self::SENDING) {
                    return $sent;
                }
            }
        }

        return $sent;
    }

    /** Bir aboneye gönderir; imleç ve sayaçlar her e-postadan sonra yazılır (yarıda kesilse de çift gitmez). */
    private function deliver(object $row, object $subscriber): string
    {
        try {
            Mail::to($subscriber->email)->send(new NewsletterMail((string) $row->subject, (string) $row->body, (string) ($row->locale ?: $subscriber->locale), (string) $subscriber->token));
            SendQuota::used();
            DB::table(self::TABLE)->where('id', $row->id)->update(['cursor' => $subscriber->id, 'sent' => (int) $row->sent + 1, 'streak' => 0, 'updated_at' => Clock::now()]);

            return 'sent';
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
            MailLog::record((string) $subscriber->email, (string) $row->subject, 'newsletter', false, $error);
            $streak = (int) $row->streak + 1;
            $from = (int) $row->streak === 0 ? (int) $row->cursor : (int) $row->streak_from;
            if ($streak >= self::MAX_STREAK) {
                // Sunucu tarafı hata: bülten durur, art arda başarısız olanlar "Devam et"te yeniden denenir.
                DB::table(self::TABLE)->where('id', $row->id)->update(['status' => self::PAUSED, 'cursor' => $from, 'failed' => max(0, (int) $row->failed - (int) $row->streak), 'streak' => 0, 'streak_from' => 0, 'error' => $error, 'updated_at' => Clock::now()]);
                $this->events->publish(new Event('newsletter.campaign_stopped', 'newsletter_campaign', (int) $row->id, ['subject' => $row->subject, 'error' => $error]));

                return 'stopped';
            }
            DB::table(self::TABLE)->where('id', $row->id)->update(['cursor' => $subscriber->id, 'failed' => (int) $row->failed + 1, 'streak' => $streak, 'streak_from' => $from, 'updated_at' => Clock::now()]);

            return 'failed';
        }
    }

    private function finish(object $row): void
    {
        DB::table(self::TABLE)->where('id', $row->id)->update(['status' => self::SENT, 'finished_at' => Clock::now(), 'updated_at' => Clock::now()]);
        $this->events->publish(new Event('newsletter.campaign_sent', 'newsletter_campaign', (int) $row->id, ['subject' => $row->subject, 'sent' => (int) $row->sent, 'failed' => (int) $row->failed]));
    }

    /** Kilit: aynı bülteni iki süreç birlikte göndermesin (zamanlanmış görev + panel). */
    private function claim(int $id): bool
    {
        $now = Clock::now();
        $until = Clock::carbon($now)->addMinutes(2)->format('Y-m-d H:i:s');

        return DB::table(self::TABLE)->where('id', $id)->where('status', self::SENDING)
            ->where(static fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->update(['locked_until' => $until]) === 1;
    }

    private function recipients(?string $locale): Builder
    {
        return DB::table('newsletter_subscribers')->where('status', Subscribers::ACTIVE)->when($locale, static fn ($q) => $q->where('locale', $locale));
    }

    private function row(int $id): object
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if (! $row) {
            throw NotFoundException::for('newsletter_campaign', $id, 'Bülten bulunamadı.');
        }

        return $row;
    }

    private function view(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'subject' => (string) $row->subject,
            'body' => (string) $row->body,
            'locale' => (string) ($row->locale ?? ''),
            'status' => (string) $row->status,
            'total' => (int) $row->total,
            'sent' => (int) $row->sent,
            'failed' => (int) $row->failed,
            'error' => $row->error,
            'created_at' => Clock::atom((string) $row->created_at),
            'started_at' => Clock::atom($row->started_at),
            'finished_at' => Clock::atom($row->finished_at),
        ];
    }
}
