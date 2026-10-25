<?php

namespace Tests\Feature;

use App\Core\Modules;
use App\Core\Settings\SettingsService;
use App\Modules\Commerce\CommerceModule;
use App\Modules\Commerce\Paytr\PaytrGateway;
use App\Modules\Commerce\Paytr\PaytrModule;
use App\Modules\Commerce\Stripe\StripeModule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mağaza'ya takılı online ödeme modülleri (Stripe, PayTR; docs/done-commerce-raporu.md). Ağa çıkılmaz (Http::fake);
 * sağlayıcıların gerçek hesaplarla denenmesi dikkat.md'de.
 */
class PaymentProvidersTest extends TestCase
{
    private const ENV = 'blog,briefs,commerce,stripe,paytr';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
        $this->withCredentials();
        Mail::fake();
        $s = SettingsService::instance();
        $s->set(StripeModule::SECRET_KEY, 'sk_test_gizli');
        $s->set(StripeModule::WEBHOOK_SECRET, 'whsec_gizli');
        $s->set(PaytrModule::MERCHANT_ID, '123456');
        $s->set(PaytrModule::MERCHANT_KEY, 'paytr-anahtar');
        $s->set(PaytrModule::MERCHANT_SALT, 'paytr-tuz');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    /** Son siparişin numarası (kimlikten; testler arası sıfırlanmayan veritabanlarında 1 değil). */
    private string $number = '';

