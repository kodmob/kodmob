<?php

namespace Tests\Feature;

use App\Core\Content\ContentType;
use App\Core\Content\Types;
use App\Core\Settings\SettingsService;
use App\Modules\Commerce\Booking\Bookable;
use App\Modules\Commerce\Booking\BookingFields;
use App\Modules\Commerce\Booking\BookingModule;
use App\Modules\Commerce\Booking\BookingProfile;
use App\Modules\Commerce\Booking\BookingView;
use App\Modules\Commerce\Booking\TourType;
use App\Modules\Commerce\CommerceModule;
use App\Modules\Commerce\Hotels\HotelType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rezervasyon profilleri (docs/done-onyuz-raporu.md §2): içerik türü Bookable ile profil verir; alanlar, fiyat, sepet
 * satırı ve sitedeki kutu profilden. Kişi başı (tur, paketlerle), gün başı (kiralık araç: tarih aralığı, günlük
 * adet), sabit fiyat (transfer: kişi sınırı, günlük rezervasyon sayısı) ve gece başı oda (otel: oda tipi × pansiyon,
 * oda tipi başına adet; Oteller aracı).
 */
class BookingProfilesTest extends TestCase
{
    private const ENV = 'blog,briefs,commerce,booking,hotels';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
        // Projenin rezerve edilebilen türleri (örnek): kiralık araç ve transfer.
        config(['project.content_types' => [...config('project.content_types'), TestCarType::class, TestTransferType::class]]);
        Types::forget();
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
        Types::forget();
    }

    private function day(int $ahead): string
    {
        return date('Y-m-d', strtotime("+{$ahead} days"));
    }

    /** Kayıt: tur panelin ucuyla; örnek türler türün kaydıyla (içerik rotaları açılıştaki türlerle kurulur). */
    private function create(string $type, array $data, array $h): int
    {
        $input = ['status' => 'publish', 'tax_rate' => '20'] + $data;
        if (in_array($type, ['tours', 'hotels'], true)) {
            return (int) $this->postJson("/v1/admin/content/{$type}", $input, $h)->assertCreated()->json('id');
        }

        return Types::get($type)->save(null, $input);
    }

    /** Sepete ekle ve havaleyle sipariş ver (çerez sepeti taşır). */
    private function order(int $id, array $data): void
    {
        $cart = $this->postJson('/v1/shop/cart/items', ['type' => 'booking', 'product' => $id, 'data' => $data])->assertOk();
        $this->withUnencryptedCookie('km_cart', $cart->getCookie('km_cart', false)->getValue());
        $this->postJson('/v1/shop/checkout', ['name' => 'Ayşe Yılmaz', 'email' => 'ayse@ornek.test', 'payment' => 'bank_transfer', 'terms' => true, 'locale' => 'tr'])->assertCreated();
    }

    public function test_profile_shapes_the_fields(): void
    {
        $tour = new TourType;
        $this->assertSame('person', $tour->bookingProfile()->unit);
        $this->assertArrayHasKey('packages', $tour->shared());
        $this->assertArrayHasKey('group_discounts', $tour->shared());
        $this->assertSame(['package_texts', 'highlights', 'itinerary', 'includes', 'excludes', 'faq', 'meeting', 'title', 'slug', 'summary', 'body'], array_values(array_unique([...array_keys(BookingFields::localized($tour->bookingProfile())), ...array_keys($tour->localized())])));
        // Gün biriminde grup indirimi ve tarih listesi yok; sabitte kişi sınırı var.
        $car = new BookingProfile(BookingProfile::DAY, ['groups', 'dates', 'capacity']);
        $this->assertSame(['capacity'], $car->features);
        $this->assertSame(['price_day', 'min_days', 'tax_rate', 'capacity'], array_keys(BookingFields::shared($car)));
        $this->assertArrayHasKey('max_persons', BookingFields::shared(new BookingProfile(BookingProfile::FIXED)));
        // Gece biriminde paket yerine oda tipleri; grup indirimi ve tarih listesi yok.
        $hotel = (new HotelType)->bookingProfile();
        $this->assertSame(['night', ['seasons', 'early', 'capacity']], [$hotel->unit, (new BookingProfile(BookingProfile::NIGHT, BookingProfile::FEATURES))->features]);
        $this->assertSame(['price_night', 'min_nights', 'room_persons', 'tax_rate', 'capacity', 'check_in', 'check_out', 'rooms', 'seasons', 'early_bird'], array_keys(BookingFields::shared($hotel)));
        $this->assertSame(['price_bb', 'price_ai'], [array_keys(BookingFields::shared($hotel)['rooms']['columns'])[4], array_keys(BookingFields::shared($hotel)['rooms']['columns'])[7]]);
        $this->assertSame(['room_texts', 'highlights', 'amenities', 'includes', 'faq'], array_keys(BookingFields::localized($hotel)));
        $this->expectException(\InvalidArgumentException::class);
        new BookingProfile('hour');
    }

    public function test_tour_packages_set_the_price_and_the_line(): void
    {
        $h = $this->admin();
        $date = $this->day(10);
        $id = $this->create('tours', [
            'price_adult' => '1000', 'price_child' => '500',
            'packages' => [['key' => 'vip', 'price' => '1500', 'price_child' => '800', 'compare' => '1800'], ['key' => '', 'price' => '1200']],
            'group_discounts' => [['min' => '4', 'percent' => '10']],
            'i18n' => ['tr' => ['title' => 'Mostar Turu', 'slug' => 'mostar', 'package_texts' => [['name' => 'VIP', 'subtitle' => 'Özel araç'], ['name' => 'Standart', 'subtitle' => '']],
                'itinerary' => [['title' => 'Sabah', 'text' => 'Yola çıkış']], 'faq' => [['q' => 'Rehber var mı?', 'a' => 'Evet.']]]],
        ], $h);

        $quote = fn (array $extra) => $this->postJson('/v1/shop/bookings/quote', ['tour' => $id, 'date' => $date, 'adults' => 2, 'children' => 1, 'locale' => 'tr'] + $extra);
        // Seçim yoksa ilk paket; paketin çocuk fiyatı yoksa temel çocuk fiyatı.
        $quote([])->assertOk()->assertJsonPath('total', 380000)->assertJsonPath('package', 'vip');
        $quote(['package' => 'p2'])->assertOk()->assertJsonPath('total', 290000);
        $quote(['package' => 'yok'])->assertStatus(422)->assertJsonPath('details.fields.package', 'Geçerli bir paket seçin.');

        // Sitedeki kutunun özeti: başlayan fiyat, paket adları, kademeler; sayfa bölümleri.
        $entry = (new TourType)->publicList('tr')[0];
        $this->assertSame(['person', 100000, 'VIP', 'Özel araç', 180000], [$entry['booking']['unit'], $entry['booking']['from'], $entry['booking']['packages'][0]['name'], $entry['booking']['packages'][0]['subtitle'], $entry['booking']['packages'][0]['compare']]);
        $this->assertSame([['min' => 4, 'percent' => 10]], $entry['booking']['groups']);
        $this->assertSame('Sabah', $entry['itinerary'][0]['title']);
        $this->assertSame('Rehber var mı?', $entry['faq'][0]['q']);

        $this->order($id, ['date' => $date, 'adults' => 2, 'children' => 1, 'package' => 'vip']);
        $this->assertSame(['package' => 'vip', 'persons' => 3], (array) DB::table('commerce_bookings')->select('package', 'persons')->first());
        $this->assertStringContainsString('Mostar Turu · VIP · ', (string) DB::table('commerce_order_items')->value('title'));
    }

    public function test_night_unit_prices_rooms_and_boards_and_holds_room_stock(): void
    {
        $h = $this->admin();
        $id = $this->create('hotels', [
            'price_night' => '1000', 'min_nights' => 2, 'room_persons' => 2, 'capacity' => 5, 'check_in' => '14:00', 'check_out' => '12:00', 'stars' => '4', 'location' => 'Göreme',
            'rooms' => [
                ['key' => 'standart', 'persons' => 2, 'stock' => 1, 'price_bb' => '1500', 'price_hb' => '2000'],
                ['key' => 'aile', 'persons' => 4, 'stock' => 0, 'price_ai' => '4000'],
            ],
            'i18n' => ['tr' => ['title' => 'Kaya Otel', 'slug' => 'kaya-otel', 'room_texts' => [['name' => 'Standart oda', 'subtitle' => 'Bahçe manzaralı'], ['name' => 'Aile odası', 'subtitle' => '']], 'amenities' => ['Wi-Fi', 'Havuz']]],
        ], $h);
        $quote = fn (array $extra) => $this->postJson('/v1/shop/bookings/quote', $extra + ['item' => $id, 'locale' => 'tr', 'date' => $this->day(10), 'end' => $this->day(13), 'adults' => 2]);

        // Seçim yoksa ilk seçenek (standart, oda kahvaltı): 3 gece × 1 oda × 1.500.
        $quote([])->assertOk()->assertJsonPath('unit', 'night')->assertJsonPath('days', 3)->assertJsonPath('total', 450000)
            ->assertJsonPath('room', 'standart')->assertJsonPath('board', 'bb')->assertJsonPath('remaining', 1);
        $quote(['room' => 'aile', 'board' => 'ai', 'quantity' => 2, 'children' => 3])->assertOk()->assertJsonPath('total', 2400000)->assertJsonPath('remaining', 5);
        // En az gece, sunulmayan pansiyon, oda kapasitesi (2 kişilik tek oda).
        $quote(['end' => $this->day(11)])->assertStatus(422)->assertJsonPath('details.fields.end', 'En az 2, en çok 60 gece seçin (çıkış girişten sonra).');
        $quote(['room' => 'standart', 'board' => 'ai'])->assertStatus(422)->assertJsonPath('details.fields.room', 'Geçerli bir oda ve pansiyon seçin.');
        $quote(['adults' => 2, 'children' => 1])->assertStatus(422)->assertJsonPath('details.fields.adults', 'En az 1 yetişkin; seçtiğiniz odalarda en çok 2 kişi kalabilir (oda sayısını artırın).');
        // İki oda üç kişiye yeter ama standart odadan tek oda var.
        $quote(['adults' => 2, 'children' => 1, 'quantity' => 2])->assertStatus(422)->assertJsonPath('details.fields.date', 'Bu tarihte yeterli yer yok (kalan: 1).');

        // Sitedeki kutu: oda tipleri ve pansiyonları, başlayan fiyat oda fiyatlarından; olanaklar; yapısal veri Hotel.
        $entry = (new HotelType)->publicList('tr')[0];
        $this->assertSame(['night', 150000, 2, '14:00', '12:00'], [$entry['booking']['unit'], $entry['booking']['from'], $entry['booking']['minDays'], $entry['booking']['checkIn'], $entry['booking']['checkOut']]);
        $room = $entry['booking']['rooms'][0];
        $this->assertSame(['standart', 'Standart oda', 'Bahçe manzaralı', 2, ['bb', 'hb']], [$room['key'], $room['name'], $room['subtitle'], $room['persons'], array_column($room['boards'], 'board')]);
        $this->assertSame(['Wi-Fi', 'Havuz'], $entry['amenities']);
        $graph = (new HotelType)->structuredData($entry, ['description' => 'x', 'url' => 'https://ornek.test/oteller/kaya-otel/', 'image' => '', 'locale' => 'tr']);
        $this->assertSame(['Hotel', 4, 'Göreme', '14:00', 'Wi-Fi'], [$graph['@type'], $graph['starRating']['ratingValue'], $graph['address']['addressLocality'], $graph['checkinTime'], $graph['amenityFeature'][0]['name']]);

        // Standart odadan tek oda var: üç gece dolu, çıkış günü boş; aile odası yalnızca toplam oda sayısından düşer.
        $this->order($id, ['date' => $this->day(10), 'end' => $this->day(13), 'adults' => 2, 'room' => 'standart', 'board' => 'hb']);
        $this->assertSame(['end_date' => $this->day(13), 'package' => 'standart', 'persons' => 1], (array) DB::table('commerce_bookings')->select('end_date', 'package', 'persons')->first());
        $this->assertStringContainsString('Kaya Otel · Standart oda · Yarım pansiyon · ', (string) DB::table('commerce_order_items')->value('title'));
        $quote(['date' => $this->day(12), 'end' => $this->day(14), 'room' => 'standart', 'board' => 'bb'])->assertStatus(422)->assertJsonPath('details.fields.date', 'Bu tarihte yeterli yer yok (kalan: 0).');
        $quote(['date' => $this->day(13), 'end' => $this->day(15), 'room' => 'standart', 'board' => 'bb'])->assertOk()->assertJsonPath('remaining', 1);
        $quote(['date' => $this->day(12), 'end' => $this->day(14), 'room' => 'aile', 'board' => 'ai'])->assertOk()->assertJsonPath('remaining', 4);
    }

    public function test_night_unit_without_room_types_sells_the_nightly_price(): void
    {
        $h = $this->admin();
        $id = $this->create('hotels', ['price_night' => '800', 'room_persons' => 3, 'i18n' => ['tr' => ['title' => 'Bağ Evi', 'slug' => 'bag-evi']]], $h);
        $this->postJson('/v1/shop/bookings/quote', ['item' => $id, 'locale' => 'tr', 'date' => $this->day(5), 'end' => $this->day(6), 'adults' => 3])->assertOk()
            ->assertJsonPath('total', 80000)->assertJsonPath('room', '')->assertJsonPath('remaining', null);
        $entry = (new HotelType)->publicList('tr')[0];
        $this->assertSame([80000, [['key' => '', 'name' => '', 'subtitle' => '', 'persons' => 3, 'boards' => [['board' => '', 'price' => 80000, 'priceText' => $entry['booking']['fromText']]]]]], [$entry['booking']['from'], $entry['booking']['rooms']]);
        // Araç kapalıyken tür yok: kayıt rezerve edilemez.
        $this->assertContains('hotels', array_keys(Types::all()));
    }

    public function test_day_unit_books_a_range_and_holds_capacity_each_day(): void
    {
        $h = $this->admin();
        $id = $this->create('cars', ['price_day' => '500', 'min_days' => 2, 'capacity' => 2, 'i18n' => ['tr' => ['title' => 'Clio', 'slug' => 'clio']]], $h);
        $quote = fn (array $extra) => $this->postJson('/v1/shop/bookings/quote', ['item' => $id, 'locale' => 'tr'] + $extra);

        $quote(['date' => $this->day(10), 'end' => $this->day(11)])->assertStatus(422)->assertJsonPath('details.fields.end', 'En az 2, en çok 60 gün seçin (teslim tarihi alıştan sonra).');
        $quote(['date' => $this->day(10), 'end' => $this->day(13), 'quantity' => 2])->assertOk()
            ->assertJsonPath('days', 3)->assertJsonPath('total', 300000)->assertJsonPath('remaining', 2)->assertJsonPath('unit', 'day');

        // İki araç, 3 gün: her gün dolu; teslim günü boş.
        $this->order($id, ['date' => $this->day(10), 'end' => $this->day(13), 'quantity' => 2]);
        $this->assertSame(['end_date' => $this->day(13), 'persons' => 2], (array) DB::table('commerce_bookings')->select('end_date', 'persons')->first());
        $month = $this->getJson("/v1/shop/bookings/{$id}/availability?month=".substr($this->day(12), 0, 7))->assertOk()->json('days');
        if (substr($this->day(12), 0, 7) === substr($this->day(13), 0, 7)) {
            $this->assertSame([0, 2], [$month[$this->day(12)], $month[$this->day(13)]]);
        }
        $quote(['date' => $this->day(12), 'end' => $this->day(14)])->assertStatus(422)->assertJsonPath('details.fields.date', 'Bu tarihte yeterli yer yok (kalan: 0).');
        $quote(['date' => $this->day(13), 'end' => $this->day(15)])->assertOk()->assertJsonPath('remaining', 2);
        $this->assertStringContainsString('Clio · '.date('d.m.Y', strtotime('+10 days')).' – '.date('d.m.Y', strtotime('+13 days')).' · 3 gün · 2 adet', (string) DB::table('commerce_order_items')->value('title'));
    }

    public function test_fixed_unit_limits_people_and_counts_bookings(): void
    {
        $h = $this->admin();
        $date = $this->day(7);
        $id = $this->create('transfers', ['price_fixed' => '900', 'max_persons' => 4, 'capacity' => 1, 'dates' => [$date], 'i18n' => ['tr' => ['title' => 'Havalimanı transferi', 'slug' => 'havalimani']]], $h);
        $quote = fn (array $extra) => $this->postJson('/v1/shop/bookings/quote', $extra + ['item' => $id, 'date' => $date, 'locale' => 'tr']);

        $quote(['persons' => 5])->assertStatus(422)->assertJsonPath('details.fields.persons', 'Kişi sayısı 1 ile 4 arasında olmalı.');
        $quote(['persons' => 3])->assertOk()->assertJsonPath('total', 90000)->assertJsonPath('persons', 3)->assertJsonPath('consumed', 1);
        $quote(['persons' => 1, 'date' => $this->day(8)])->assertStatus(422);

        $this->order($id, ['date' => $date, 'persons' => 3]);
        $this->assertSame(0, $this->getJson("/v1/shop/bookings/{$id}/availability?month=".substr($date, 0, 7))->json("days.{$date}"));
        $quote(['persons' => 1])->assertStatus(422);
        $row = $this->getJson('/v1/admin/commerce/bookings', $h)->assertOk()->json('items.0');
        $this->assertSame(['Transfer', 'fixed', 1], [$row['kind'], $row['unit'], $row['persons']]);
        $this->assertSame(['fixed', 90000, 4], [(new TestTransferType)->publicList('tr')[0]['booking']['unit'], (new TestTransferType)->publicList('tr')[0]['booking']['from'], (new TestTransferType)->publicList('tr')[0]['booking']['maxPersons']]);
    }
}

