<?php

namespace App\Core\Mail;

use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gönderim kaydı (`mail_log`): alıcı, konu, tür, sonuç ve hata. E-postanın içeriği saklanmaz.
 * Ayarlar > E-posta "Son gönderimler" listesi; 90 günden eskiler gece silinir.
 */
final class MailLog
{
    public const KEEP_DAYS = 90;

    public static function record(string $recipient, string $subject, string $kind, bool $sent, ?string $error = null, int $attempts = 1): void
    {
        try {
            DB::table('mail_log')->insert([
                'recipient' => mb_substr($recipient, 0, 191),
                'subject' => mb_substr($subject, 0, 255),
                'kind' => mb_substr($kind, 0, 40),
                'status' => $sent ? 'sent' : 'failed',
                'error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'attempts' => max(1, min(255, $attempts)),
                'created_at' => Clock::now(),
            ]);
        } catch (Throwable) {
            // Tablo yoksa (kurulum öncesi) kayıt tutulmaz; gönderim etkilenmez.
        }
    }

    /** @return list<array{id:int,recipient:string,subject:string,kind:string,status:string,error:?string,attempts:int,created_at:string}> */
    public static function recent(int $limit = 20): array
    {
        try {
            return DB::table('mail_log')->orderByDesc('id')->limit($limit)->get()->map(static fn ($r) => [
                'id' => (int) $r->id,
                'recipient' => (string) $r->recipient,
                'subject' => (string) $r->subject,
                'kind' => (string) $r->kind,
                'status' => (string) $r->status,
                'error' => $r->error !== null ? (string) $r->error : null,
                'attempts' => (int) $r->attempts,
                'created_at' => Clock::atom((string) $r->created_at),
            ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    public static function prune(): void
    {
        try {
            DB::table('mail_log')->where('created_at', '<', Clock::carbon(Clock::now())->subDays(self::KEEP_DAYS)->format('Y-m-d H:i:s'))->delete();
        } catch (Throwable) {
        }
    }
}
