<?php

namespace App\Modules\Commerce;

use App\Core\Content\Locales;
use App\Core\Content\PageCache;
use App\Core\Events\Event;
use App\Core\Events\EventBus;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Mail\SendMail;
use App\Core\Mail\SiteMail;
use App\Core\Search\SearchQuery;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Core\Support\PanelUrl;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Commerce\Lines\CartLines;
use App\Modules\Commerce\Payments\PaymentMethod;
use App\Modules\Commerce\Payments\Payments;
use Illuminate\Support\Facades\DB;

/**
 * Siparişler (`commerce_orders`, `commerce_order_items`, `commerce_payments`).
 *
 * Sipariş verme tek işlemdir: sepet sunucuda yeniden hesaplanır, stok kilitlenip düşülür, kupon kullanımı
 * sayılır, ödeme kaydı açılır; bir adım tutmazsa hiçbiri yazılmaz. Müşteri siparişini numara + bağlantı
 * anahtarıyla görür (anahtar tabloda özetiyle). E-postalar ve panel bildirimi işlem bittikten sonra.
 *
 * Durum akışı (`FLOW`): ödeme bekliyor → ödendi → hazırlanıyor → kargoda → tamamlandı; iptal ve iade. İptal /
 * iadede stok bir kez geri eklenir.
 */
final class Orders
{
    public const STATUSES = ['pending', 'paid', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];