/** Örnek: günlük kiralık araç (projenin türü gibi). */
final class TestCarType extends ContentType implements Bookable
{
    public function postType(): string
    {
        return 'km_car';
    }

    public function labels(): array
    {
        return ['Araçlar', 'Araç'];
    }

    public function bookingProfile(): BookingProfile
    {
        return new BookingProfile(BookingProfile::DAY, ['packages', 'seasons', 'early', 'capacity']);
    }

    public function shared(): array
    {
        return BookingFields::shared($this->bookingProfile());
    }

    public function localized(): array
    {
        return ['title' => ['type' => 'text', 'label' => 'Ad'], 'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title']] + BookingFields::localized($this->bookingProfile());
    }

    protected function publicItem(array $item, string $locale): array
    {
        return BookingView::item($this->bookingProfile(), $item, $locale) + ['id' => $item['id'], 'slug' => $item['slug'], 'title' => $item['title']];
    }
}

/** Örnek: sabit fiyatlı transfer. */
final class TestTransferType extends ContentType implements Bookable
{
    public function postType(): string
    {
        return 'km_transfer';
    }

    public function labels(): array
    {
        return ['Transferler', 'Transfer'];
    }

    public function bookingProfile(): BookingProfile
    {
        return new BookingProfile(BookingProfile::FIXED, ['capacity', 'dates'], ['faq']);
    }

    public function shared(): array
    {
        return BookingFields::shared($this->bookingProfile());
    }

    public function localized(): array
    {
        return ['title' => ['type' => 'text', 'label' => 'Ad'], 'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title']] + BookingFields::localized($this->bookingProfile());
    }

    protected function publicItem(array $item, string $locale): array
    {
        return BookingView::item($this->bookingProfile(), $item, $locale) + ['id' => $item['id'], 'slug' => $item['slug'], 'title' => $item['title']];
    }
}
