<?php

namespace App\Modules\Users;

use App\Core\Activity\Activity;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Mail\MailLog;
use App\Core\Mail\MailSettings;
use App\Core\Mail\SendMail;
use App\Core\Mail\SiteMail;
use App\Core\Sms\Sms;
use App\Core\Support\Text;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Kullanıcılara SMS ve e-posta (Kullanıcılar sayfası: satır, toplu işlem, yan panel). Yetki:
 * km_message_users.
 *
 *   POST /users/sms   { ids: [..], message }            ayarlı sağlayıcıyla hemen (Ayarlar > SMS)
 *   POST /users/mail  { ids: [..], subject, message }   tek kişiye hemen; çok kişiye arka planda
 *
 * Telefonu ya da e-postası olmayan kullanıcı atlanır ve yanıtta `skipped` ile döner. E-posta
 * yanıtlanınca gönderen yöneticiye gider (Reply-To). Mesaj metni saklanmaz; işlem geçmişine
 * alıcı sayısı (e-postada konu) yazılır.
 */
final class MessageController extends Controller
{
    public const MAX_RECIPIENTS = 200;

    public function sms(Request $request): array
    {
        $input = $this->input($request);
        $users = $this->recipients($input);
        $skipped = [];
        $recipients = [];
        foreach ($users as $user) {
            if (trim((string) $user->phone) === '') {
                $skipped[] = $this->skip($user, 'Telefonu yok');

                continue;
            }
            $recipients[] = ['to' => (string) $user->phone, 'name' => (string) $user->display_name, 'id' => (int) $user->id];
        }
        if (! $recipients) {
            throw ValidationException::field('ids', $users->count() === 1 ? 'Bu kullanıcının telefonu kayıtlı değil.' : 'Seçilen kullanıcıların hiçbirinin telefonu kayıtlı değil.');
        }

        $result = Sms::send($recipients, (string) ($input['message'] ?? ''), 'user.message');
        foreach ($result['invalid'] as $invalid) {
            $skipped[] = ['name' => $invalid['name'], 'reason' => 'Telefon numarası geçersiz ('.$invalid['to'].')'];
        }
        $this->activity('users.sms_sent', $users, ['count' => $result['sent'], 'parts' => $result['parts']]);

        return ['sent' => $result['sent'], 'parts' => $result['parts'], 'skipped' => $skipped];
    }

    public function mail(Request $request): array
    {
        $input = $this->input($request);
        $subject = mb_substr(Text::field((string) ($input['subject'] ?? '')), 0, 150);
        $lines = SiteMail::paragraphs(mb_substr(Text::textarea((string) ($input['message'] ?? '')), 0, 10000));
        $errors = array_filter([
            'subject' => $subject === '' ? 'Konuyu yazın.' : null,
            'message' => ! $lines ? 'Mesajı yazın.' : null,
        ]);
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        $users = $this->recipients($input);
        $sender = $request->user();
        $lines[] = '— '.$sender->display_name;

        $skipped = [];
        $targets = [];
        foreach ($users as $user) {
            if (! Text::isEmail((string) $user->email)) {
                $skipped[] = $this->skip($user, 'E-postası yok');

                continue;
            }
            $targets[] = $user;
        }
        if (! $targets) {
            throw ValidationException::field('ids', 'Seçilen kullanıcıların geçerli e-posta adresi yok.');
        }

        $mail = new SiteMail($subject, $lines, replyToAddress: Text::isEmail((string) $sender->email) ? (string) $sender->email : null, replyToName: (string) $sender->display_name, mailLocale: 'tr');
        if (count($targets) === 1) {
            // Tek kişi: hemen gönderilir, sunucunun hatası panelde görünür.
            $to = (string) $targets[0]->email;
            MailSettings::apply();
            try {
                Mail::to($to)->send($mail);
            } catch (Throwable $e) {
                MailLog::record($to, $subject, 'user.message', false, $e->getMessage());
                throw new DomainException('km_mail_failed', 'Gönderilemedi: '.mb_substr($e->getMessage(), 0, 300), 422);
            }
            MailLog::record($to, $subject, 'user.message', true);
        } else {
            // Çok kişi: her biri arka planda (yeniden denemeli); sonuçlar Ayarlar > E-posta'da.
            foreach ($targets as $user) {
                SendMail::to((string) $user->email, $mail, 'user.message');
            }
        }
        $this->activity('users.mail_sent', $users, ['count' => count($targets), 'subject' => $subject]);

        return ['sent' => count($targets), 'queued' => count($targets) > 1, 'skipped' => $skipped];
    }

    /** @return Collection<int,User> */
    private function recipients(array $input): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($input['ids'] ?? [])), static fn ($id) => $id > 0)));
        if (! $ids) {
            throw ValidationException::field('ids', 'Alıcı seçin.');
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            throw ValidationException::field('ids', 'Tek seferde en fazla '.self::MAX_RECIPIENTS.' kişiye gönderilebilir.');
        }
        $users = User::query()->whereIn('id', $ids)->orderBy('display_name')->get();
        if ($users->isEmpty()) {
            throw ValidationException::field('ids', 'Seçilen kullanıcılar bulunamadı.');
        }

        return $users;
    }

    private function skip(User $user, string $reason): array
    {
        return ['id' => (int) $user->id, 'name' => (string) $user->display_name, 'reason' => $reason];
    }

    private function activity(string $type, Collection $users, array $payload): void
    {
        Activity::type($type);
        if ($users->count() === 1) {
            Activity::subject('user', (int) $users->first()->id, (string) $users->first()->display_name);
        }
        $names = $users->pluck('display_name')->map(static fn ($n) => (string) $n)->all();
        Activity::with($payload + ['names' => array_slice($names, 0, 5), 'more' => max(0, count($names) - 5)]);
    }
}
