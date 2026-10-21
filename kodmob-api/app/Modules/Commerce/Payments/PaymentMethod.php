<?php

namespace App\Modules\Commerce\Payments;

/**
 * Ödeme yöntemi (havale, kapıda ödeme; online sağlayıcılar kendi modüllerinden `Payments::register()` ile).
 * Kart bilgisi sunucuya hiç gelmez: online yöntem sağlayıcının ödeme sayfasına yönlendirir, sonucu imzalı
 * bildirimle (geri çağrı) alır.
 */
interface PaymentMethod
{
    /** Anahtar (siparişte saklanır): bank_transfer, cash_on_delivery, stripe… */
    public function key(): string;

    /** Ödeme adımında görünen ad (ziyaretçinin dilinde). */
    public function label(string $locale): string;

    /** Bu projede kullanılabilir mi (ayarı açık ve gerekli bilgileri girilmiş). */
    public function available(bool $shippable): bool;

    /** Siparişe eklenen ücret (kuruş; kapıda ödeme gibi). */
    public function fee(): int;

    /** Online sağlayıcı mı (ödeme sayfasına yönlendirir, sonucu bildirimle gelir). */
    public function online(): bool;

    /**
     * Sipariş verildi (ya da müşteri ödemeyi yeniden başlattı): ödemenin başlangıcı. Dönen dizi ziyaretçiye gider:
     * `['instructions' => [...]]` (havale bilgileri) ya da `['redirect' => 'https://…']` (online sağlayıcının
     * ödeme sayfası). Online yöntem ödeme kaydına sağlayıcının numarasını yazar (Orders::reference) ve sonucu
     * imzalı bildirimle alır (Orders::markPaid).
     *
     * @param  array<string,mixed>  $order  Orders::view()
     * @param  string  $returnUrl  müşterinin sipariş sayfası (numara + bağlantı anahtarı); sağlayıcı ödemeden sonra
     *                             buraya döndürür (`&odeme=tamam` / `&odeme=iptal`)
     * @return array<string,mixed>
     */
    public function start(array $order, string $returnUrl): array;
}
