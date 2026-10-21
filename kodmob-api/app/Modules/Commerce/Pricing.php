<?php

namespace App\Modules\Commerce;

/**
 * Sepetin ve siparişin hesabı (yan etkisiz; tutarlar kuruş). Sepet her gösterilişte, sipariş verilirken bir kez
 * daha sunucuda hesaplanır: tarayıcıdan gelen fiyata hiç güvenilmez.
 *
 * Sıra: satır toplamları → ara toplam → kupon indirimi (satırlara oranla dağıtılır) → KDV (satır başına; fiyatlar
 * KDV dahilse içinden ayrılır, hariçse eklenir) → kargo (dijital olmayan ürün varsa; eşik üstü ücretsiz) → ödeme
 * yönteminin ek ücreti (kapıda ödeme gibi).
 */
final class Pricing
{
    /**
     * @param  list<array{product_id:int,title:string,sku:string,price:int,tax_rate:int,quantity:int,digital:bool}>  $lines
     * @param  array{type:string,value:int,min_total:int}|null  $coupon
     * @param  array{include_tax?:bool,shipping_fee?:int,free_shipping_over?:int,fee?:int}  $options
     * @return array{lines:list<array<string,mixed>>,subtotal:int,discount:int,shipping:int,fee:int,tax:int,total:int,shippable:bool,coupon_error:string}
     */
    public static function quote(array $lines, ?array $coupon = null, array $options = []): array
    {
        $includeTax = (bool) ($options['include_tax'] ?? true);
        foreach ($lines as $i => $line) {
            $lines[$i]['total'] = max(0, $line['price']) * max(0, $line['quantity']);
        }
        $subtotal = array_sum(array_column($lines, 'total'));

        [$discount, $couponError] = self::discount($coupon, $subtotal);

        // İndirim satırlara oranla dağıtılır (KDV indirimli tutardan); yuvarlama farkı son satıra.
        $left = $discount;
        $tax = 0;
        $last = array_key_last($lines);
        foreach ($lines as $i => $line) {
            $share = $i === $last ? $left : ($subtotal > 0 ? intdiv($discount * $line['total'], $subtotal) : 0);
            $left -= $share;
            $net = $line['total'] - $share;
            $rate = max(0, $line['tax_rate']);
            $lineTax = $includeTax
                ? (int) round($net - $net / (1 + $rate / 100))
                : (int) round($net * $rate / 100);
            $lines[$i]['discount'] = $share;
            $lines[$i]['tax'] = $lineTax;
            $tax += $lineTax;
        }

        $shippable = (bool) array_filter($lines, static fn ($line) => ! $line['digital']);
        $after = $subtotal - $discount;
        $shippingFee = max(0, (int) ($options['shipping_fee'] ?? 0));
        $freeOver = max(0, (int) ($options['free_shipping_over'] ?? 0));
        $shipping = $shippable && $lines && ! ($freeOver > 0 && $after >= $freeOver) ? $shippingFee : 0;
        $fee = $lines ? max(0, (int) ($options['fee'] ?? 0)) : 0;

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shipping,
            'fee' => $fee,
            'tax' => $tax,
            'total' => $after + ($includeTax ? 0 : $tax) + $shipping + $fee,
            'shippable' => $shippable,
            'coupon_error' => $couponError,
        ];
    }

    /** @return array{0:int,1:string} indirim ve kupon uygulanamıyorsa nedeni */
    private static function discount(?array $coupon, int $subtotal): array
    {
        if ($coupon === null || $subtotal <= 0) {
            return [0, ''];
        }
        if ($subtotal < (int) $coupon['min_total']) {
            return [0, 'Bu kupon '.Money::format((int) $coupon['min_total']).' ve üstü alışverişte geçerli.'];
        }
        $value = max(0, (int) $coupon['value']);
        $discount = $coupon['type'] === 'percent'
            ? intdiv($subtotal * min(100, $value), 100)
            : min($value, $subtotal);

        return [$discount, ''];
    }
}
