<?php

namespace App\Modules\Commerce\Booking;

use App\Core\Content\ContentRepository;
use App\Core\Content\ContentType;
use App\Core\Content\Record;
use App\Core\Content\Types;
use Illuminate\Support\Facades\DB;

/**
 * Rezerve edilebilen türler (Bookable uygulayan içerik türleri; çatınınki TourType, projeninkiler de) ve kaydı
 * türüyle bulma: sepet satırı, fiyat ve uçlar türü bilmeden çalışır.
 */
final class Bookables
{
    /** @return array<string,ContentType&Bookable> kayıt türü (km_tour) → tür */
    public static function types(): array
    {
        $out = [];
        foreach (Types::all() as $type) {
            if ($type instanceof Bookable) {
                $out[$type->postType()] = $type;
            }
        }

        return $out;
    }

    /**
     * Yayındaki rezerve edilebilir kayıt.
     *
     * @return array{type:ContentType&Bookable,record:Record,profile:BookingProfile}|null
     */
    public static function find(int $id): ?array
    {
        $types = self::types();
        $postType = $id > 0 ? DB::table('contents')->where('id', $id)->whereNull('deleted_at')->value('type') : null;
        $type = $postType !== null ? ($types[(string) $postType] ?? null) : null;
        $record = $type ? ContentRepository::find($id, $type->postType()) : null;
        if ($type === null || $record === null || $record->status !== 'publish') {
            return null;
        }

        return ['type' => $type, 'record' => $record, 'profile' => $type->bookingProfile()];
    }
}