    /** Durum → gidebileceği durumlar. */
    public const FLOW = [
        'pending' => ['paid', 'processing', 'cancelled'],
        'paid' => ['processing', 'shipped', 'completed', 'refunded', 'cancelled'],
        'processing' => ['paid', 'shipped', 'completed', 'cancelled'],
        'shipped' => ['completed', 'refunded'],
        'completed' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    /** Müşteriye e-postayla bildirilen durumlar. */
    private const NOTIFY = ['paid', 'shipped', 'completed', 'cancelled', 'refunded'];

    public function __construct(private readonly EventBus $events = new EventBus) {}

    /**
     * Sepetten sipariş. `$input`: name, email, phone, address{line, city, district, postal, country}, note,
     * payment (yöntem anahtarı), terms (sözleşme onayı).
     *
     * @return array{order:array<string,mixed>,token:string,payment:array<string,mixed>}
     */
    public function place(Cart $cart, array $input, string $locale): array
    {
        if ($cart->isEmpty()) {
            throw ValidationException::field('cart', CommerceText::get('error.empty', $locale));
        }
        $method = Payments::get((string) ($input['payment'] ?? ''));
        $details = $cart->details($locale, $method?->key());
        $quote = $details['quote'];
        if (! $quote['lines']) {
            throw ValidationException::field('cart', CommerceText::get('error.empty', $locale));
        }
        $customer = self::customer($input, $quote['shippable']);
        if ($method === null || ! $method->available($quote['shippable'])) {
            throw ValidationException::field('payment', 'Bir ödeme yöntemi seçin.');
        }
        if (empty($input['terms'])) {
            throw ValidationException::field('terms', 'Satış sözleşmesini ve ön bilgilendirme formunu onaylayın.');
        }
        if ($details['couponError'] !== '') {
            throw ValidationException::field('coupon', $details['couponError']);
        }

        $token = bin2hex(random_bytes(20));
        $now = Clock::now();
        $outOfStock = false;
        $orderId = DB::transaction(function () use ($quote, $details, $customer, $method, $locale, $token, $now, &$outOfStock) {
            $id = (int) DB::table('commerce_orders')->insertGetId([
                'number' => 'tmp-'.bin2hex(random_bytes(6)), 'token_hash' => hash('sha256', $token), 'status' => 'pending',
                'payment_method' => $method->key(), 'payment_status' => 'unpaid', 'currency' => Money::currency(),
                'subtotal' => $quote['subtotal'], 'discount' => $quote['discount'], 'shipping' => $quote['shipping'],
                'fee' => $quote['fee'], 'tax' => $quote['tax'], 'total' => $quote['total'], 'coupon' => $details['coupon'],
                'customer_name' => $customer['name'], 'customer_email' => $customer['email'], 'customer_phone' => $customer['phone'],
                'address' => Text::json($customer['address']), 'note' => $customer['note'], 'locale' => $locale,
                'data' => Text::json(['include_tax' => $details['includeTax']]), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $number = self::number($id);
            DB::table('commerce_orders')->where('id', $id)->update(['number' => $number]);
            foreach ($quote['lines'] as $line) {
                $itemId = (int) DB::table('commerce_order_items')->insertGetId([
                    'order_id' => $id, 'product_id' => $line['product_id'], 'title' => mb_substr($line['title'], 0, 190), 'sku' => $line['sku'],
                    'unit_price' => $line['price'], 'quantity' => $line['quantity'], 'tax_rate' => $line['tax_rate'], 'total' => $line['total'],
                    'data' => Text::json(['type' => $line['type'], 'line' => $line['data'], 'discount' => $line['discount'], 'tax' => $line['tax'], 'digital' => $line['digital']]),
                ]);
                $kind = CartLines::get($line['type']) ?? throw ValidationException::field('cart', CommerceText::get('error.unavailable', $locale, ['title' => $line['title']]));
                $outOfStock = $kind->take(['id' => $line['product_id'], 'quantity' => $line['quantity'], 'data' => $line['data']], $line['title'], $id, $itemId, $locale) || $outOfStock;
            }
            if ($details['coupon_row']) {
                Coupons::redeem((int) $details['coupon_row']['id'], $id);
            }
            DB::table('commerce_payments')->insert([
                'order_id' => $id, 'provider' => $method->key(), 'status' => 'pending', 'amount' => $quote['total'],
                'currency' => Money::currency(), 'reference' => '', 'data' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->events->publish(new Event('commerce.order_placed', 'order', $id, [
                'number' => $number, 'name' => $customer['name'], 'total' => Money::format($quote['total']),
            ], subjectLabel: $number));

            return $id;
        });

        $cart->delete();
        if ($outOfStock) {
            PageCache::flush();
        }
        $order = $this->view($orderId);
        $payment = $this->start($method, $order, $token);
        $this->mailPlaced($order, $token, $payment);

        return ['order' => $order, 'token' => $token, 'payment' => $payment];
    }

    /**
     * Ödemeyi başlatır. Online sağlayıcıya ulaşılamazsa sipariş yine verilmiş sayılır (ödeme bekliyor); müşteri
     * sipariş sayfasından yeniden dener (pay).
     *
     * @return array<string,mixed>
     */
    private function start(PaymentMethod $method, array $order, string $token): array
    {
        try {
            return $method->start($order, CommerceModule::orderUrl($order['number'], $token, $order['locale']));
        } catch (\Throwable $e) {
            report($e);

            return ['error' => CommerceText::get('error.payment', $order['locale'])];
        }
    }

    /** Müşteri ödemeyi (yeniden) başlatır: ödeme bekleyen, online yöntemli sipariş. */
    public function pay(string $number, string $token): array
    {
        $row = $this->customerRow($number, $token);
        $method = Payments::get((string) $row->payment_method);
        if ($method === null || ! $method->online() || $row->payment_status !== 'unpaid' || $row->status !== 'pending') {
            throw new DomainException('km_order_not_payable', 'Bu sipariş için ödeme beklenmiyor.', 409);
        }

        return $this->start($method, $this->view((int) $row->id), $token);
    }

    /** Online sağlayıcının numarası (Stripe oturumu, PayTR sipariş kodu) siparişin bekleyen ödeme kaydına. */
    public static function reference(int $orderId, string $provider, string $reference): void
    {
        $id = DB::table('commerce_payments')->where('order_id', $orderId)->where('provider', $provider)->orderByDesc('id')->value('id');
        DB::table('commerce_payments')->where('id', $id)->update(['reference' => mb_substr($reference, 0, 190), 'updated_at' => Clock::now()]);
    }

    /**
     * Sağlayıcının imzalı bildirimi: ödeme alındı. Aynı bildirim ikinci kez gelirse bir şey yapılmaz; tutar
     * siparişin tutarından azsa işaretlenmez (false).
     */
    public function markPaid(string $provider, string $reference, int $amount): bool
    {
        $payment = DB::table('commerce_payments')->where('provider', $provider)->where('reference', $reference)->orderByDesc('id')->first();
        if ($payment === null) {
            return false;
        }
        if ($payment->status === 'paid') {
            return true;
        }
        $order = $this->view((int) $payment->order_id);
        if ($amount < $order['amounts']['total']) {
            report(new \RuntimeException("[Mağaza] {$provider} bildirimi eksik tutar: {$order['number']} ({$amount} < {$order['amounts']['total']})"));

            return false;
        }
        DB::table('commerce_payments')->where('id', $payment->id)->update(['status' => 'paid', 'amount' => $amount, 'updated_at' => Clock::now()]);
        $this->events->publish(new Event('commerce.payment_received', 'order', $order['id'], [
            'number' => $order['number'], 'provider' => $provider, 'total' => Money::format($amount, $order['currency']),
        ], subjectLabel: $order['number']));
        if ($order['status'] === 'pending') {
            $this->setStatus($order['id'], 'paid');
        } elseif ($order['payment_status'] === 'unpaid') {
            DB::table('commerce_orders')->where('id', $order['id'])->update(['payment_status' => 'paid', 'paid_at' => Clock::now(), 'updated_at' => Clock::now()]);
        }

        return true;
    }

    /** Müşterinin bağlantısıyla sipariş (numara + anahtar). */
    public function forCustomer(string $number, string $token): array
    {
        $order = $this->view((int) $this->customerRow($number, $token)->id);
        $method = Payments::get($order['payment_method']);
        $waiting = $method && $order['payment_status'] === 'unpaid' && $order['status'] === 'pending';
        // Ödeme bekleyen havale siparişinde banka bilgileri yeniden; online yöntemde "Ödemeyi tamamla" (pay).
        $order['payment'] = $waiting && ! $method->online() ? $method->start($order, '') : [];
        $order['payable'] = $waiting && $method->online();

        return self::publicView($order);
    }

    /** Numara + bağlantı anahtarıyla siparişin satırı (anahtar tutmazsa yok sayılır). */
    private function customerRow(string $number, string $token): object
    {
        $row = DB::table('commerce_orders')->where('number', $number)->first();
        if ($row === null || $token === '' || ! hash_equals((string) $row->token_hash, hash('sha256', $token))) {
            throw new DomainException('km_order_not_found', 'Sipariş bulunamadı.', 404);
        }

        return $row;
    }

    /**
     * Panel listesi: durum ve arama (numara, ad, e-posta) süzgeciyle, yeniden eskiye.
     *
     * @return array{items:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function list(string $status = '', string $search = '', int $limit = 300): array
    {
        $query = DB::table('commerce_orders')->orderByDesc('id')->limit(max(1, min(1000, $limit)));
        if (in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (trim($search) !== '') {
            SearchQuery::anyLike($query, ['number', 'customer_name', 'customer_email'], trim($search));
        }
        $counts = DB::table('commerce_orders')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(static fn ($n) => (int) $n)->all();

        return ['items' => $query->get()->map(fn ($row) => self::summary($row))->all(), 'counts' => $counts];
    }

    /** Panel ayrıntısı: sipariş, satırlar, ödemeler, gidebileceği durumlar. */
    public function view(int $id): array
    {
        $row = DB::table('commerce_orders')->where('id', $id)->first();
        if ($row === null) {
            throw new DomainException('km_order_not_found', 'Sipariş bulunamadı.', 404);
        }
        $items = DB::table('commerce_order_items')->where('order_id', $id)->orderBy('id')->get()->map(static fn ($i) => [
            'product_id' => $i->product_id ? (int) $i->product_id : null, 'title' => (string) $i->title, 'sku' => (string) $i->sku,
            'unit_price' => (int) $i->unit_price, 'quantity' => (int) $i->quantity, 'tax_rate' => (int) $i->tax_rate, 'total' => (int) $i->total,
            'unitText' => Money::format((int) $i->unit_price, (string) $row->currency), 'totalText' => Money::format((int) $i->total, (string) $row->currency),
        ])->all();
        $payments = DB::table('commerce_payments')->where('order_id', $id)->orderBy('id')->get()->map(static fn ($p) => [
            'id' => (int) $p->id, 'provider' => (string) $p->provider, 'status' => (string) $p->status, 'amount' => (int) $p->amount,
            'reference' => (string) $p->reference, 'created_at' => (string) $p->created_at,
        ])->all();

        return self::summary($row) + [
            'customer_phone' => (string) $row->customer_phone,
            'address' => (array) json_decode((string) $row->address, true),
            'note' => (string) $row->note,
            'locale' => (string) $row->locale ?: Locales::default(),
            'amounts' => ['subtotal' => (int) $row->subtotal, 'discount' => (int) $row->discount, 'shipping' => (int) $row->shipping, 'fee' => (int) $row->fee, 'tax' => (int) $row->tax, 'total' => (int) $row->total],
            'totals' => array_map(static fn ($v) => Money::format((int) $v, (string) $row->currency), ['subtotal' => $row->subtotal, 'discount' => $row->discount, 'shipping' => $row->shipping, 'fee' => $row->fee, 'tax' => $row->tax, 'total' => $row->total]),
            'coupon' => (string) $row->coupon,
            'items' => $items,
            'payments' => $payments,
            'next' => self::FLOW[(string) $row->status] ?? [],
            'paid_at' => $row->paid_at,
        ];
    }

    /** Panelden durum değişikliği (akışa uygun olmalı); müşteriye bildirim. */
    public function setStatus(int $id, string $status): array
    {
        $order = $this->view($id);
        if (! in_array($status, $order['next'], true)) {
            throw ValidationException::field('status', 'Sipariş "'.CommerceText::get('status.'.$order['status'], 'tr').'" durumundan "'.CommerceText::get('status.'.$status, 'tr').'" durumuna geçemez.');
        }
        DB::transaction(function () use ($order, $status) {
            $values = ['status' => $status, 'updated_at' => Clock::now()];
            $paidNow = $status === 'paid' || ($status === 'completed' && $order['payment_status'] === 'unpaid');
            if ($paidNow && $order['payment_status'] === 'unpaid') {
                $values += ['payment_status' => 'paid', 'paid_at' => Clock::now()];
                DB::table('commerce_payments')->where('order_id', $order['id'])->where('status', 'pending')->update(['status' => 'paid', 'updated_at' => Clock::now()]);
            }
            if ($status === 'refunded') {
                $values['payment_status'] = 'refunded';
            }
            DB::table('commerce_orders')->where('id', $order['id'])->update($values);
            if (in_array($status, ['cancelled', 'refunded'], true)) {
                $this->restock($order['id']);
            }
        });
        $updated = $this->view($id);
        if (in_array($status, self::NOTIFY, true)) {
            $this->mailStatus($updated);
        }

        return $updated;
    }

    // ------------------------------------------------------------ yardımcılar

    /** İptal / iade: satırların ayırdığı stok / kapasite bir kez geri verilir (sipariş verisinde işaret). */
    private function restock(int $orderId): void
    {
        $row = DB::table('commerce_orders')->where('id', $orderId)->lockForUpdate()->first();
        $data = (array) json_decode((string) $row->data, true);
        if (! empty($data['restocked'])) {
            return;
        }
        foreach (DB::table('commerce_order_items')->where('order_id', $orderId)->get() as $item) {
            $type = (string) (json_decode((string) $item->data, true)['type'] ?? 'product');
            CartLines::get($type)?->release($item);
        }
        $data['restocked'] = true;
        DB::table('commerce_orders')->where('id', $orderId)->update(['data' => Text::json($data)]);
        PageCache::flush();
    }

    /** @return array{name:string,email:string,phone:string,address:array<string,string>,note:string} */
    private static function customer(array $input, bool $shippable): array
    {
        $text = static fn (mixed $v, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '') ?? ''), 0, $max);
        $a = is_array($input['address'] ?? null) ? $input['address'] : [];
        $customer = [
            'name' => $text($input['name'] ?? '', 120),
            'email' => mb_strtolower($text($input['email'] ?? '', 190)),
            'phone' => $text($input['phone'] ?? '', 40),
            'address' => [
                'line' => $text($a['line'] ?? '', 300), 'district' => $text($a['district'] ?? '', 80), 'city' => $text($a['city'] ?? '', 80),
                'postal' => $text($a['postal'] ?? '', 20), 'country' => $text($a['country'] ?? '', 60),
            ],
            'note' => mb_substr(trim(is_scalar($input['note'] ?? '') ? (string) ($input['note'] ?? '') : ''), 0, 1000),
        ];
        $errors = [];
        if (mb_strlen($customer['name']) < 3) {
            $errors['name'] = 'Adınızı ve soyadınızı yazın.';
        }
        if (! Text::isEmail($customer['email'])) {
            $errors['email'] = 'Geçerli bir e-posta adresi yazın.';
        }
        if ($shippable) {
            if (mb_strlen($customer['address']['line']) < 5) {
                $errors['address.line'] = 'Teslimat adresini yazın.';
            }
            if ($customer['address']['city'] === '') {
                $errors['address.city'] = 'Şehri yazın.';
            }
            if (mb_strlen(preg_replace('/\D/', '', $customer['phone']) ?? '') < 10) {
                $errors['phone'] = 'Kargo için telefon numarası gerekli.';
            }
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }

        return $customer;
    }

    private static function number(int $id): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) SettingsService::instance()->get(CommerceModule::ORDER_PREFIX)) ?: 'S');

