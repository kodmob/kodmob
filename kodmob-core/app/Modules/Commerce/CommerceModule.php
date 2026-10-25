<?php

namespace App\Modules\Commerce;

use App\Core\Database\BackupPart;
use App\Core\Events\EventRecorded;
use App\Core\Health\Health;
use App\Core\Module;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Core\Support\PanelUrl;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\Commerce\Lines\CartLines;
use App\Modules\Commerce\Payments\Payments;
use App\Modules\Notifications\NotificationService;
use App\Modules\Site\Render\Router;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;

/**
 * Mağaza (isteğe bağlı araç `commerce`; docs/done-commerce-raporu.md): ürünler (içerik türü), sepet, ödeme adımı,
 * siparişler, kuponlar; havale ve kapıda ödeme. Online ödeme sağlayıcıları (Stripe, PayTR) ve Rezervasyon bu
 * modüle takılan ayrı modüllerdir (`requires: ['commerce']`).
 *
 * Uçlar routes/api.php'de (`module:commerce`): ziyaretçi `/shop/…`, panel `/admin/commerce/…`.
 */
final class CommerceModule extends Module
{
    public const VIEW_ORDERS = 'km_view_orders';

    public const MANAGE_ORDERS = 'km_manage_orders';

    public const MANAGE_COUPONS = 'km_manage_coupons';

    public const CURRENCY = 'commerce.currency';

    public const INCLUDE_TAX = 'commerce.include_tax';

    public const SHIPPING_FEE = 'commerce.shipping_fee';

    public const FREE_SHIPPING_OVER = 'commerce.free_shipping_over';

    public const NOTIFY_EMAIL = 'commerce.notify_email';

    public const ORDER_PREFIX = 'commerce.order_prefix';

    public const BANK_ENABLED = 'commerce.bank_enabled';

    public const BANK_NAME = 'commerce.bank_name';

    public const BANK_HOLDER = 'commerce.bank_holder';

    public const BANK_IBAN = 'commerce.bank_iban';

    public const COD_ENABLED = 'commerce.cod_enabled';

    public const COD_FEE = 'commerce.cod_fee';

    /** Dokunulmayan sepetin silinmesi (gün). */
    private const CART_DAYS = 30;

    public function capabilities(): array
    {
        return [
            self::VIEW_ORDERS => ['label' => 'Siparişleri görme', 'group' => 'Mağaza'],
            self::MANAGE_ORDERS => ['label' => 'Sipariş durumunu değiştirme (ödendi, kargoda, iptal)', 'group' => 'Mağaza'],
            self::MANAGE_COUPONS => ['label' => 'Kuponları yönetme', 'group' => 'Mağaza'],
        ];
    }

    public function contentTypes(): array
    {
        return [ProductType::class];
    }

    /** Siparişler müşterinin kişisel verisidir (ad, adres, telefon): yedekte ayrıca seçilir. Sepetler geçicidir, girmez. */
    public function backup(): array
    {
        return [new BackupPart('commerce', 'Siparişler, ödemeler ve kuponlar', [
            'commerce_coupons', 'commerce_orders', 'commerce_order_items', 'commerce_payments', 'commerce_coupon_uses',
        ], personal: true, contentLinks: ['commerce_order_items' => ['product_id']])];
    }

    /** Sıradaki adımlar: ödeme yöntemi olmadan sipariş verilemez. */
    /** Genel bakış: bu ayın satışı ve son siparişler. */
    public function widgets(): array
    {
        $labels = ['pending' => ['Ödeme bekliyor', 'warning'], 'paid' => ['Ödendi', 'success'], 'processing' => ['Hazırlanıyor', 'info'], 'shipped' => ['Kargoda', 'info'],
            'completed' => ['Tamamlandı', 'success'], 'cancelled' => ['İptal', 'neutral'], 'refunded' => ['İade', 'neutral']];

        return [
            ['key' => 'sales', 'label' => 'Satış', 'icon' => 'cart', 'type' => 'stats', 'size' => 'md', 'order' => 25, 'can' => self::VIEW_ORDERS,
                'link' => ['route' => 'orders', 'label' => 'Siparişler'], 'description' => 'Bu ayın siparişleri ve ödenen tutar; bekleyenler.',
                'data' => static function () {
                    $month = Clock::carbon(Clock::now())->startOfMonth()->format('Y-m-d H:i:s');
                    $orders = DB::table('commerce_orders');
                    $paid = (clone $orders)->where('payment_status', 'paid')->where('paid_at', '>=', $month);

                    return ['items' => [
                        ['label' => 'Bu ay sipariş', 'value' => (clone $orders)->where('created_at', '>=', $month)->count(), 'route' => 'orders'],
                        ['label' => 'Bu ay ödenen', 'value' => Money::format((int) (clone $paid)->sum('total'))],
                        ['label' => 'Ödeme bekleyen', 'value' => (clone $orders)->where('status', 'pending')->count(), 'route' => 'orders'],
                        ['label' => 'Hazırlanacak', 'value' => (clone $orders)->whereIn('status', ['paid', 'processing'])->count(), 'route' => 'orders'],
                    ]];
                }],
            ['key' => 'orders', 'label' => 'Son siparişler', 'icon' => 'cart', 'type' => 'list', 'size' => 'md', 'order' => 26, 'can' => self::VIEW_ORDERS,
                'link' => ['route' => 'orders', 'label' => 'Tümü'],
                'data' => static fn () => ['empty' => 'Henüz sipariş yok.', 'items' => DB::table('commerce_orders')->orderByDesc('id')->limit(5)->get()
                    ->map(static fn ($o) => [
                        'title' => $o->number.' · '.$o->customer_name, 'meta' => Money::format((int) $o->total, (string) $o->currency), 'time' => Clock::local((string) $o->created_at),
                        'badge' => $labels[$o->status] ?? null, 'route' => 'orders', 'params' => ['id' => (int) $o->id],
                    ])->all()]],
        ];
    }

