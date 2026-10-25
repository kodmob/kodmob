<?php

namespace App\Core\Sms;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;

/**
 * SMS gönderimi: metni denetler, numaraları düzeltir (tekrarları atar), ayarlı sağlayıcıyla tek
 * istekte gönderir ve her alıcıyı gönderim kaydına (SmsLog) yazar. Hemen gönderir (kuyruksuz):
 * sağlayıcının hatası (kredi bitti, başlık tanımsız…) paneldeki kişiye o anda gösterilir.
 *
 *   Sms::send([['to' => '0532 123 45 67', 'name' => 'Ayşe']], 'Toplantı 15:00\'te.', 'user.message');
 *
 * Mesaj metni hiçbir yerde saklanmaz.
 */
final class Sms
{
    /**
     * @param  list<array{to:string,name?:string}>  $recipients
     * @return array{sent:int,job:string,parts:int,encoding:string,invalid:list<array{name:string,to:string}>}
     */
    public static function send(array $recipients, string $text, string $kind): array
    {
        $info = self::check($text);
        $gateway = SmsSettings::gateway();

        $valid = [];
        $invalid = [];
        foreach ($recipients as $recipient) {
            $number = SmsText::number((string) ($recipient['to'] ?? ''));
            if ($number === null) {
                $invalid[] = ['name' => (string) ($recipient['name'] ?? ''), 'to' => (string) ($recipient['to'] ?? '')];

                continue;
            }
            $valid[$number] ??= (string) ($recipient['name'] ?? '');
        }
        if (! $valid) {
            throw ValidationException::field('to', count($recipients) === 1 ? 'Geçerli bir cep telefonu numarası yazın (ör. 0532 123 45 67).' : 'Alıcıların hiçbirinin geçerli cep telefonu numarası yok.');
        }

        try {
            $job = $gateway->send(array_map('strval', array_keys($valid)), $info['text'], $info['encoding']);
        } catch (DomainException $e) {
            foreach ($valid as $number => $name) {
                SmsLog::record((string) $number, $name, $kind, false, $info['parts'], null, $e->getMessage());
            }
            throw $e;
        }
        foreach ($valid as $number => $name) {
            SmsLog::record((string) $number, $name, $kind, true, $info['parts'], $job);
        }

        return ['sent' => count($valid), 'job' => $job, 'parts' => $info['parts'], 'encoding' => $info['encoding'], 'invalid' => $invalid];
    }

    /**
     * Metni gönderilebilir mi diye denetler.
     *
     * @return array{text:string,encoding:string,length:int,parts:int,per_part:int,unsupported:list<string>}
     */
    public static function check(string $text): array
    {
        $info = SmsText::analyze($text);
        if ($info['text'] === '') {
            throw ValidationException::field('message', 'Mesajı yazın.');
        }
        if ($info['unsupported']) {
            throw ValidationException::field('message', 'Şu karakterler SMS ile gönderilemez: '.implode(' ', $info['unsupported']).'. Silin ya da harfle yazın.');
        }
        if ($info['parts'] > SmsText::MAX_PARTS) {
            throw ValidationException::field('message', 'Mesaj en fazla '.SmsText::MAX_PARTS.' SMS boyunda olabilir; kısaltın.');
        }

        return $info;
    }
}