        return $prefix.'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed> */
    private static function summary(object $row): array
    {
        return [
            'id' => (int) $row->id, 'number' => (string) $row->number, 'status' => (string) $row->status,
            'statusLabel' => CommerceText::get('status.'.$row->status, 'tr'),
            'payment_method' => (string) $row->payment_method, 'payment_status' => (string) $row->payment_status,
            'customer_name' => (string) $row->customer_name, 'customer_email' => (string) $row->customer_email,
            'total' => (int) $row->total, 'totalText' => Money::format((int) $row->total, (string) $row->currency),
            'currency' => (string) $row->currency, 'created_at' => (string) $row->created_at,
        ];
    }

    /** Müşteriye giden hal: iç alanlar (ödeme kayıtları, sonraki durumlar) yok, durum adı kendi dilinde. */
    private static function publicView(array $order): array
    {
        $order['statusLabel'] = CommerceText::get('status.'.$order['status'], $order['locale']);
        $order['paymentLabel'] = Payments::get($order['payment_method'])?->label($order['locale']) ?? $order['payment_method'];
        $order['totals'] = array_map(static fn ($v) => Money::format((int) $v, $order['currency'], $order['locale']), $order['amounts']);

        return array_diff_key($order, array_flip(['id', 'payments', 'next', 'customer_email', 'customer_phone']));
    }

