<?php

namespace App\Core\Sms;

use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SMS gönderim kaydı (`sms_log`): alıcının adı, maskeli numarası, tür, boy, sonuç, sağlayıcının iş
 * numarası ve hata. Mesaj metni ve numaranın tamamı saklanmaz. Ayarlar > SMS "Son gönderimler";
 * 90 günden eskiler gece silinir.
 */
final class SmsLog
{
    public const KEEP_DAYS = 90;

    public static function record(string $number, string $name, string $kind, bool $sent, int $parts, ?string $job = null, ?string $error = null): void
    {
        try {
            DB::table('sms_log')->insert([
                'recipient' => SmsText::mask($number),
                'name' => mb_substr($name, 0, 120),
                'kind' => mb_substr($kind, 0, 40),
                'status' => $sent ? 'sent' : 'failed',
                'parts' => max(0, min(255, $parts)),
                'job' => $job !== null ? mb_substr($job, 0, 64) : null,
                'error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'created_at' => Clock::now(),
            ]);
        } catch (Throwable) {
            // Tablo yoksa (kurulum öncesi) kayıt tutulmaz; gönderim etkilenmez.
        }
    }

    /** @return list<array{id:int,recipient:string,name:string,kind:string,status:string,parts:int,job:?string,error:?string,created_at:string}> */
    public static function recent(int $limit = 20): array
    {
        try {
            return DB::table('sms_log')->orderByDesc('id')->limit($limit)->get()->map(static fn ($r) => [
                'id' => (int) $r->id,
                'recipient' => (string) $r->recipient,
                'name' => (string) $r->name,
                'kind' => (string) $r->kind,
                'status' => (string) $r->status,
                'parts' => (int) $r->parts,
                'job' => $r->job !== null ? (string) $r->job : null,
                'error' => $r->error !== null ? (string) $r->error : null,
                'created_at' => Clock::atom((string) $r->created_at),
            ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    public static function prune(): void
    {
        try {
            DB::table('sms_log')->where('created_at', '<', Clock::later('-'.self::KEEP_DAYS.' days'))->delete();
        } catch (Throwable) {
        }
    }
}
