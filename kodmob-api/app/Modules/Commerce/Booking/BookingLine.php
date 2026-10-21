<?php

namespace App\Modules\Commerce\Booking;

use App\Core\Content\Locales;
use App\Core\Content\Record;
use App\Core\Exceptions\ValidationException;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Core\Support\SiteManifest;
use App\Modules\Commerce\Lines\LineType;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;

/**
 * Rezervasyon satırı (`booking`): rezerve edilebilen herhangi bir kayıt (Bookable; tur, kiralık araç, transfer…) ve
 * profilinin istediği bilgiler (BookingProfile):
 *
 *   person  { date, adults, children, infants, package? }
 *   day     { date, end, quantity, package? }        end: teslim günü (hariç)
 *   fixed   { date, persons, package? }
 *   night   { date, end, quantity, adults, children, room?, board? }   date: giriş, end: çıkış (hariç), quantity: oda
 *
 * Fiyat BookingPricing'in; adet her zaman 1 (başka tarih ayrı satır). Kapasite: kaydın günlük kapasitesi eksi iptal /
 * iade edilmemiş siparişlerin o güne düşen miktarı (`commerce_bookings`; gün ve gece biriminde aralığın her günü).
 * Gece biriminde oda tipinin adedi de ayrıca (`commerce_bookings.package` oda tipinin kısa adı). Dijitaldir: kargo ve
 * teslimat adresi istenmez.
 */
final class BookingLine implements LineType
{
    private const MAX_PERSONS = 50;

    private const MAX_QUANTITY = 20;

    public function key(): string
    {
        return 'booking';
    }

    public function prepare(int $id, int $quantity, array $data, string $locale): array
    {
        $found = Bookables::find($id) ?? throw ValidationException::field('product', BookingText::get('error.tour', $locale));
        $request = self::request($found['profile'], $found['record'], $data, $locale);
        $quote = BookingPricing::price($found['profile'], $found['record']->data, $request, Clock::today());
        $left = self::remaining($found['record'], $found['profile'], $request['date'], (string) ($request['end'] ?? ''), (string) ($request['room'] ?? ''));
        if ($left !== null && $left < $quote['consumed']) {
            throw ValidationException::field('date', BookingText::get('error.full', $locale, ['left' => max(0, $left)]));
        }

        return ['quantity' => 1, 'data' => $request];
    }