    private function mailPlaced(array $order, string $token, array $payment): void
    {
        $locale = $order['locale'];
        $lines = [
            CommerceText::get('mail.placed.hello', $locale, ['name' => $order['customer_name']]),
            CommerceText::get('mail.placed.body', $locale),
        ];
        if (! empty($payment['instructions']) && $order['payment_method'] === 'bank_transfer') {
            $lines[] = CommerceText::get('mail.placed.bank', $locale);
        }
        $facts = [
            CommerceText::get('fact.number', $locale) => $order['number'],
            CommerceText::get('fact.items', $locale) => implode(', ', array_map(static fn ($i) => $i['quantity'].' × '.$i['title'], $order['items'])),
            CommerceText::get('fact.total', $locale) => Money::format($order['amounts']['total'], $order['currency'], $locale),
            CommerceText::get('fact.payment', $locale) => Payments::get($order['payment_method'])?->label($locale) ?? '',
        ] + array_filter((array) ($payment['instructions'] ?? []), static fn ($v) => $v !== '');
        SendMail::to($order['customer_email'], new SiteMail(
            '['.SiteManifest::siteName().'] '.CommerceText::get('mail.placed.subject', $locale, ['number' => $order['number']]),
            $lines,
            facts: $facts,
            action: ($url = CommerceModule::orderUrl($order['number'], $token, $locale)) !== '' ? ['label' => CommerceText::get('mail.view', $locale), 'url' => $url] : null,
            mailLocale: $locale,
        ), 'commerce.order_placed');

        $admin = CommerceModule::notifyAddress();
        if ($admin !== '') {
            SendMail::to($admin, new SiteMail(
                '['.SiteManifest::siteName().'] Yeni sipariş: '.$order['number'].' · '.$order['totalText'],
                ['Yeni sipariş: '.$order['customer_name'].' ('.$order['customer_email'].').'],
                facts: ['Sipariş no' => $order['number'], 'Ürünler' => $facts[CommerceText::get('fact.items', $locale)], 'Toplam' => $order['totalText'], 'Ödeme' => Payments::get($order['payment_method'])?->label('tr') ?? ''],
                action: ['label' => 'Panelde aç', 'url' => PanelUrl::absolute('orders', ['id' => $order['id']])],
                replyToAddress: $order['customer_email'],
                replyToName: $order['customer_name'],
                mailLocale: 'tr',
            ), 'commerce.order_notify');
        }
    }

    private function mailStatus(array $order): void
    {
        $locale = $order['locale'];
        $status = CommerceText::get('status.'.$order['status'], $locale);
        SendMail::to($order['customer_email'], new SiteMail(
            '['.SiteManifest::siteName().'] '.CommerceText::get('mail.status.subject', $locale, ['status' => $status, 'number' => $order['number']]),
            [CommerceText::get('mail.placed.hello', $locale, ['name' => $order['customer_name']]), CommerceText::get('mail.status.body', $locale, ['status' => $status])],
            facts: [CommerceText::get('fact.number', $locale) => $order['number'], CommerceText::get('fact.total', $locale) => Money::format($order['amounts']['total'], $order['currency'], $locale)],
            mailLocale: $locale,
        ), 'commerce.order_status');
    }
}
