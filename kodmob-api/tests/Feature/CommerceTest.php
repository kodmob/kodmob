<?php

namespace Tests\Feature;

use App\Core\Mail\SiteMail;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Settings\SettingsService;
use App\Modules\Commerce\CommerceModule;
use App\Modules\Commerce\Pricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mağaza (Modules\Commerce; docs/done-commerce-raporu.md): hesap, sepet, kupon, sipariş, stok, durum akışı,
 * e-postalar ve yetkiler. Modül ortamdan açılır (test sitesinin profilinde yok).
 */
class CommerceTest extends TestCase
{
    private const ENV = 'blog,briefs,estimate,jarvis,domains,sms,sites,commerce';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
        // Sepet anahtarı çerezde: JSON isteklerinde de çerezler gönderilsin.
        $this->withCredentials();
        $settings = SettingsService::instance();
        $settings->set(CommerceModule::BANK_ENABLED, true);
        $settings->set(CommerceModule::BANK_IBAN, 'TR00 0000 0000 0000 0000 0000 00');
        $settings->set(CommerceModule::BANK_NAME, 'Örnek Bank');
        $settings->set(CommerceModule::SHIPPING_FEE, 50.0);
        $settings->set(CommerceModule::FREE_SHIPPING_OVER, 1000.0);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    /** Panelden ürün (fiyat panelde ondalıklı yazılır, kuruş saklanır). */
    private function product(array $h, string $title, string $price, array $extra = []): int
    {
        return (int) $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => $price, 'tax_rate' => '20'] + $extra + [
            'i18n' => ['tr' => ['title' => $title, 'summary' => $title.' açıklaması']],
        ], $h)->assertCreated()->json('id');
    }

    private function checkout(array $over = []): TestResponse
    {
        return $this->postJson('/v1/shop/checkout', array_replace([
            'name' => 'Ayşe Yılmaz', 'email' => 'Ayse@Ornek.test', 'phone' => '0532 000 00 00',
            'address' => ['line' => 'Atatürk Cad. No 1', 'city' => 'İzmir', 'district' => 'Konak'],
            'payment' => 'bank_transfer', 'terms' => true, 'locale' => 'tr',
        ], $over));
    }

    public function test_pricing_with_tax_coupon_shipping_and_fee(): void
    {
        $line = static fn (int $price, int $qty, int $rate = 20, bool $digital = false) => ['product_id' => 1, 'title' => 'x', 'sku' => '', 'price' => $price, 'tax_rate' => $rate, 'quantity' => $qty, 'digital' => $digital];

        // KDV dahil: 2 × 120,00 = 240,00; KDV içinden 40,00; kargo 50,00.
        $q = Pricing::quote([$line(12000, 2)], null, ['include_tax' => true, 'shipping_fee' => 5000]);
        $this->assertSame([24000, 0, 5000, 4000, 29000], [$q['subtotal'], $q['discount'], $q['shipping'], $q['tax'], $q['total']]);

        // KDV hariç: 100,00 + %20 = 120,00; %10 kupon KDV'den önce; eşik üstü kargo yok; kapıda ödeme ücreti.
        $q = Pricing::quote([$line(10000, 1)], ['type' => 'percent', 'value' => 10, 'min_total' => 0], ['include_tax' => false, 'shipping_fee' => 5000, 'free_shipping_over' => 5000, 'fee' => 1500]);
        $this->assertSame([10000, 1000, 0, 1800, 9000 + 1800 + 1500], [$q['subtotal'], $q['discount'], $q['shipping'], $q['tax'], $q['total']]);

        // Yalnızca dijital ürün: kargo yok; sabit kupon tutarı aşamaz; alt sınır.
        $q = Pricing::quote([$line(3000, 1, 20, true)], ['type' => 'fixed', 'value' => 5000, 'min_total' => 0], ['shipping_fee' => 5000]);
        $this->assertSame([false, 3000, 0], [$q['shippable'], $q['discount'], $q['total']]);
        $q = Pricing::quote([$line(3000, 1)], ['type' => 'fixed', 'value' => 500, 'min_total' => 10000]);
        $this->assertSame(0, $q['discount']);
        $this->assertStringContainsString('ve üstü', $q['coupon_error']);
    }

    public function test_module_is_optional_and_brings_products_and_capabilities(): void
    {
        $this->assertContains('commerce', Modules::keys());
        $this->assertArrayHasKey('km_manage_products', Capability::all());
        $this->assertArrayHasKey(CommerceModule::VIEW_ORDERS, Capability::all());
        $h = $this->admin();
        $id = $this->product($h, 'Seramik Kupa', '149,90', ['sku' => 'KUPA-1', 'track_stock' => true, 'stock' => 5]);
        $stored = json_decode((string) DB::table('contents')->where('id', $id)->value('data'), true);
        $this->assertSame([14990, 5, 'KUPA-1'], [$stored['price'], $stored['stock'], $stored['sku']]);
        $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => '10', 'sku' => 'KUPA-1', 'i18n' => ['tr' => ['title' => 'İkinci']]], $h)
            ->assertStatus(422)->assertJsonPath('details.fields.sku', 'Bu stok kodu başka bir üründe kullanılıyor.');

        // Araç Pazarı'nda kapatılınca uçları 404.
        $this->putJson('/v1/system/tools/commerce', ['enabled' => false], $h)->assertOk();
        $this->getJson('/v1/shop/cart')->assertNotFound();
        $this->getJson('/v1/admin/commerce/orders', $h)->assertNotFound();
    }

    public function test_cart_checkout_bank_transfer_and_order_flow(): void
    {
        Mail::fake();
        $h = $this->admin();
        $kupa = $this->product($h, 'Seramik Kupa', '149,90', ['track_stock' => true, 'stock' => 3]);
        $ebook = $this->product($h, 'E-kitap', '50', ['digital' => true]);

        $this->getJson('/v1/shop/cart')->assertOk()->assertJsonPath('count', 0);
        $add = $this->postJson('/v1/shop/cart/items', ['product' => $kupa, 'quantity' => 2])->assertOk()
            ->assertJsonPath('count', 2)->assertJsonPath('totals.subtotal', '299,80 ₺')->assertJsonPath('totals.shipping', '50,00 ₺');
        $token = $add->getCookie('km_cart', false)->getValue();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
        $this->withUnencryptedCookie('km_cart', $token);

        $this->postJson('/v1/shop/cart/items', ['product' => $kupa, 'quantity' => 2])->assertStatus(422)->assertJsonPath('details.fields.quantity', '"Seramik Kupa" için yeterli stok yok.');
        $this->postJson('/v1/shop/cart/items', ['product' => $ebook, 'quantity' => 1])->assertOk()->assertJsonPath('count', 3);
        $cart = $this->getJson('/v1/shop/cart')->assertOk()->json();
        $this->assertSame(['bank_transfer'], array_column($cart['payments'], 'key'), 'kapıda ödeme kapalı');
        $this->assertSame(29980 + 5000 + 5000, $cart['amounts']['total']);

        // Kupon: sepette denemek kullanımı saymaz.
        $this->postJson('/v1/admin/commerce/coupons', ['code' => 'hosgeldin', 'type' => 'percent', 'value' => 10], $h)->assertOk()->assertJsonPath('code', 'HOSGELDIN');
        $this->postJson('/v1/shop/cart/coupon', ['code' => 'YOK'])->assertStatus(422);
        $this->postJson('/v1/shop/cart/coupon', ['code' => ' hosgeldin '])->assertOk()->assertJsonPath('coupon', 'HOSGELDIN')->assertJsonPath('amounts.discount', 3498);
        $this->assertSame(0, (int) DB::table('commerce_coupons')->value('used'));

        // Eksik bilgi, sözleşme onayı, ödeme yöntemi.
        $this->checkout(['address' => ['line' => '', 'city' => ''], 'phone' => ''])->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['address.line', 'address.city', 'phone']]]);
        $this->checkout(['terms' => false])->assertStatus(422)->assertJsonPath('details.fields.terms', 'Satış sözleşmesini ve ön bilgilendirme formunu onaylayın.');
        $this->checkout(['payment' => 'cash_on_delivery'])->assertStatus(422)->assertJsonPath('details.fields.payment', 'Bir ödeme yöntemi seçin.');

        $placed = $this->checkout()->assertCreated()->json();
        // Numara siparişin kimliğinden (S-000001 biçimi; MySQL ve PostgreSQL'de testler arası kimlik sıfırlanmaz).
        $number = $placed['order']['number'];
        $this->assertMatchesRegularExpression('/^S-\d{6}$/', $number);
        $this->assertSame('pending', $placed['order']['status']);
        $this->assertSame('Ödeme bekliyor', $placed['order']['statusLabel']);
        $this->assertSame('TR00 0000 0000 0000 0000 0000 00', $placed['payment']['instructions']['IBAN']);
        $this->assertArrayNotHasKey('customer_email', $placed['order'], 'müşteri görünümünde iç alan yok');
        $this->assertSame(1, (int) DB::table('commerce_coupons')->value('used'), 'kupon sipariş verilince sayılır');
        $this->assertSame(1, $this->stock($kupa), 'stok düştü');
        $this->assertSame(0, DB::table('commerce_carts')->count(), 'sepet silindi');
        $this->assertSame('ayse@ornek.test', DB::table('commerce_orders')->value('customer_email'));
        Mail::assertSent(SiteMail::class, static fn (SiteMail $m) => $m->hasTo('ayse@ornek.test') && str_contains($m->subjectLine, 'Siparişinizi aldık: '.$number));
        Mail::assertSent(SiteMail::class, static fn (SiteMail $m) => $m->hasTo('yonetici@kodmob.test') && str_contains($m->subjectLine, 'Yeni sipariş: '.$number));
        $this->assertSame(1, DB::table('notifications')->where('type', 'commerce.order_placed')->count(), 'panel bildirimi');

        // Müşteri bağlantısı: anahtarsız açılmaz.
        $this->getJson('/v1/shop/orders/'.$number.'?token='.$placed['token'])->assertOk()->assertJsonPath('totals.total', '364,82 ₺');
        $this->getJson('/v1/shop/orders/'.$number.'?token='.str_repeat('0', 40))->assertNotFound();

        // Panel: liste, durum akışı, yetki.
        $id = (int) DB::table('commerce_orders')->value('id');
        $this->getJson('/v1/admin/commerce/orders?status=pending', $h)->assertOk()->assertJsonPath('items.0.number', $number)->assertJsonPath('counts.pending', 1);
        $this->getJson('/v1/admin/commerce/orders', $this->as('editor'))->assertForbidden();
        $this->postJson("/v1/admin/commerce/orders/{$id}/status", ['status' => 'shipped'], $h)->assertStatus(422);
        $this->postJson("/v1/admin/commerce/orders/{$id}/status", ['status' => 'paid'], $h)->assertOk()->assertJsonPath('payment_status', 'paid');
        Mail::assertSent(SiteMail::class, static fn (SiteMail $m) => $m->hasTo('ayse@ornek.test') && str_contains($m->subjectLine, 'Ödendi'));
        $this->postJson("/v1/admin/commerce/orders/{$id}/status", ['status' => 'cancelled'], $h)->assertOk()->assertJsonPath('next', []);
        $this->assertSame(3, $this->stock($kupa), 'iptalde stok geri geldi');
        $this->assertGreaterThanOrEqual(1, DB::table('events')->where('event_type', 'commerce.order_status')->count(), 'işlem geçmişi');
    }

    public function test_coupon_limits_and_validation(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/commerce/coupons', ['code' => 'x', 'type' => 'percent', 'value' => 150], $h)->assertStatus(422)
            ->assertJsonStructure(['details' => ['fields' => ['code', 'value']]]);
        $this->postJson('/v1/admin/commerce/coupons', ['code' => 'TEK', 'type' => 'fixed', 'value' => 1000, 'usage_limit' => 1], $h)->assertOk();
        $kupa = $this->product($h, 'Kupa', '100');
        foreach ([201, 422] as $i => $expected) {
            $cookie = $this->postJson('/v1/shop/cart/items', ['product' => $kupa, 'quantity' => 1])->getCookie('km_cart', false)->getValue();
            $this->withUnencryptedCookie('km_cart', $cookie);
            if ($i === 0) {
                $this->postJson('/v1/shop/cart/coupon', ['code' => 'TEK'])->assertOk();
                $this->checkout()->assertStatus($expected);
            } else {
                $this->postJson('/v1/shop/cart/coupon', ['code' => 'TEK'])->assertStatus(422)->assertJsonPath('details.fields.coupon', 'Kuponun kullanım sınırı doldu.');
            }
        }
    }

    private function stock(int $id): int
    {
        return (int) json_decode((string) DB::table('contents')->where('id', $id)->value('data'), true)['stock'];
    }
}