    /**
     * Ziyaretçinin seçimi → profile göre doğrulanmış istek.
     *
     * @return array{date:string,end?:string,adults?:int,children?:int,infants?:int,quantity?:int,persons?:int,package?:string,room?:string,board?:string}
     */
    public static function request(BookingProfile $profile, Record $record, array $data, string $locale): array
    {
        $date = BookingPricing::date($data['date'] ?? '');
        if ($date === '' || $date < self::firstDate()) {
            throw ValidationException::field('date', BookingText::get('error.date', $locale));
        }
        $dates = ! $profile->ranged() && $profile->has('dates') ? self::dates($record->data) : [];
        if ($dates && ! in_array($date, $dates, true)) {
            throw ValidationException::field('date', BookingText::get('error.closed', $locale));
        }
        $request = ['date' => $date];
        switch ($profile->unit) {
            case BookingProfile::DAY:
                $end = BookingPricing::date($data['end'] ?? '');
                $days = BookingPricing::days($date, $end);
                $min = max(1, (int) ($record->data['min_days'] ?? 1));
                if ($days < $min || $days > BookingPricing::MAX_DAYS) {
                    throw ValidationException::field('end', BookingText::get('error.days', $locale, ['min' => $min, 'max' => BookingPricing::MAX_DAYS]));
                }
                $count = (int) ($data['quantity'] ?? 1);
                if ($count < 1 || $count > self::MAX_QUANTITY) {
                    throw ValidationException::field('quantity', BookingText::get('error.quantity', $locale, ['max' => self::MAX_QUANTITY]));
                }
                $request += ['end' => $end, 'quantity' => $count];
                break;
            case BookingProfile::NIGHT:
                $end = BookingPricing::date($data['end'] ?? '');
                $nights = BookingPricing::days($date, $end);
                $min = max(1, (int) ($record->data['min_nights'] ?? 1));
                if ($nights < $min || $nights > BookingPricing::MAX_DAYS) {
                    throw ValidationException::field('end', BookingText::get('error.nights', $locale, ['min' => $min, 'max' => BookingPricing::MAX_DAYS]));
                }
                $rooms = (int) ($data['quantity'] ?? 1);
                if ($rooms < 1 || $rooms > self::MAX_QUANTITY) {
                    throw ValidationException::field('quantity', BookingText::get('error.rooms', $locale, ['max' => self::MAX_QUANTITY]));
                }
                $option = BookingPricing::option($record->data, (string) ($data['room'] ?? ''), (string) ($data['board'] ?? ''));
                if ($option === null) {
                    throw ValidationException::field('room', BookingText::get('error.room', $locale));
                }
                // Oda başına kişi: oda tipininki, yoksa kaydınki; 0 sınır yok (bebek sayılmaz, istenmez).
                $per = $option['persons'] ?: max(0, (int) ($record->data['room_persons'] ?? 0));
                $max = min(self::MAX_PERSONS, $per > 0 ? $per * $rooms : self::MAX_PERSONS);
                $adults = (int) ($data['adults'] ?? 0);
                $children = max(0, (int) ($data['children'] ?? 0));
                if ($adults < 1 || $adults + $children > $max) {
                    throw ValidationException::field('adults', BookingText::get('error.roomPersons', $locale, ['max' => $max]));
                }
                $request += ['end' => $end, 'quantity' => $rooms, 'adults' => $adults, 'children' => $children, 'room' => $option['room'], 'board' => $option['board']];
                break;
            case BookingProfile::FIXED:
                $max = (int) ($record->data['max_persons'] ?? 0) ?: self::MAX_PERSONS;
                $persons = (int) ($data['persons'] ?? 1);
                if ($persons < 1 || $persons > $max) {
                    throw ValidationException::field('persons', BookingText::get('error.max', $locale, ['max' => $max]));
                }
                $request['persons'] = $persons;
                break;
            default:
                $adults = (int) ($data['adults'] ?? 0);
                $children = max(0, (int) ($data['children'] ?? 0));
                $infants = max(0, (int) ($data['infants'] ?? 0));
                if ($adults < 1 || $adults + $children + $infants > self::MAX_PERSONS) {
                    throw ValidationException::field('adults', BookingText::get('error.persons', $locale, ['max' => self::MAX_PERSONS]));
                }
                $request += ['adults' => $adults, 'children' => $children, 'infants' => $infants];
        }
        if ($profile->has('packages') && BookingPricing::packages($record->data)) {
            $package = BookingPricing::package($profile, $record->data, (string) ($data['package'] ?? ''));
            if ($package === null) {
                throw ValidationException::field('package', BookingText::get('error.package', $locale));
            }
            $request['package'] = (string) $package['key'];
        }

        return $request;
    }

    public function resolve(array $line, string $locale, ?Router $router): ?array
    {
        $found = Bookables::find((int) $line['id']);
        $item = $found ? $found['type']->localize($found['record'], $locale) : null;
        if ($item === null) {
            return null;
        }
        $d = $line['data'];
        $profile = $found['profile'];
        $quote = BookingPricing::price($profile, $found['record']->data, $d, Clock::today());
        $left = self::remaining($found['record'], $profile, (string) $d['date'], (string) ($d['end'] ?? ''), (string) ($quote['room'] ?? ''));
        $route = SiteManifest::pageRoutes()[$found['type']->recordKind()] ?? '';
        $package = $quote['package'] !== null ? self::packageName($found['record']->data, $locale, $quote['package']) : '';
        if ($profile->unit === BookingProfile::NIGHT) {
            $package = implode(' · ', array_filter([self::roomName($found['record']->data, $locale, (string) ($quote['room'] ?? '')), ($quote['board'] ?? '') !== '' ? BookingText::get('board.'.$quote['board'], $locale) : '']));
        }

        return [
            'title' => implode(' · ', array_filter([$item['title'] ?? '', $package, self::summary($profile, $d, $quote, $locale)])),
            'sku' => '', 'price' => $quote['total'], 'tax_rate' => (int) ($found['record']->data['tax_rate'] ?? 0), 'quantity' => 1, 'digital' => true,
            'url' => ($item['slug'] ?? '') !== '' && $route !== '' ? (string) ($router?->url($route, $locale, $item['slug']) ?? '') : '',
            'image' => $item['cover']['src'] ?? '', 'available' => $left === null || $left >= $quote['consumed'],
        ];
    }