    public function steps(): array
    {
        $methods = array_filter(Payments::all(), static fn ($m) => $m->available(true) || $m->available(false));

        return [['key' => 'payments', 'order' => 45, 'label' => 'Ödeme yöntemini seçin', 'hint' => 'Havale / EFT, kapıda ödeme ya da Araç Pazarı\'ndan Stripe, PayTR; yoksa ziyaretçi sipariş veremez.',
            'done' => $methods !== [], 'route' => 'projectSettings', 'anchor' => self::BANK_ENABLED, 'can' => 'km_manage_settings']];
    }

    public function settings(): array
    {
        $g = 'Mağaza';

        return [
            new SettingDefinition(self::CURRENCY, 'string', 'TRY', 'Para birimi', $g, 'Fiyatlar ve siparişler bu para biriminde. Satış başladıktan sonra değiştirmeyin.', options: Money::CURRENCIES, scope: 'project'),
            new SettingDefinition(self::INCLUDE_TAX, 'bool', true, 'Fiyatlar KDV dahil', $g, 'Kapalıysa KDV ödeme adımında fiyatın üstüne eklenir.', scope: 'project'),
            new SettingDefinition(self::SHIPPING_FEE, 'float', 0, 'Kargo ücreti', $g, 'Kargolanan ürün olan siparişe eklenir. 0: ücretsiz.', 0, 100000, scope: 'project'),
            new SettingDefinition(self::FREE_SHIPPING_OVER, 'float', 0, 'Ücretsiz kargo alt sınırı', $g, 'Bu tutar ve üstü siparişte kargo ücretsiz. 0: kapalı.', 0, 1000000, scope: 'project'),
            new SettingDefinition(self::NOTIFY_EMAIL, 'string', '', 'Sipariş bildirim e-postası', $g, 'Yeni siparişler bu adrese gelir. Boşsa yönetici hesabının e-postası.', scope: 'project'),
            new SettingDefinition(self::ORDER_PREFIX, 'string', 'S', 'Sipariş numarası öneki', $g, 'Ör. S → S-000123.', scope: 'project'),
            new SettingDefinition(self::BANK_ENABLED, 'bool', false, 'Havale / EFT', $g, 'Ödeme adımında havale seçeneği; sipariş ödeme gelince panelde "Ödendi" yapılır.', scope: 'project'),
            new SettingDefinition(self::BANK_NAME, 'string', '', 'Banka adı', $g, scope: 'project'),
            new SettingDefinition(self::BANK_HOLDER, 'string', '', 'Hesap sahibi', $g, scope: 'project'),
            new SettingDefinition(self::BANK_IBAN, 'string', '', 'IBAN', $g, 'Sipariş sonrası sayfada ve e-postada gösterilir.', scope: 'project'),
            new SettingDefinition(self::COD_ENABLED, 'bool', false, 'Kapıda ödeme', $g, 'Yalnızca kargolanan üründe.', scope: 'project'),
            new SettingDefinition(self::COD_FEE, 'float', 0, 'Kapıda ödeme ücreti', $g, 'Siparişe eklenir. 0: ücretsiz.', 0, 10000, scope: 'project'),
        ];
    }

    public function events(): array
    {
        return [
            'commerce.order_placed' => ['Yeni sipariş', 'success', static fn ($p) => trim(($p['number'] ?? '').' · '.($p['name'] ?? '').' · '.($p['total'] ?? ''), ' ·')],
            'commerce.payment_received' => ['Ödeme alındı', 'success', static fn ($p) => trim(($p['number'] ?? '').' · '.($p['total'] ?? '').' · '.($p['provider'] ?? ''), ' ·')],
            'commerce.order_status' => ['Sipariş durumu değişti', 'primary', static fn ($p) => trim(($p['number'] ?? '').' → '.($p['status'] ?? ''), ' →')],
            'commerce.coupon_created' => ['Kupon eklendi', 'success', static fn ($p) => (string) ($p['code'] ?? '')],
            'commerce.coupon_saved' => ['Kupon güncellendi', 'primary', static fn ($p) => (string) ($p['code'] ?? '')],
            'commerce.coupon_deleted' => ['Kupon silindi', 'danger', static fn ($p) => (string) ($p['code'] ?? '')],
        ];
    }

