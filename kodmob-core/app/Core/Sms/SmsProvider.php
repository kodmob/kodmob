<?php

namespace App\Core\Sms;

/**
 * SMS sağlayıcısı: ayar alanlarını tanımlar, ayarlardan gönderici (SmsGateway) kurar. Çatıda NetGSM (NetgsmProvider) ve
 * deneme (LogProvider) var; başka sağlayıcı (İleti Merkezi, Twilio…) araç paketi olarak yazılır ve modülünün `boot()`'unda
 * kaydolur: `SmsProviders::register(new IletiMerkeziProvider)` (Keşfet: github.com/kodmob/kodmob-explore).
 *
 * Alan tanımı (panel Sistem Ayarları › SMS formu bundan çizer):
 *
 *   ['key' => 'username', 'label' => 'Kullanıcı adı', 'type' => 'text' | 'secret' | 'select', 'required' => true,
 *    'options' => ['0' => 'Bilgilendirme', …], 'default' => '0', 'placeholder' => '850…', 'help' => '…', 'max' => 100,
 *    'sender' => true,   // gönderici başlığı alanı: "Bağlantıyı dene" onaylı başlıkları bu alana önerir
 *    'message' => '…']   // zorunlu alan boşken gösterilecek metin (yoksa "<Etiket> gerekli.")
 *
 * `secret` alan mühürlü saklanır (Secret), panele geri gönderilmez.
 */
interface SmsProvider
{
    /** Kayıttaki anahtar (küçük harf), ör. "netgsm". */
    public function key(): string;

    /** Panelde görünen ad. */
    public function label(): string;

    /** Sağlayıcı seçilince formun üstündeki açıklama (hesap, IP izni…). */
    public function help(): string;

    /** @return list<array{key:string,label:string,type:string,required?:bool,options?:array<int|string,string>,default?:string,placeholder?:string,help?:string,max?:int,sender?:bool,message?:string}> */
    public function fields(): array;

    /** @param array<string,string> $values alanların değerleri (gizliler açık) */
    public function gateway(array $values): SmsGateway;
}