    public function take(array $line, string $title, int $orderId, int $itemId, string $locale): bool
    {
        $row = DB::table('contents')->where('id', $line['id'])->whereNull('deleted_at')->lockForUpdate()->first();
        $found = $row && $row->status === 'publish' ? Bookables::find((int) $row->id) : null;
        if ($found === null) {
            throw ValidationException::field('cart', BookingText::get('error.tour', $locale));
        }
        $d = $line['data'];
        $quote = BookingPricing::price($found['profile'], $found['record']->data, $d, Clock::today());
        $left = self::remaining($found['record'], $found['profile'], (string) $d['date'], (string) ($d['end'] ?? ''), (string) ($quote['room'] ?? ''));
        if ($left !== null && $left < $quote['consumed']) {
            throw ValidationException::field('cart', BookingText::get('error.full', $locale, ['left' => max(0, $left)]).' ('.$title.')');
        }
        // Gece biriminde paket sütunu oda tipinin kısa adı (oda tipi başına adet bununla sayılır).
        $package = $found['profile']->unit === BookingProfile::NIGHT ? (($quote['room'] ?? '') !== '' ? $quote['room'] : null) : ($d['package'] ?? null);
        DB::table('commerce_bookings')->insert([
            'order_id' => $orderId, 'item_id' => $itemId, 'tour_id' => $found['record']->id, 'date' => $d['date'],
            'end_date' => ($d['end'] ?? '') !== '' ? $d['end'] : null, 'package' => $package,
            'adults' => (int) ($d['adults'] ?? 0), 'children' => (int) ($d['children'] ?? 0), 'infants' => (int) ($d['infants'] ?? 0),
            'persons' => $quote['consumed'], 'created_at' => Clock::now(),
        ]);

        return $left !== null && $left === $quote['consumed'];
    }

    public function release(object $item): void
    {
        DB::table('commerce_bookings')->where('item_id', $item->id)->delete();
    }

    /**
     * Kalan yer (kapasite sınırsızsa null). Gün ve gece biriminde [başlangıç, bitiş) aralığının en dar günü. Gece
     * biriminde oda tipinin adedi de sınırdır (`$room`: oda tipinin kısa adı): ikisinden dar olanı.
     */
    public static function remaining(Record $record, BookingProfile $profile, string $date, string $end = '', string $room = ''): ?int
    {
        // Sınırlar: [adet, oda tipi (null: hepsi)].
        $limits = [];
        if ($profile->has('capacity') && (int) ($record->data['capacity'] ?? 0) > 0) {
            $limits[] = [(int) $record->data['capacity'], null];
        }
        if ($profile->unit === BookingProfile::NIGHT && $room !== '') {
            foreach (BookingPricing::options($record->data) as $option) {
                if ($option['room'] === $room && $option['stock'] > 0) {
                    $limits[] = [$option['stock'], $room];
                    break;
                }
            }
        }
        if (! $limits) {
            return null;
        }
        $days = [$date];
        if ($profile->ranged() && BookingPricing::days($date, $end) > 1) {
            $days = [];
            for ($t = strtotime($date.' 12:00:00 UTC'), $last = strtotime($end.' 12:00:00 UTC'); $t < $last; $t += 86400) {
                $days[] = gmdate('Y-m-d', $t);
            }
        }
        $left = PHP_INT_MAX;
        foreach ($days as $day) {
            foreach ($limits as [$limit, $only]) {
                $used = (int) DB::table('commerce_bookings as b')
                    ->join('commerce_orders as o', 'o.id', '=', 'b.order_id')
                    ->where('b.tour_id', $record->id)
                    ->whereNotIn('o.status', ['cancelled', 'refunded'])
                    ->when($only !== null, static fn ($q) => $q->where('b.package', $only))
                    ->where(static function ($q) use ($day) {
                        $q->where(static fn ($q) => $q->whereNull('b.end_date')->where('b.date', $day))
                            ->orWhere(static fn ($q) => $q->where('b.date', '<=', $day)->where('b.end_date', '>', $day));
                    })
                    ->sum('b.persons');
                $left = min($left, $limit - $used);
            }
        }

        return $left;
    }

