<?php

namespace App\Core\Mail;

use App\Core\Jobs\Background;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Bir e-postayı arka planda gönderir (Background): kuyrukta 3 deneme (1 ve 5 dk arayla), sonuç
 * gönderim kaydına (MailLog) yazılır. Hata talebi ya da paneldeki işlemi etkilemez.
 *
 *   SendMail::to('a@b.c', new SiteMail(...), 'brief.reply');
 */
final class SendMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public string $recipient, public SiteMail $mail, public string $kind) {}

    public static function to(string $recipient, SiteMail $mail, string $kind): void
    {
        Background::dispatch(new self($recipient, $mail, $kind));
    }

    public static function label(): string
    {
        return 'E-posta gönderimi';
    }

    public function handle(): void
    {
        MailSettings::apply();
        Mail::to($this->recipient)->send($this->mail);
        MailLog::record($this->recipient, $this->mail->subjectLine, $this->kind, true, null, $this->attempts());
    }

    /** Son deneme de başarısız (kuyrukta) ya da kuyruksuz tek deneme başarısız. */
    public function failed(Throwable $error): void
    {
        MailLog::record($this->recipient, $this->mail->subjectLine, $this->kind, false, $error->getMessage(), $this->attempts());
    }
}
