<?php

namespace App\Modules\Commerce\Booking;

/**
 * Rezervasyon profili: neyin, nasıl rezerve edildiği.
 *
 * Birim (fiyatın ve kutudaki girişlerin biçimi):
 *   person  kişi başı: yetişkin / çocuk / bebek (tur, etkinlik); kapasite kişi
 *   day     gün başı: alış – teslim tarihi ve adet (araç, ekipman kiralama); kapasite günlük adet
 *   fixed   rezervasyon başı sabit fiyat: tarih ve kişi sayısı bilgi için (transfer, özel tur); kapasite günlük rezervasyon
 *   night   gece başı oda: giriş – çıkış, oda sayısı, yetişkin / çocuk; oda tipi × pansiyon fiyat matrisi (otel,
 *           pansiyon, villa); kapasite her gece oda (toplam ve oda tipi başına)
 *
 * Özellikler (alan + fiyat kuralı + kutudaki parça): packages, groups (yalnızca person), seasons, early, capacity,
 * dates (person ve fixed: yalnızca listedeki tarihler; day ve night biriminde yok). Gece biriminde paket yerine oda
 * tipleri (her zaman; BookingFields `rooms`).
 *
 * Sayfa bölümleri (yalnızca içerik alanı; sitede çatının parçaları): highlights, amenities, itinerary, includes,
 * excludes, faq, meeting, languages.
 */
final class BookingProfile
{
    public const PERSON = 'person';

    public const DAY = 'day';

    public const FIXED = 'fixed';

    public const NIGHT = 'night';

    public const UNITS = [self::PERSON, self::DAY, self::FIXED, self::NIGHT];

    public const FEATURES = ['packages', 'groups', 'seasons', 'early', 'capacity', 'dates'];

    public const SECTIONS = ['highlights', 'amenities', 'itinerary', 'includes', 'excludes', 'faq', 'meeting', 'languages'];

    /** @var list<string> */
    public readonly array $features;

    /** @var list<string> */
    public readonly array $sections;

    /**
     * @param  list<string>  $features
     * @param  list<string>  $sections
     */
    public function __construct(public readonly string $unit, array $features = [], array $sections = [])
    {
        if (! in_array($unit, self::UNITS, true)) {
            throw new \InvalidArgumentException("Rezervasyon birimi: {$unit} (person, day, fixed, night)");
        }
        $features = array_values(array_intersect(self::FEATURES, $features));
        // Grup indirimi kişi sayısına bakar (yalnızca kişi başı); gün ve gece biriminde tarih aralığı seçilir, tarih
        // listesi yok; gecede paketin yerini oda tipi × pansiyon alır.
        $drop = match ($unit) {
            self::PERSON => [],
            self::DAY => ['groups', 'dates'],
            self::NIGHT => ['groups', 'dates', 'packages'],
            default => ['groups'],
        };
        $this->features = array_values(array_diff($features, $drop));
        $this->sections = array_values(array_intersect(self::SECTIONS, $sections));
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /** Tarih aralığıyla (başlangıç – bitiş) rezerve edilir: gün ve gece birimi. */
    public function ranged(): bool
    {
        return $this->unit === self::DAY || $this->unit === self::NIGHT;
    }

    public function section(string $name): bool
    {
        return in_array($name, $this->sections, true);
    }

    /** Sitedeki kutuya giden özet (birim, özellikler, bölümler). @return array<string,mixed> */
    public function toArray(): array
    {
        return ['unit' => $this->unit, 'features' => $this->features, 'sections' => $this->sections];
    }
}
