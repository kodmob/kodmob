<?php

namespace App\Modules\Commerce;

use App\Core\Content\Locales;

/**
 * Mağazanın ziyaretçiye giden metinleri (ödeme yöntemleri, e-postalar, hatalar). Sitenin sayfa metinleri profilin
 * sözlüğündedir; bunlar sunucunun ürettikleri. Türkçe ve İngilizce; başka dilde İngilizce, o da yoksa Türkçe.
 */
final class CommerceText
{
    private const TEXT = [
        'tr' => [
            'pay.bank_transfer' => 'Havale / EFT',
            'pay.cash_on_delivery' => 'Kapıda ödeme',
            'pay.bank_name' => 'Banka',
            'pay.bank_holder' => 'Hesap sahibi',
            'pay.reference' => 'Açıklama',
            'pay.cod_note' => 'Ödemeyi ürünü teslim alırken yaparsınız.',
            'status.pending' => 'Ödeme bekliyor',
            'status.paid' => 'Ödendi',
            'status.processing' => 'Hazırlanıyor',
            'status.shipped' => 'Kargoda',
            'status.completed' => 'Tamamlandı',
            'status.cancelled' => 'İptal edildi',
            'status.refunded' => 'İade edildi',
            'mail.placed.subject' => 'Siparişinizi aldık: {number}',
            'mail.placed.hello' => 'Merhaba {name},',
            'mail.placed.body' => 'Siparişiniz bize ulaştı. Ayrıntılar aşağıda; durumunu bağlantıdan izleyebilirsiniz.',
            'mail.placed.bank' => 'Havale / EFT bilgileri aşağıdadır; açıklamaya sipariş numaranızı yazın.',
            'mail.status.subject' => 'Siparişinizin durumu: {status} ({number})',
            'mail.status.body' => 'Siparişinizin durumu "{status}" oldu.',
            'mail.view' => 'Siparişi görüntüle',
            'fact.number' => 'Sipariş no',
            'fact.total' => 'Toplam',
            'fact.payment' => 'Ödeme',
            'fact.items' => 'Ürünler',
            'error.empty' => 'Sepetiniz boş.',
            'error.stock' => '"{title}" için yeterli stok yok.',
            'error.unavailable' => '"{title}" artık satılmıyor.',
            'error.payment' => 'Ödeme sayfası şu anda açılamadı. Siparişiniz kaydedildi; sipariş sayfasından yeniden deneyebilirsiniz.',
        ],
        'en' => [
            'pay.bank_transfer' => 'Bank transfer',
            'pay.cash_on_delivery' => 'Cash on delivery',
            'pay.bank_name' => 'Bank',
            'pay.bank_holder' => 'Account holder',
            'pay.reference' => 'Reference',
            'pay.cod_note' => 'You pay when you receive the goods.',
            'status.pending' => 'Awaiting payment',
            'status.paid' => 'Paid',
            'status.processing' => 'Processing',
            'status.shipped' => 'Shipped',
            'status.completed' => 'Completed',
            'status.cancelled' => 'Cancelled',
            'status.refunded' => 'Refunded',
            'mail.placed.subject' => 'We received your order: {number}',
            'mail.placed.hello' => 'Hello {name},',
            'mail.placed.body' => 'Your order has reached us. Details are below; you can follow its status from the link.',
            'mail.placed.bank' => 'Bank transfer details are below; please write your order number as the reference.',
            'mail.status.subject' => 'Your order status: {status} ({number})',
            'mail.status.body' => 'Your order status is now "{status}".',
            'mail.view' => 'View order',
            'fact.number' => 'Order no',
            'fact.total' => 'Total',
            'fact.payment' => 'Payment',
            'fact.items' => 'Items',
            'error.empty' => 'Your cart is empty.',
            'error.stock' => 'Not enough stock for "{title}".',
            'error.unavailable' => '"{title}" is no longer for sale.',
            'error.payment' => 'The payment page could not be opened. Your order is saved; you can retry from the order page.',
        ],
    ];

    /** @param array<string,string|int> $vars */
    public static function get(string $key, ?string $locale = null, array $vars = []): string
    {
        $locale ??= Locales::default();
        $text = self::TEXT[$locale][$key] ?? self::TEXT['en'][$key] ?? self::TEXT['tr'][$key] ?? $key;

        return strtr($text, array_combine(array_map(static fn ($k) => '{'.$k.'}', array_keys($vars)), array_map('strval', array_values($vars))) ?: []);
    }
}
