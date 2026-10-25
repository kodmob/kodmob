<?php

namespace App\Modules\Commerce;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * İndirim kuponları (`commerce_coupons`). Kod büyük harfle saklanır; yüzde (1–100) ya da sabit tutar (kuruş),
 * alt sınır, kullanım sınırı, geçerlilik aralığı. Kullanım **sipariş verilince** sayılır (sepette denemek
 * saymaz; kaynak eklentideki hata düzeltildi).
 */
final class Coupons
{
    public const TYPES = ['percent' => 'Yüzde', 'fixed' => 'Sabit tutar'];

    public static function normalize(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '', 'UTF-8');
    }

    /**
     * Sepette kullanılabilir kupon (etkin, tarih aralığında, sınırı dolmamış); yoksa nedeniyle hata.
     *
     * @return array<string,mixed>
     */
    public static function usable(string $code): array
    {
        $code = self::normalize($code);
        $row = $code === '' ? null : DB::table('commerce_coupons')->where('code', $code)->first();
        $now = Clock::now();
        if ($row === null || ! $row->active) {
            throw ValidationException::field('coupon', 'Kupon bulunamadı.');
        }
        if (($row->starts_at && $row->starts_at > $now) || ($row->expires_at && $row->expires_at < $now)) {
            throw ValidationException::field('coupon', 'Kuponun geçerlilik süresi dışında.');
        }
        if ($row->usage_limit > 0 && $row->used >= $row->usage_limit) {
            throw ValidationException::field('coupon', 'Kuponun kullanım sınırı doldu.');
        }

        return self::row($row);
    }

    /** Sipariş verildi: kullanım kaydı ve sayaç (aynı işlem içinde; sınır yarışında ikinci sipariş reddedilir). */
    public static function redeem(int $couponId, int $orderId): void
    {
        $done = DB::table('commerce_coupons')->where('id', $couponId)
            ->where(static fn ($q) => $q->where('usage_limit', 0)->orWhereColumn('used', '<', 'usage_limit'))
            ->increment('used');
        if ($done !== 1) {
            throw ValidationException::field('coupon', 'Kuponun kullanım sınırı doldu.');
        }
        DB::table('commerce_coupon_uses')->insert(['coupon_id' => $couponId, 'order_id' => $orderId, 'created_at' => Clock::now()]);
    }

    /** @return list<array<string,mixed>> panel listesi */
    public static function all(): array
    {
        return DB::table('commerce_coupons')->orderByDesc('id')->get()->map(static fn ($r) => self::row($r))->all();
    }

    /** Panelden ekleme / güncelleme; kayıt döner. */
    public static function save(?int $id, array $input): array
    {
        $code = self::normalize((string) ($input['code'] ?? ''));
        $type = (string) ($input['type'] ?? 'percent');
        $value = (int) ($input['value'] ?? 0);
        $errors = [];
        if (! preg_match('/^[A-Z0-9ÇĞİÖŞÜ_-]{3,40}$/u', $code)) {
            $errors['code'] = 'Kod 3–40 karakter: harf, rakam, tire.';
        } elseif (DB::table('commerce_coupons')->where('code', $code)->when($id, static fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $errors['code'] = 'Bu kod başka bir kuponda kullanılıyor.';
        }
        if (! isset(self::TYPES[$type])) {
            $errors['type'] = 'Geçersiz kupon türü.';
        } elseif ($type === 'percent' && ($value < 1 || $value > 100)) {
            $errors['value'] = 'Yüzde 1 ile 100 arasında olmalı.';
        } elseif ($type === 'fixed' && $value < 1) {
            $errors['value'] = 'Tutar sıfırdan büyük olmalı.';
        }
        $starts = self::date($input['starts_at'] ?? null);
        $expires = self::date($input['expires_at'] ?? null);
        if ($starts && $expires && $expires <= $starts) {
            $errors['expires_at'] = 'Bitiş başlangıçtan sonra olmalı.';
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        $values = [
            'code' => $code, 'type' => $type, 'value' => $value,
            'min_total' => max(0, (int) ($input['min_total'] ?? 0)),
            'usage_limit' => max(0, (int) ($input['usage_limit'] ?? 0)),
            'starts_at' => $starts, 'expires_at' => $expires,
            'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'note' => mb_substr(trim((string) ($input['note'] ?? '')), 0, 190),
            'updated_at' => Clock::now(),
        ];
        if ($id) {
            if (! DB::table('commerce_coupons')->where('id', $id)->update($values) && ! DB::table('commerce_coupons')->where('id', $id)->exists()) {
                throw ValidationException::field('id', 'Kupon bulunamadı.');
            }
        } else {
            $id = (int) DB::table('commerce_coupons')->insertGetId($values + ['created_at' => Clock::now()]);
        }

        return self::row(DB::table('commerce_coupons')->where('id', $id)->first());
    }

    public static function delete(int $id): void
    {
        DB::table('commerce_coupons')->where('id', $id)->delete();
    }

    private static function date(mixed $value): ?string
    {
        $time = is_string($value) && $value !== '' ? strtotime($value) : false;

        return $time ? date('Y-m-d H:i:s', $time) : null;
    }

    /** @return array<string,mixed> */
    private static function row(object $r): array
    {
        return [
            'id' => (int) $r->id, 'code' => (string) $r->code, 'type' => (string) $r->type, 'value' => (int) $r->value,
            'min_total' => (int) $r->min_total, 'usage_limit' => (int) $r->usage_limit, 'used' => (int) $r->used,
            'starts_at' => $r->starts_at, 'expires_at' => $r->expires_at, 'active' => (bool) $r->active, 'note' => (string) $r->note,
            'label' => $r->type === 'percent' ? '%'.$r->value : Money::format((int) $r->value),
        ];
    }
}
