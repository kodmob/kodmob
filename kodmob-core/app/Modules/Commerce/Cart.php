<?php

namespace App\Modules\Commerce;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\Commerce\Lines\CartLines;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;

/**
 * Ziyaretçinin sepeti (`commerce_carts`). Tarayıcıda yalnızca rastgele anahtar durur (HttpOnly `km_cart`
 * çerezi); tabloda özeti. Satırlar tür, kayıt, adet ve ek bilgidir (Lines\LineType: ürün; Rezervasyon'un tarih
 * ve kişi sayıları): ad, fiyat, stok / kapasite her gösterilişte türden okunur (Pricing). Sepete eklemek hesap
 * gerektirmez; 30 gün dokunulmayan sepet silinir (CommerceModule::schedule).
 */
final class Cart
{
    public const COOKIE = 'km_cart';

    public const MAX_LINES = 50;

    public const MAX_QUANTITY = 999;

    /** @param array<string,array{key:string,type:string,id:int,quantity:int,data:array<string,mixed>}> $lines anahtar → satır */
    private function __construct(public readonly string $token, private array $lines, private string $coupon, private bool $exists) {}

    /** Anahtarın sepeti; yoksa (ya da anahtar yoksa) yeni boş sepet (kaydedilince oluşur). */
    public static function open(?string $token): self
    {
        $token = is_string($token) && preg_match('/^[0-9a-f]{40}$/', $token) ? $token : '';
        $row = $token !== '' ? DB::table('commerce_carts')->where('token_hash', hash('sha256', $token))->first() : null;
        if ($row === null) {
            return new self($token !== '' ? $token : bin2hex(random_bytes(20)), [], '', false);
        }
        $lines = [];
        foreach ((array) json_decode((string) $row->items, true) as $key => $line) {
            // Eski biçim (2.6 öncesi denemeler): ürün → adet.
            $line = is_array($line) ? $line : ['type' => 'product', 'id' => (int) $key, 'quantity' => (int) $line, 'data' => []];
            $line = ['type' => (string) ($line['type'] ?? 'product'), 'id' => (int) ($line['id'] ?? 0), 'quantity' => (int) ($line['quantity'] ?? 0), 'data' => (array) ($line['data'] ?? [])];
            $lines[self::lineKey($line['type'], $line['id'], $line['data'])] = ['key' => self::lineKey($line['type'], $line['id'], $line['data'])] + $line;
        }

        return new self($token, $lines, (string) $row->coupon, true);
    }

    /** Satır anahtarı: tür, kayıt ve ek bilginin özeti (aynı tur başka tarihte ayrı satır). */
    public static function lineKey(string $type, int $id, array $data): string
    {
        return $type.':'.$id.($data ? ':'.substr(sha1(Text::json($data)), 0, 12) : '');
    }

    /** Satır ekler (aynı satır varsa adedini artırır); tür doğrular (stok, kapasite). */
    public function add(string $type, int $id, int $quantity, array $data, string $locale): string
    {
        $kind = CartLines::get($type) ?? throw ValidationException::field('type', 'Bu tür satış kapalı.');
        $prepared = $kind->prepare($id, max(1, $quantity), $data, $locale);
        $key = self::lineKey($type, $id, $prepared['data']);
        if (isset($this->lines[$key])) {
            $prepared = $kind->prepare($id, min(self::MAX_QUANTITY, $this->lines[$key]['quantity'] + $prepared['quantity']), $prepared['data'], $locale);
        } elseif (count($this->lines) >= self::MAX_LINES) {
            throw ValidationException::field('product', 'Sepette en çok '.self::MAX_LINES.' satır olabilir.');
        }
        $this->lines[$key] = ['key' => $key, 'type' => $type, 'id' => $id, 'quantity' => min(self::MAX_QUANTITY, $prepared['quantity']), 'data' => $prepared['data']];

        return $key;
    }

    /** Satırın adedini yazar; 0 satırı siler. */
    public function set(string $key, int $quantity, string $locale): void
    {
        $line = $this->lines[$key] ?? throw ValidationException::field('line', 'Sepette böyle bir satır yok.');
        $quantity = min(self::MAX_QUANTITY, max(0, $quantity));
        if ($quantity === 0) {
            unset($this->lines[$key]);

            return;
        }
        $kind = CartLines::get($line['type']) ?? throw ValidationException::field('type', 'Bu tür satış kapalı.');
        $this->lines[$key]['quantity'] = $kind->prepare($line['id'], $quantity, $line['data'], $locale)['quantity'];
    }

