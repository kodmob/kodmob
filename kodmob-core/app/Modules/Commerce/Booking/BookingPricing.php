<?php

namespace App\Modules\Commerce\Booking;

/**
 * Rezervasyonun fiyatı (yan etkisiz; tutarlar kuruş). Taban birimden (BookingProfile), seçilen paket birimin
 * fiyatının yerine geçer:
 *
 *   person  yetişkin × yetişkin fiyatı + çocuk × çocuk fiyatı + bebek × bebek fiyatı
 *   day     gün sayısı × günlük fiyat × adet
 *   fixed   sabit fiyat
 *   night   gece sayısı × oda adedi × seçilen oda tipi ve pansiyonun gecelik fiyatı (oda tipi yoksa gecelik fiyat)
 *
 * Kurallar kaynak eklentideki sırayla (profilde açıksa):
 *   1. grup indirimi     yetişkin + çocuk sayısı kuralın alt sınırına ulaşınca en büyük eşleşen kural (% indirim)
 *   2. sezon             başlangıç tarihi kuralın aralığındaysa yüzde fark (+ zam, − indirim; ilk eşleşen)
 *   3. erken rezervasyon başlangıca en az N gün varsa en yüksek indirim
 *
 * Uygulanan kurallar dökümde (`applied`): sepette ve e-postada ne kadar kazanıldığı görünür.
 */
final class BookingPricing
{
    /** Gün ve gece birimiyle en uzun aralık. */
    public const MAX_DAYS = 60;

    /**
     * @param  array<string,mixed>  $data  kaydın verisi (BookingFields alanları)
     * @param  array{date:string,end?:string,adults?:int,children?:int,infants?:int,quantity?:int,persons?:int,package?:string,room?:string,board?:string}  $request
     * @return array{base:int,total:int,persons:int,consumed:int,days:int,package:?string,room:?string,board:?string,applied:list<array{kind:string,percent:int}>}
     */
    public static function price(BookingProfile $profile, array $data, array $request, string $today): array
    {
        $package = self::package($profile, $data, (string) ($request['package'] ?? ''));
        $date = (string) $request['date'];
        $adults = max(0, (int) ($request['adults'] ?? 0));
        $children = max(0, (int) ($request['children'] ?? 0));
        $infants = max(0, (int) ($request['infants'] ?? 0));
        $quantity = max(1, (int) ($request['quantity'] ?? 1));
        $days = 0;
        $option = null;
        switch ($profile->unit) {
            case BookingProfile::NIGHT:
                $days = self::days($date, (string) ($request['end'] ?? ''));
                $option = self::option($data, (string) ($request['room'] ?? ''), (string) ($request['board'] ?? ''));
                $base = $days * (int) ($option['price'] ?? 0) * $quantity;
                $persons = $adults + $children;
                $consumed = $quantity;
                break;
            case BookingProfile::DAY:
                $days = self::days($date, (string) ($request['end'] ?? ''));
                $base = $days * (int) ($package['price'] ?? $data['price_day'] ?? 0) * $quantity;
                $persons = $consumed = $quantity;
                break;
            case BookingProfile::FIXED:
                $base = (int) ($package['price'] ?? $data['price_fixed'] ?? 0);
                $persons = max(1, (int) ($request['persons'] ?? 1));
                $consumed = 1;
                break;
            default:
                $adult = (int) ($package['price'] ?? $data['price_adult'] ?? 0);
                $child = (int) ($package !== null && (int) ($package['price_child'] ?? 0) > 0 ? $package['price_child'] : ($data['price_child'] ?? 0));
                $base = $adults * $adult + $children * $child + $infants * (int) ($data['price_infant'] ?? 0);
                $persons = $consumed = $adults + $children;
        }
        $price = $base;
        $applied = [];

        if ($profile->has('groups')) {
            $group = 0;
            $groupMin = -1;
            foreach ((array) ($data['group_discounts'] ?? []) as $rule) {
                $min = (int) ($rule['min'] ?? 0);
                if ($min > 0 && $persons >= $min && $min > $groupMin) {
                    [$groupMin, $group] = [$min, max(0, min(100, (int) ($rule['percent'] ?? 0)))];
                }
            }
            if ($group > 0) {
                $price = intdiv($price * (100 - $group), 100);
                $applied[] = ['kind' => 'group', 'percent' => $group];
            }
        }

        if ($profile->has('seasons')) {
            foreach ((array) ($data['seasons'] ?? []) as $rule) {
                $from = self::date($rule['from'] ?? '');
                $to = self::date($rule['to'] ?? '');
                $percent = max(-100, min(500, (int) ($rule['percent'] ?? 0)));
                if ($from !== '' && $to !== '' && $from <= $date && $date <= $to && $percent !== 0) {
                    $price = intdiv($price * (100 + $percent), 100);
                    $applied[] = ['kind' => 'season', 'percent' => $percent];
                    break;
                }
            }
        }

        if ($profile->has('early')) {
            $ahead = (int) floor((strtotime($date.' 00:00:00 UTC') - strtotime($today.' 00:00:00 UTC')) / 86400);
            $early = 0;
            foreach ((array) ($data['early_bird'] ?? []) as $rule) {
                if ((int) ($rule['days'] ?? 0) > 0 && $ahead >= (int) $rule['days']) {
                    $early = max($early, max(0, min(100, (int) ($rule['percent'] ?? 0))));
                }
            }
            if ($early > 0) {
                $price = intdiv($price * (100 - $early), 100);
                $applied[] = ['kind' => 'early', 'percent' => $early];
            }
        }

        return [
            'base' => $base, 'total' => max(0, $price), 'persons' => $persons, 'consumed' => $consumed, 'days' => $days,
            'package' => $package['key'] ?? null, 'room' => $option['room'] ?? null, 'board' => $option['board'] ?? null, 'applied' => $applied,
        ];
    }