    public function boot(): void
    {
        // Online sağlayıcılar (Stripe, PayTR) kendi boot()'larında ekler: liste her açılışta baştan.
        Payments::reset();
        // Satır türleri: ürün; Rezervasyon gibi takılı modüller kendi türünü ekler.
        CartLines::reset();
        Events::listen(EventRecorded::class, static function (EventRecorded $recorded) {
            $event = $recorded->event;
            if ($event->type !== 'commerce.order_placed') {
                return;
            }
            NotificationService::notifyCapable(
                self::VIEW_ORDERS,
                'commerce.order_placed',
                'Yeni sipariş: '.($event->payload['number'] ?? ''),
                trim(($event->payload['name'] ?? '').' · '.($event->payload['total'] ?? ''), ' ·'),
                PanelUrl::to('orders', ['id' => $event->subjectId]),
                'success',
                ['order_id' => $event->subjectId]
            );
        });
    }

    /**
     * Yasal belgelerdeki mağaza değerleri: para birimi, kargo ücreti ve ücretsiz kargo sınırı (biçimli; 0 ise boş),
     * havale / kapıda ödeme açık mı, kapıda ödeme ücreti, açık ödeme yöntemlerinin listesi (o dilde).
     */
    public function legal(string $locale): array
    {
        $format = static fn (int $minor) => $minor > 0 ? Money::format($minor, null, $locale) : '';
        $methods = array_filter(Payments::all(), static fn ($m) => $m->available(true) || $m->available(false));
        $keys = array_map(static fn ($m) => $m->key(), $methods);
        $cod = Payments::get('cash_on_delivery');

        return ['commerce' => [
            'currency' => Money::currency(),
            'shippingFee' => $format(self::money(self::SHIPPING_FEE)),
            'freeOver' => $format(self::money(self::FREE_SHIPPING_OVER)),
            'bank' => in_array('bank_transfer', $keys, true),
            'cod' => in_array('cash_on_delivery', $keys, true),
            'codFee' => $cod && in_array('cash_on_delivery', $keys, true) ? $format($cod->fee()) : '',
            'payments' => implode(', ', array_map(static fn ($m) => $m->label($locale), $methods)) ?: '—',
        ]];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => DB::table('commerce_carts')->where('updated_at', '<', now()->subDays(self::CART_DAYS)->format('Y-m-d H:i:s'))->delete())
            ->name('commerce:carts')->dailyAt('03:40');
    }

    public function health(): array
    {
        $methods = array_filter(Payments::all(), static fn ($m) => $m->available(true) || $m->available(false));
        $waiting = DB::table('commerce_orders')->where('status', 'pending')->count();
        $flags = [];
        if (! $methods) {
            $flags[] = Health::flag('warn', 'Açık ödeme yöntemi yok: ziyaretçi sipariş veremez.', 'Proje Ayarları > Mağaza: Havale / EFT ya da kapıda ödeme; ya da Araç Pazarı\'ndan bir ödeme sağlayıcısı.');
        }

        return [Health::section('commerce', 'Mağaza', 'cart', [
            ['Ödeme yöntemleri', $methods ? implode(', ', array_map(static fn ($m) => $m->label('tr'), $methods)) : 'yok'],
            ['Ödeme bekleyen sipariş', (string) $waiting],
            ['Para birimi', Money::currency()],
        ], $flags, ['link' => ['route' => 'orders', 'label' => 'Siparişler']])];
    }

    // ------------------------------------------------------------ yardımcılar

    /** Ondalıklı para ayarı → kuruş. */
    public static function money(string $key): int
    {
        return (int) round((float) SettingsService::instance()->get($key) * 100);
    }

    /** @return array{include_tax:bool,shipping_fee:int,free_shipping_over:int,fee:int} */
    public static function pricingOptions(int $fee = 0): array
    {
        return [
            'include_tax' => (bool) SettingsService::instance()->get(self::INCLUDE_TAX),
            'shipping_fee' => self::money(self::SHIPPING_FEE),
            'free_shipping_over' => self::money(self::FREE_SHIPPING_OVER),
            'fee' => $fee,
        ];
    }

    public static function notifyAddress(): string
    {
        $configured = trim(SettingsService::instance()->string(self::NOTIFY_EMAIL));
        if (Text::isEmail($configured)) {
            return $configured;
        }

        return (string) DB::table('users')->where('role', 'admin')->where('status', 'active')->orderBy('id')->value('email');
    }

    /** Müşterinin sipariş sayfası (sitenin `shopOrder` rotası; profilde yoksa boş). */
    public static function orderUrl(string $number, string $token, string $locale): string
    {
        $path = Router::load(Paths::siteRoot())?->url('shopOrder', $locale);

        return $path === null ? '' : rtrim((string) config('app.url'), '/').$path.'?no='.rawurlencode($number).'&anahtar='.$token;
    }
}