    /** Kuponu uygular (geçersizse hata) ya da boş kodla kaldırır. */
    public function coupon(string $code): void
    {
        $this->coupon = $code === '' ? '' : Coupons::usable($code)['code'];
    }

    public function couponCode(): string
    {
        return $this->coupon;
    }

    public function clear(): void
    {
        $this->lines = [];
        $this->coupon = '';
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function save(): void
    {
        $values = ['items' => Text::json(array_values(array_map(static fn ($l) => array_diff_key($l, ['key' => true]), $this->lines))), 'coupon' => $this->coupon, 'updated_at' => Clock::now()];
        if ($this->exists) {
            DB::table('commerce_carts')->where('token_hash', hash('sha256', $this->token))->update($values);
        } else {
            DB::table('commerce_carts')->insert($values + ['token_hash' => hash('sha256', $this->token), 'created_at' => Clock::now()]);
            $this->exists = true;
        }
    }

    public function delete(): void
    {
        DB::table('commerce_carts')->where('token_hash', hash('sha256', $this->token))->delete();
        $this->lines = [];
        $this->exists = false;
    }

    /**
     * Ziyaretçiye giden sepet: satırlar (satıştan kalkan düşer), tutarlar, ödeme seçenekleri.
     *
     * @return array<string,mixed>
     */
    public function view(string $locale, ?string $method = null): array
    {
        return array_diff_key($this->details($locale, $method), ['quote' => true, 'coupon_row' => true]);
    }

    /**
     * Sepetin tam hesabı (sipariş verirken: Pricing sonucu ve uygulanan kupon dahil).
     *
     * @return array<string,mixed>
     */
    public function details(string $locale, ?string $method = null): array
    {
        $lines = [];
        $display = [];
        // Ürün / tur sayfasının adresi sitenin rotasından (profilde rotası yoksa boş).
        $router = $this->lines ? Router::load(Paths::siteRoot()) : null;
        foreach ($this->lines as $key => $line) {
            $resolved = CartLines::get($line['type'])?->resolve($line, $locale, $router);
            if ($resolved === null) {
                continue;
            }
            $lines[] = [
                'key' => $key, 'type' => $line['type'], 'product_id' => $line['id'], 'data' => $line['data'],
                'title' => $resolved['title'], 'sku' => $resolved['sku'], 'price' => $resolved['price'], 'tax_rate' => $resolved['tax_rate'],
                'quantity' => $resolved['quantity'], 'digital' => $resolved['digital'],
            ];
            $display[$key] = ['url' => $resolved['url'], 'image' => $resolved['image'], 'inStock' => $resolved['available']];
        }
        $coupon = null;
        $couponError = '';
        if ($this->coupon !== '') {
            try {
                $coupon = Coupons::usable($this->coupon);
            } catch (ValidationException $e) {
                $couponError = $e->getMessage();
            }
        }
        $payment = $method !== null ? Payments\Payments::get($method) : null;
        $quote = Pricing::quote($lines, $coupon, CommerceModule::pricingOptions($payment?->fee() ?? 0));
        $format = static fn (int $minor) => Money::format($minor, null, $locale);

        return [
            'items' => array_map(static fn ($line) => array_diff_key($line, ['data' => true]) + $display[$line['key']] + ['unitText' => $format($line['price']), 'totalText' => $format($line['total'])], $quote['lines']),
            'count' => array_sum(array_column($quote['lines'], 'quantity')),
            'coupon' => $this->coupon,
            'couponError' => $couponError ?: $quote['coupon_error'],
            'currency' => Money::currency(),
            'shippable' => $quote['shippable'],
            'payments' => Payments\Payments::options($quote['shippable'], $locale),
            'totals' => array_map($format, array_intersect_key($quote, array_flip(['subtotal', 'discount', 'shipping', 'fee', 'tax', 'total']))),
            'amounts' => array_intersect_key($quote, array_flip(['subtotal', 'discount', 'shipping', 'fee', 'tax', 'total'])),
            'includeTax' => CommerceModule::pricingOptions()['include_tax'],
            'quote' => $quote,
            'coupon_row' => $coupon,
        ];
    }
}
