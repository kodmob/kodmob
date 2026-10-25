<?php

namespace Tests\Feature;

use App\Core\Content\Types;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Yedekte kişisel kayıtlar (Module::backup; docs/done-commerce-raporu.md K5): siparişler, talepler, aboneler indirilen
 * yedeğe yalnızca istenince girer, geri yüklemede ayrıca seçilir; kimlikler (sipariş numarası) korunur, siparişteki
 * ürün geri yüklenen ürünün yeni kimliğine bağlanır.
 */
class PersonalBackupTest extends TestCase
{
    private const ENV = 'blog,briefs,estimate,jarvis,domains,sms,sites,commerce,newsletter';

    private const ORDERS = 'Siparişler, ödemeler ve kuponlar';

    protected function setUp(): void
    {
        putenv('KODMOB_MODULES='.self::ENV);
        $_ENV['KODMOB_MODULES'] = $_SERVER['KODMOB_MODULES'] = self::ENV;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('KODMOB_MODULES');
        unset($_ENV['KODMOB_MODULES'], $_SERVER['KODMOB_MODULES']);
    }

    private function order(string $email, ?int $product = null): int
    {
        $now = Clock::now();
        $id = DB::table('commerce_orders')->insertGetId([
            'number' => 'S-'.substr(md5($email), 0, 8), 'token_hash' => hash('sha256', $email), 'status' => 'pending', 'payment_method' => 'bank_transfer',
            'currency' => 'TRY', 'subtotal' => 10000, 'total' => 10000, 'customer_name' => 'Ayşe Yılmaz', 'customer_email' => $email,
            'address' => '{"city":"İzmir"}', 'note' => '', 'locale' => 'tr', 'data' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($product) {
            DB::table('commerce_order_items')->insert(['order_id' => $id, 'product_id' => $product, 'title' => 'Kupa', 'unit_price' => 10000, 'quantity' => 1, 'total' => 10000, 'data' => '{}']);
        }

        return $id;
    }

    public function test_personal_records_are_opt_in_on_download_and_on_restore(): void
    {
        $h = $this->admin();
        $now = Clock::now();
        $product = (int) $this->postJson('/v1/admin/content/products', ['status' => 'publish', 'price' => '100', 'i18n' => ['tr' => ['title' => 'Kupa']]], $h)->assertCreated()->json('id');
        DB::table('commerce_coupons')->insert(['code' => 'YAZ', 'type' => 'percent', 'value' => 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $first = $this->order('ayse@ornek.test', $product);
        DB::table('briefs')->insert(['locale' => 'tr', 'name' => 'Ali', 'email' => 'ali@ornek.test', 'services' => '[]', 'message' => 'Merhaba', 'ip_hash' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('newsletter_subscribers')->insert(['email' => 'veli@ornek.test', 'locale' => 'tr', 'status' => 'active', 'token' => str_repeat('b', 64), 'source' => 'site', 'created_at' => $now, 'updated_at' => $now, 'confirmed_at' => $now]);

        $this->getJson('/v1/admin/backups', $h)->assertOk()->assertJsonPath('personal', [
            'Talepler (iletişim ve teklif formları)', self::ORDERS, 'Bülten aboneleri ve bültenler',
        ]);
        $plain = $this->getJson('/v1/admin/backup', $h)->assertOk()->json();
        $this->assertSame([], (array) $plain['records'], 'kişisel kayıtlar istenmeden indirilen yedeğe girmez');
        $full = $this->getJson('/v1/admin/backup?personal=1', $h)->assertOk()->json();
        $this->assertSame(3, $full['records']['commerce']['count'], 'kupon, sipariş, kalemi');
        $this->assertTrue($full['records']['commerce']['personal']);
        $this->assertSame(1, $full['records']['briefs']['count']);
        $this->assertSame(1, $full['records']['newsletter']['count']);

        // Yedekten sonra: ilk sipariş silindi, yenisi geldi.
        DB::table('commerce_order_items')->delete();
        DB::table('commerce_orders')->delete();
        $this->order('yeni@ornek.test');

        // Kişisel kayıtlar seçilmeden: içerik geri gelir, siparişler olduğu gibi kalır.
        $this->postJson('/v1/admin/backup', $full, $h)->assertOk()
            ->assertJsonPath('records.skipped', ['Talepler (iletişim ve teklif formları)', self::ORDERS, 'Bülten aboneleri ve bültenler']);
        $this->assertSame(['yeni@ornek.test'], DB::table('commerce_orders')->pluck('customer_email')->all());

        // Seçilince: yedekteki siparişler aynı kimlik ve numarayla; ürün bağı geri yüklenen ürüne.
        $result = $this->postJson('/v1/admin/backup', $full + ['personal' => true], $h)->assertOk()
            ->assertJsonPath('counts.commerce', 3)->assertJsonPath('counts.briefs', 1)->json();
        $this->assertContains(self::ORDERS, $result['records']['restored']);
        $this->assertSame([$first], DB::table('commerce_orders')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('S-'.substr(md5('ayse@ornek.test'), 0, 8), DB::table('commerce_orders')->value('number'));
        $restored = Types::get('products')->records(false)[0]->id;
        $this->assertNotSame($product, $restored, 'ürün yeni kimlikle geri yüklendi');
        $this->assertSame($restored, (int) DB::table('commerce_order_items')->value('product_id'));
        $this->assertTrue((bool) DB::table('commerce_coupons')->where('code', 'YAZ')->value('active'));
        $this->assertSame(1, DB::table('newsletter_subscribers')->where('email', 'veli@ornek.test')->count());
        $this->assertGreaterThan($first, $this->order('sonra@ornek.test'), 'kimlik sayacı yedekteki en büyük kimliğin üstünde');

        // Geri yükleme öncesi anlık yedek kişisel kayıtları da taşır (geri alınabilsin).
        $snapshot = $this->getJson('/v1/admin/backups/'.$result['snapshot'], $h)->assertOk()->json();
        $this->assertSame(['yeni@ornek.test'], array_column($snapshot['records']['commerce']['tables']['commerce_orders'], 'customer_email'));
    }
}
