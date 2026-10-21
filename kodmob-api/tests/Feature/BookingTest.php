<?php

namespace Tests\Feature;

use App\Core\Modules;
use App\Core\Settings\SettingsService;
use App\Modules\Commerce\Booking\BookingModule;
use App\Modules\Commerce\Booking\BookingPricing;
use App\Modules\Commerce\CommerceModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rezervasyon (Mağaza'ya takılı modül `booking`; WordPress sürümünün rezervasyon modülünden): fiyat
 * kuralları, tarih ve kapasite, Mağaza'nın sepeti ve siparişiyle rezervasyon, iptalde yerin geri gelmesi.
 */
class BookingTest extends TestCase
{
    private const ENV = 'blog,briefs,commerce,booking';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
        $this->withCredentials();
        Mail::fake();
        $s = SettingsService::instance();
        $s->set(CommerceModule::BANK_ENABLED, true);
        $s->set(CommerceModule::BANK_IBAN, 'TR00 0000');
        $s->set(BookingModule::LEAD_DAYS, 0);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    private function tour(array $h, array $extra = []): int
    {
        return (int) $this->postJson('/v1/admin/content/tours', ['status' => 'publish', 'price_adult' => '1000', 'price_child' => '500', 'tax_rate' => '20'] + $extra + [
            'i18n' => ['tr' => ['title' => 'Mostar Turu', 'slug' => 'mostar-turu']],
        ], $h)->assertCreated()->json('id');
    }

    private function future(int $days): string
    {
        return date('Y-m-d', strtotime("+{$days} days"));
    }

    public function test_pricing_rules_in_source_plugin_order(): void
    {
        $tour = [
            'price_adult' => 100000, 'price_child' => 50000, 'price_infant' => 0,
            'group_discounts' => [['min' => 4, 'percent' => 10], ['min' => 2, 'percent' => 5]],
            'seasons' => [['from' => '2026-07-01', 'to' => '2026-08-31', 'percent' => 20]],
            'early_bird' => [['days' => 30, 'percent' => 10], ['days' => 60, 'percent' => 15]],
        ];
        // 3 yetişkin + 1 çocuk = 350.000; grup (4 kişi) %10 → 315.000; sezon +%20 → 378.000; 61 gün önce %15 → 321.300.
        $q = BookingPricing::quote($tour, '2026-08-01', 3, 1, 1, '2026-06-01');
        $this->assertSame([350000, 321300, 4], [$q['base'], $q['total'], $q['persons']]);
        $this->assertSame([['kind' => 'group', 'percent' => 10], ['kind' => 'season', 'percent' => 20], ['kind' => 'early', 'percent' => 15]], $q['applied']);
        // Sezon dışı, son dakika, tek kişi: kural yok.
        $q = BookingPricing::quote($tour, '2026-10-02', 1, 0, 0, '2026-10-01');
        $this->assertSame([100000, []], [$q['total'], $q['applied']]);
        $this->assertSame('', BookingPricing::date('2026-02-30'));
    }

    public function test_booking_goes_through_the_shop_and_capacity_holds(): void
    {
        $h = $this->admin();
        $date = $this->future(10);
        $id = $this->tour($h, ['capacity' => 3, 'dates' => [$date, $this->future(20)], 'group_discounts' => [['min' => '5', 'percent' => '10'], ['min' => '', 'percent' => '']]]);
        $this->assertContains('booking', Modules::keys());
        // Yalnızca sayı sütunlu satır da kaydedilir (boş satır atılır).
        $this->assertSame([['min' => 5, 'percent' => 10]], json_decode((string) DB::table('contents')->where('id', $id)->value('data'), true)['group_discounts']);

        // Tarih seçici ve fiyat dökümü.
        $days = $this->getJson("/v1/shop/bookings/{$id}/availability?month=".substr($date, 0, 7))->assertOk()->json('days');
        $this->assertSame(3, $days[$date]);
        $this->postJson('/v1/shop/bookings/quote', ['tour' => $id, 'date' => $date, 'adults' => 2, 'children' => 1, 'locale' => 'tr'])->assertOk()
            ->assertJsonPath('total', 250000)->assertJsonPath('totalText', '2.500,00 ₺')->assertJsonPath('remaining', 3);
        $this->postJson('/v1/shop/bookings/quote', ['tour' => $id, 'date' => $this->future(11), 'adults' => 1])->assertStatus(422)->assertJsonPath('details.fields.date', 'Bu tarihte tur yok; listeden bir tarih seçin.');
        $this->postJson('/v1/shop/bookings/quote', ['tour' => $id, 'date' => $date, 'adults' => 0])->assertStatus(422);
        $this->postJson('/v1/shop/bookings/quote', ['tour' => $id, 'date' => $date, 'adults' => 4])->assertStatus(422)->assertJsonPath('details.fields.date', 'Bu tarihte yeterli yer yok (kalan: 3).');

        // Sepet: rezervasyon satırı (dijital: kargo ve adres yok); aynı tur başka tarihte ayrı satır.
        $cart = $this->postJson('/v1/shop/cart/items', ['type' => 'booking', 'product' => $id, 'data' => ['date' => $date, 'adults' => 2, 'children' => 1]])->assertOk();
        $this->withUnencryptedCookie('km_cart', $cart->getCookie('km_cart', false)->getValue());
        $this->assertStringStartsWith('Mostar Turu · '.date('d.m.Y', strtotime($date)).' · 2 yetişkin, 1 çocuk', $cart->json('items.0.title'));
        $this->assertSame([false, 0], [$cart->json('shippable'), $cart->json('amounts.shipping')]);

        $placed = $this->postJson('/v1/shop/checkout', ['name' => 'Ayşe Yılmaz', 'email' => 'ayse@ornek.test', 'payment' => 'bank_transfer', 'terms' => true, 'locale' => 'tr'])->assertCreated();
        $this->assertSame(250000, $placed->json('order.amounts.total'));
        $this->assertSame(['date' => $date, 'persons' => 3], (array) DB::table('commerce_bookings')->select('date', 'persons')->first());

        // Kapasite: kalan 0; ikinci rezervasyon reddedilir. Panelde listede.
        $this->assertSame(0, $this->getJson("/v1/shop/bookings/{$id}/availability?month=".substr($date, 0, 7))->json("days.{$date}"));
        $this->postJson('/v1/shop/cart/items', ['type' => 'booking', 'product' => $id, 'data' => ['date' => $date, 'adults' => 1]])->assertStatus(422);
        $this->getJson('/v1/admin/commerce/bookings', $h)->assertOk()->assertJsonPath('items.0.number', $placed->json('order.number'))->assertJsonPath('items.0.persons', 3);
        $this->getJson('/v1/admin/commerce/bookings', $this->as('editor'))->assertForbidden();

        // İptal: yer geri gelir.
        $order = (int) DB::table('commerce_orders')->value('id');
        $this->postJson("/v1/admin/commerce/orders/{$order}/status", ['status' => 'cancelled'], $h)->assertOk();
        $this->assertSame(0, DB::table('commerce_bookings')->count());
        $this->assertSame(3, $this->getJson("/v1/shop/bookings/{$id}/availability?month=".substr($date, 0, 7))->json("days.{$date}"));
    }

    public function test_booking_needs_the_shop(): void
    {
        $h = $this->admin();
        $this->assertSame(['commerce'], Modules::requires('booking'));
        $this->putJson('/v1/system/tools/commerce', ['enabled' => false], $h)->assertOk();
        $this->assertNotContains('booking', Modules::keys());
        $this->getJson('/v1/shop/bookings/1/availability')->assertNotFound();
    }
}