    /**
     * Gece biriminin seçenekleri: oda tipi × pansiyon, fiyatı olan her hücre (oda sırasıyla, pansiyon BOARDS
     * sırasıyla). Oda tipinin kısa adı boşsa sırasından (`r1`). Hiç oda tipi yoksa gecelik fiyatla tek seçenek (oda ve
     * pansiyon boş).
     *
     * @return list<array{room:string,board:string,index:int,price:int,persons:int,stock:int}>
     */
    public static function options(array $data): array
    {
        $out = [];
        foreach (array_values((array) ($data['rooms'] ?? [])) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '') !== '' ? (string) $row['key'] : 'r'.($i + 1);
            foreach (array_keys(BookingFields::BOARDS) as $board) {
                $price = (int) ($row['price_'.$board] ?? 0);
                if ($price > 0) {
                    $out[] = ['room' => $key, 'board' => $board, 'index' => $i, 'price' => $price, 'persons' => max(0, (int) ($row['persons'] ?? 0)), 'stock' => max(0, (int) ($row['stock'] ?? 0))];
                }
            }
        }
        if (! $out && (int) ($data['price_night'] ?? 0) > 0) {
            $out[] = ['room' => '', 'board' => '', 'index' => -1, 'price' => (int) $data['price_night'], 'persons' => 0, 'stock' => 0];
        }

        return $out;
    }

    /**
     * Seçilen oda tipi ve pansiyon; seçim yoksa ilk seçenek. Yoksa null.
     *
     * @return array{room:string,board:string,index:int,price:int,persons:int,stock:int}|null
     */
    public static function option(array $data, string $room, string $board): ?array
    {
        $options = self::options($data);
        if ($room === '' && $board === '') {
            return $options[0] ?? null;
        }
        foreach ($options as $option) {
            if ($option['room'] === $room && $option['board'] === $board) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Kişi başı tur (kaynak eklentinin hesabı; testler ve eski çağrılar).
     *
     * @param  array<string,mixed>  $tour
     * @return array{base:int,total:int,persons:int,consumed:int,days:int,package:?string,applied:list<array{kind:string,percent:int}>}
     */
    public static function quote(array $tour, string $date, int $adults, int $children, int $infants, string $today): array
    {
        return self::price(new BookingProfile(BookingProfile::PERSON, ['groups', 'seasons', 'early']), $tour, ['date' => $date, 'adults' => $adults, 'children' => $children, 'infants' => $infants], $today);
    }

    /**
     * Seçilen paket; paketli kayıtta seçim yoksa ilki. Paket yoksa null.
     *
     * @return array<string,mixed>|null
     */
    public static function package(BookingProfile $profile, array $data, string $key): ?array
    {
        $packages = $profile->has('packages') ? self::packages($data) : [];
        if (! $packages) {
            return null;
        }
        foreach ($packages as $package) {
            if ($package['key'] === $key) {
                return $package;
            }
        }

        return $key === '' ? $packages[0] : null;
    }

    /** Fiyatı olan paketler; kısa adı boşsa sırasından (`p1`). @return list<array<string,mixed>> */
    public static function packages(array $data): array
    {
        $out = [];
        foreach (array_values((array) ($data['packages'] ?? [])) as $i => $row) {
            if (is_array($row) && (int) ($row['price'] ?? 0) > 0) {
                $out[] = ['key' => (string) ($row['key'] ?? '') !== '' ? (string) $row['key'] : 'p'.($i + 1), 'index' => $i] + $row;
            }
        }

        return $out;
    }

    /** Alış ile teslim arasındaki gün (teslim günü sayılmaz); geçersizse 0. */
    public static function days(string $start, string $end): int
    {
        if (self::date($start) === '' || self::date($end) === '' || $end <= $start) {
            return 0;
        }

        return (int) round((strtotime($end.' 00:00:00 UTC') - strtotime($start.' 00:00:00 UTC')) / 86400);
    }

    /** "2026-11-05" ya da boş (geçersiz tarih). */
    public static function date(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return $value;
    }
}