    /** Sepette 2 × 100,00 dijital ürün (kargo yok), seçilen yöntemle sipariş. */
    private function order(string $payment): array
    {
        $h = $this->admin();
        $id = (int) $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => '100', 'tax_rate' => '20', 'digital' => true, 'i18n' => ['tr' => ['title' => 'E-kitap']]], $h)->assertCreated()->json('id');
        $token = $this->postJson('/v1/shop/cart/items', ['product' => $id, 'quantity' => 2])->assertOk()->getCookie('km_cart', false)->getValue();
        $this->withUnencryptedCookie('km_cart', $token);

        $placed = $this->postJson('/v1/shop/checkout', ['name' => 'Ali Veli', 'email' => 'ali@ornek.test', 'payment' => $payment, 'terms' => true, 'locale' => 'tr'])->assertCreated()->json();
        $this->number = $placed['order']['number'];

        return $placed;
    }

    private function stripeEvent(string $session, int $amount, ?int $time = null, string $secret = 'whsec_gizli'): TestResponse
    {
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $session, 'payment_status' => 'paid', 'amount_total' => $amount, 'metadata' => ['order' => $this->number]]]]);
        $time ??= time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);

        return $this->call('POST', '/v1/shop/payments/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_secret_settings_never_leave_the_server(): void
    {
        $h = $this->admin();
        $stored = (string) DB::table('settings')->where('setting_key', StripeModule::SECRET_KEY)->value('value');
        $this->assertStringStartsWith('enc:', $stored, 'mühürlü');
        $this->assertSame('sk_test_gizli', SettingsService::instance()->string(StripeModule::SECRET_KEY));

        $item = collect($this->getJson('/v1/settings', $h)->assertOk()->json('items'))->firstWhere('key', StripeModule::SECRET_KEY);
        $this->assertSame(['', true, 'secret'], [$item['value'], $item['set'], $item['format']]);
        $this->putJson('/v1/settings', ['values' => [StripeModule::SECRET_KEY => 'sk_test_yeni']], $h)->assertOk();
        $this->assertStringNotContainsString('sk_test', (string) DB::table('events')->where('event_type', 'settings.updated')->value('payload'), 'işlem geçmişinde maskeli');
        $this->assertArrayNotHasKey(StripeModule::SECRET_KEY, $this->getJson('/v1/admin/backup', $h)->json('settings') ?? []);
    }

    public function test_stripe_checkout_session_and_signed_webhook(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
        $placed = $this->order('stripe');
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $placed['payment']['redirect']);
        Http::assertSent(static fn (HttpRequest $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $r->hasHeader('Authorization', 'Bearer sk_test_gizli')
            && $r['line_items'][0]['price_data']['unit_amount'] == 20000 && $r['line_items'][0]['price_data']['currency'] === 'try'
            && str_contains($r['success_url'], 'odeme=tamam'));
        $this->assertSame('cs_test_1', DB::table('commerce_payments')->value('reference'));
        $this->assertTrue($this->getJson('/v1/shop/orders/'.$this->number.'?token='.$placed['token'])->json('payable'), 'ödemeyi tamamla düğmesi');

        // İmzasız, yanlış anahtarla ya da eski zamanla gelen bildirim reddedilir.
        $this->postJson('/v1/shop/payments/stripe', ['type' => 'checkout.session.completed'])->assertStatus(400);
        $this->stripeEvent('cs_test_1', 20000, null, 'whsec_baska')->assertStatus(400);
        $this->stripeEvent('cs_test_1', 20000, time() - 3600)->assertStatus(400);
        // Eksik tutar ödendi saymaz.
        $this->stripeEvent('cs_test_1', 100)->assertOk();
        $this->assertSame('pending', DB::table('commerce_orders')->value('status'));

        $this->stripeEvent('cs_test_1', 20000)->assertOk()->assertJsonPath('received', true);
        $this->stripeEvent('cs_test_1', 20000)->assertOk();
        $this->assertSame(['paid', 'paid'], [DB::table('commerce_orders')->value('status'), DB::table('commerce_orders')->value('payment_status')]);
        $this->assertSame(1, DB::table('events')->where('event_type', 'commerce.payment_received')->count(), 'ikinci bildirim etkisiz');
        $this->assertFalse($this->getJson('/v1/shop/orders/'.$this->number.'?token='.$placed['token'])->json('payable'));
    }

    public function test_paytr_token_and_hash_checked_callback(): void
    {
        Http::fake(['www.paytr.com/*' => Http::response(['status' => 'success', 'token' => 'tok123'])]);
        $placed = $this->order('paytr');
        $this->assertSame('https://www.paytr.com/odeme/guvenli/tok123', $placed['payment']['redirect']);
        $oid = (string) DB::table('commerce_payments')->value('reference');
        $this->assertMatchesRegularExpression('/^'.str_replace('-', '', $this->number).'[0-9A-F]{6}$/', $oid);
        Http::assertSent(static function (HttpRequest $r) use ($oid) {
            $hash = $r['merchant_id'].$r['user_ip'].$r['merchant_oid'].$r['email'].$r['payment_amount'].$r['user_basket'].$r['no_installment'].$r['max_installment'].$r['currency'].$r['test_mode'];

            return $r->url() === PaytrGateway::TOKEN_API && $r['merchant_oid'] === $oid && $r['payment_amount'] === '20000' && $r['currency'] === 'TL'
                && $r['test_mode'] === '1' && $r['paytr_token'] === PaytrGateway::sign($hash.'paytr-tuz', 'paytr-anahtar')
                && json_decode(base64_decode($r['user_basket']), true) === [['E-kitap', '100.00', 2]];
        });

        $callback = fn (string $status, string $total, ?string $hash = null) => $this->post('/v1/shop/payments/paytr', [
            'merchant_oid' => $oid, 'status' => $status, 'total_amount' => $total,
            'hash' => $hash ?? PaytrGateway::sign($oid.'paytr-tuz'.$status.$total, 'paytr-anahtar'),
        ]);
        $callback('success', '20000', 'yanlis')->assertStatus(400);
        $this->assertSame('pending', DB::table('commerce_orders')->value('status'));
        $this->assertSame('OK', $callback('success', '20000')->assertOk()->getContent());
        $this->assertSame('paid', DB::table('commerce_orders')->value('status'));
    }

    public function test_providers_need_the_shop_and_their_keys(): void
    {
        $this->assertSame(['commerce'], Modules::requires('stripe'));
        $this->assertEqualsCanonicalizing(['stripe', 'paytr', 'booking'], Modules::dependents('commerce'));
        $h = $this->admin();
        $id = (int) $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => '10', 'i18n' => ['tr' => ['title' => 'Ürün']]], $h)->json('id');
        $this->postJson('/v1/shop/cart/items', ['product' => $id, 'quantity' => 1])->assertOk()
            ->assertJsonPath('payments.*.key', ['stripe', 'paytr']);

        SettingsService::instance()->set(PaytrModule::MERCHANT_SALT, '');
        $this->getJson('/v1/shop/cart')->assertJsonPath('payments.*.key', ['stripe'], 'anahtarı eksik sağlayıcı seçilemez');

        // Mağaza kapanınca ona takılı ödeme modülleri de çalışmaz.
        $this->putJson('/v1/system/tools/commerce', ['enabled' => false], $h)->assertOk();
        $this->assertNotContains('stripe', Modules::keys());
        $this->postJson('/v1/shop/payments/stripe')->assertNotFound();
        $this->putJson('/v1/system/tools/stripe', ['enabled' => true], $h)->assertStatus(422);
        $this->assertSame(CommerceModule::class, Modules::OPTIONAL['commerce']['class']);
    }
}