    /** Rezervasyonun en erken tarihi (bugün + Proje Ayarları'ndaki gün). */
    public static function firstDate(): string
    {
        $lead = max(0, SettingsService::instance()->int(BookingModule::LEAD_DAYS));

        return date('Y-m-d', strtotime(Clock::today().' +'.$lead.' days'));
    }

    /** Rezerve edilebilir tarihler (geçerli olanlar, sıralı); boşsa her gün. @return list<string> */
    public static function dates(array $data): array
    {
        $dates = array_values(array_unique(array_filter(array_map([BookingPricing::class, 'date'], (array) ($data['dates'] ?? [])))));
        sort($dates);

        return $dates;
    }

    /** Sepet ve e-postadaki özet: tarih ve kişi / gün / adet. */
    private static function summary(BookingProfile $profile, array $d, array $quote, string $locale): string
    {
        $when = self::when((string) $d['date'], $locale);

        return match ($profile->unit) {
            BookingProfile::DAY => $when.' – '.self::when((string) $d['end'], $locale).' · '.BookingText::get('days', $locale, ['n' => $quote['days']])
                .((int) $d['quantity'] > 1 ? ' · '.BookingText::get('quantity', $locale, ['n' => (int) $d['quantity']]) : ''),
            BookingProfile::FIXED => $when.' · '.BookingText::get('persons', $locale, ['n' => (int) $d['persons']]),
            BookingProfile::NIGHT => $when.' – '.self::when((string) $d['end'], $locale).' · '.BookingText::get('nights', $locale, ['n' => $quote['days']])
                .' · '.BookingText::get('rooms', $locale, ['n' => (int) $d['quantity']]).' · '.BookingText::persons((int) $d['adults'], (int) $d['children'], 0, $locale),
            default => $when.' · '.BookingText::persons((int) $d['adults'], (int) $d['children'], (int) $d['infants'], $locale),
        };
    }

    /** Paketin bu dildeki adı (yoksa varsayılan dildeki, o da yoksa kısa adı). */
    private static function packageName(array $data, string $locale, string $key): string
    {
        foreach (BookingPricing::packages($data) as $package) {
            if ($package['key'] === $key) {
                $texts = $data['i18n'][$locale]['package_texts'][$package['index']] ?? $data['i18n'][Locales::default()]['package_texts'][$package['index']] ?? [];

                return (string) (($texts['name'] ?? '') ?: $key);
            }
        }

        return $key;
    }

    /** Oda tipinin bu dildeki adı (yoksa varsayılan dildeki, o da yoksa kısa adı); oda tipi yoksa boş. */
    private static function roomName(array $data, string $locale, string $room): string
    {
        foreach (BookingPricing::options($data) as $option) {
            if ($option['room'] === $room && $room !== '') {
                $texts = $data['i18n'][$locale]['room_texts'][$option['index']] ?? $data['i18n'][Locales::default()]['room_texts'][$option['index']] ?? [];

                return (string) (($texts['name'] ?? '') ?: $room);
            }
        }

        return '';
    }

    private static function when(string $date, string $locale): string
    {
        [$y, $m, $d] = explode('-', $date) + [1 => '', 2 => ''];

        return $locale === 'tr' || $locale === 'de' ? "{$d}.{$m}.{$y}" : $date;
    }
}
