<?php

namespace App\Core\Sms;

/**
 * SMS sağlayıcısı. Şimdilik NetGSM ve deneme (günlüğe yazan) var; başka sağlayıcı (İleti Merkezi,
 * Twilio…) bu arayüzü uygulayıp SmsSettings::PROVIDERS ve SmsSettings::gateway()'e eklenir.
 * Hata DomainException('km_sms_failed', yöneticiye gösterilecek Türkçe mesaj) olarak atılır.
 */
interface SmsGateway
{
    /**
     * Aynı metni numaralara tek istekte gönderir.
     *
     * @param  list<string>  $numbers  SmsText::number() ile düzeltilmiş
     * @param  string  $encoding  '' ya da 'tr' (SmsText::analyze)
     * @return string sağlayıcının iş numarası (rapor için)
     */
    public function send(array $numbers, string $text, string $encoding): string;

    /**
     * Hesapta onaylı gönderici başlıkları; bağlantı denemesi olarak da kullanılır (kredi harcamaz).
     *
     * @return list<string>
     */
    public function senders(): array;
}
