<?php

namespace App\Modules\Commerce\Booking;

use App\Core\Content\Locales;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Support\Clock;
use App\Modules\Commerce\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rezervasyon uçları (`module:booking`); rezerve edilebilen her tür için (Bookable; tur, kiralama, transfer…):
 *
 *   GET  /shop/bookings/{id}/availability   ?month=2026-11  ayın günleri ve kalan yer (tarih seçici)
 *   POST /shop/bookings/quote               { tour | item, date, end?, adults…, quantity?, persons?, package?, room?, board? }
 *                                           fiyat ve döküm (sepete eklemeden)
 *   GET  /admin/commerce/bookings           panel: rezervasyonlar (km_view_bookings)
 *
 * Sepete ekleme Mağaza'nın ucudur: `POST /shop/cart/items { type: booking, product: <kayıt>, data: { date, … } }`.
 */
final class BookingsController extends Controller
{
    public function availability(Request $request, int $tour): array
    {
        $found = Bookables::find($tour) ?? throw ValidationException::field('tour', BookingText::get('error.tour'));
        ['record' => $record, 'profile' => $profile] = $found;
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month', '')) ? (string) $request->query('month') : substr(Clock::today(), 0, 7);
        $first = BookingLine::firstDate();
        $listed = ! $profile->ranged() && $profile->has('dates') ? BookingLine::dates($record->data) : [];
        $days = [];
        $count = (int) date('t', strtotime($month.'-01'));
        for ($d = 1; $d <= $count; $d++) {
            $date = $month.'-'.str_pad((string) $d, 2, '0', STR_PAD_LEFT);
            if ($date < $first || ($listed && ! in_array($date, $listed, true))) {
                continue;
            }
            $days[$date] = BookingLine::remaining($record, $profile, $date);
        }

        return [
            'month' => $month, 'first' => $first, 'unit' => $profile->unit,
            'limited' => $profile->has('capacity') && (int) ($record->data['capacity'] ?? 0) > 0,
            'dates' => $listed, 'days' => (object) $days,
        ];
    }

    public function quote(Request $request): array
    {
        $input = $this->input($request);
        $locale = in_array($input['locale'] ?? '', Locales::active(), true) ? (string) $input['locale'] : Locales::default();
        $id = (int) ($input['item'] ?? $input['tour'] ?? 0);
        $prepared = (new BookingLine)->prepare($id, 1, $input, $locale);
        $found = Bookables::find($id);
        $quote = BookingPricing::price($found['profile'], $found['record']->data, $prepared['data'], Clock::today());

        return $quote + [
            'unit' => $found['profile']->unit,
            'baseText' => Money::format($quote['base'], null, $locale),
            'totalText' => Money::format($quote['total'], null, $locale),
            'remaining' => BookingLine::remaining($found['record'], $found['profile'], $prepared['data']['date'], (string) ($prepared['data']['end'] ?? ''), (string) ($prepared['data']['room'] ?? '')),
            'data' => $prepared['data'],
        ];
    }

    /** Panel: yaklaşan (ve geçmiş) rezervasyonlar; sipariş durumu ve müşteriyle. */
    public function index(): array
    {
        $rows = DB::table('commerce_bookings as b')
            ->join('commerce_orders as o', 'o.id', '=', 'b.order_id')
            ->leftJoin('contents as c', 'c.id', '=', 'b.tour_id')
            ->orderByDesc('b.date')->orderByDesc('b.id')->limit(1000)
            ->get(['b.id', 'b.date', 'b.end_date', 'b.package', 'b.adults', 'b.children', 'b.infants', 'b.persons', 'b.tour_id', 'c.title as tour', 'c.type as tour_type', 'o.id as order_id', 'o.number', 'o.status', 'o.customer_name', 'o.customer_email', 'o.customer_phone']);
        $today = Clock::today();
        $types = Bookables::types();

        return ['items' => $rows->map(static fn ($r) => [
            'id' => (int) $r->id, 'date' => (string) $r->date, 'end' => (string) ($r->end_date ?? ''), 'package' => (string) ($r->package ?? ''),
            'upcoming' => ($r->end_date ?? $r->date) >= $today ? 'yes' : 'no',
            'tour' => (string) ($r->tour ?? ''), 'tour_id' => (int) $r->tour_id,
            'kind' => isset($types[(string) $r->tour_type]) ? $types[(string) $r->tour_type]->labels()[1] : '',
            'unit' => isset($types[(string) $r->tour_type]) ? $types[(string) $r->tour_type]->bookingProfile()->unit : BookingProfile::PERSON,
            'adults' => (int) $r->adults, 'children' => (int) $r->children, 'infants' => (int) $r->infants, 'persons' => (int) $r->persons,
            'order_id' => (int) $r->order_id, 'number' => (string) $r->number, 'status' => (string) $r->status,
            'customer_name' => (string) $r->customer_name, 'customer_email' => (string) $r->customer_email, 'customer_phone' => (string) $r->customer_phone,
        ])->all()];
    }
}
