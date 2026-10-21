<?php

namespace App\Modules\Commerce\Booking;

use App\Modules\Commerce\Money;
use App\Modules\Commerce\ProductType;

/**
 * Rezerve edilebilen türün alanları profilden (her türde aynı adlar: ortak veri, ortak fiyat ve test). Tür kendi
 * içerik alanlarını (ad, adres, açıklama, görseller) ekler:
 *
 *   public function shared(): array    { return BookingFields::shared($p) + ['cover' => …]; }
 *   public function localized(): array { return ['title' => …, 'slug' => …] + BookingFields::localized($p); }
 *
 * Paket adları dile göre ayrı satırlarda (`package_texts`), fiyatlı paket listesiyle aynı sırada (fiyat her dilde aynı).
 * Gece biriminde oda tipleri de öyle: `rooms` (kısa ad, kişi, adet, pansiyon başına gecelik fiyat) ve `room_texts`.
 */
final class BookingFields
{
    private const PRICE = 'Fiyat ve kapasite';

    private const RULES = 'Tarihler ve kurallar';

    private const PACKAGES = 'Paketler';

    private const SECTIONS = 'Sayfa bölümleri';

    private const ROOMS = 'Odalar ve pansiyon';

    /** Pansiyon türleri (oda tipinde sütun `price_<kod>`); sitede ve e-postada dile göre adı (BookingText `board.<kod>`). */
    public const BOARDS = ['ro' => 'Sadece oda', 'bb' => 'Oda kahvaltı', 'hb' => 'Yarım pansiyon', 'fb' => 'Tam pansiyon', 'ai' => 'Her şey dahil'];

    /** @return array<string,array<string,mixed>> */
    public static function shared(BookingProfile $profile): array
    {
        $currency = Money::currency();
        $money = static fn (string $label, array $extra = []) => ['type' => 'money', 'label' => $label, 'unit' => $currency, 'section' => self::PRICE, 'width' => 'third'] + $extra;
        $int = static fn (string $label) => ['type' => 'int', 'label' => $label];
        $fields = match ($profile->unit) {
            default => [
                'price_adult' => $money('Yetişkin fiyatı', ['required' => true, 'help' => 'Kişi başı.']),
                'price_child' => $money('Çocuk fiyatı (2–12)'),
                'price_infant' => $money('Bebek fiyatı (0–2)'),
            ],
            BookingProfile::DAY => [
                'price_day' => $money('Günlük fiyat', ['required' => true]),
                'min_days' => ['type' => 'int', 'label' => 'En az gün', 'min' => 1, 'section' => self::PRICE, 'width' => 'third'],
            ],
            BookingProfile::FIXED => [
                'price_fixed' => $money('Fiyat', ['required' => true, 'help' => 'Rezervasyon başı (kişi sayısından bağımsız).']),
                'max_persons' => ['type' => 'int', 'label' => 'En çok kişi', 'min' => 0, 'help' => '0: sınır yok.', 'section' => self::PRICE, 'width' => 'third'],
            ],
            BookingProfile::NIGHT => [
                'price_night' => $money('Gecelik fiyat', ['required' => true, 'help' => 'Oda başı. Oda tipi girilmezse bu fiyatla satılır.']),
                'min_nights' => ['type' => 'int', 'label' => 'En az gece', 'min' => 1, 'section' => self::PRICE, 'width' => 'third'],
                'room_persons' => ['type' => 'int', 'label' => 'Oda başı en çok kişi', 'min' => 0, 'help' => 'Oda tipinde yazılmamışsa geçerli. 0: sınır yok. Yetişkin ve çocuk sayılır.', 'section' => self::PRICE, 'width' => 'third'],
            ],
        };
        $fields['tax_rate'] = ['type' => 'select', 'label' => 'KDV oranı', 'options' => ProductType::TAX_RATES, 'section' => self::PRICE, 'width' => 'third'];
        if ($profile->has('capacity')) {
            $fields['capacity'] = ['type' => 'int', 'label' => match ($profile->unit) {
                BookingProfile::DAY => 'Günlük adet (ör. araç sayısı)',
                BookingProfile::FIXED => 'Günlük rezervasyon sayısı',
                BookingProfile::NIGHT => 'Oda sayısı (her gece)',
                default => 'Günlük kapasite (kişi)',
            }, 'min' => 0, 'help' => match ($profile->unit) {
                BookingProfile::PERSON => '0: sınırsız. Yetişkin ve çocuk sayılır, bebek sayılmaz.',
                BookingProfile::NIGHT => '0: sınırsız. Bütün oda tipleri birlikte; oda tipinde ayrıca adet yazılabilir.',
                default => '0: sınırsız.',
            }, 'section' => self::PRICE, 'width' => 'third'];
        }
        if ($profile->unit === BookingProfile::NIGHT) {
            $fields['check_in'] = ['type' => 'text', 'label' => 'Giriş saati', 'max' => 10, 'help' => 'Ör. 14:00', 'section' => self::RULES, 'width' => 'third'];
            $fields['check_out'] = ['type' => 'text', 'label' => 'Çıkış saati', 'max' => 10, 'help' => 'Ör. 12:00', 'section' => self::RULES, 'width' => 'third'];
            $columns = [
                'key' => ['type' => 'slug', 'label' => 'Kısa ad (ör. standart)'],
                'persons' => $int('Kişi (en çok)'),
                'stock' => $int('Oda adedi (0: sınırsız)'),
            ];
            foreach (self::BOARDS as $code => $label) {
                $columns['price_'.$code] = ['type' => 'money', 'label' => $label];
            }
            $fields['rooms'] = ['type' => 'rows', 'label' => 'Oda tipleri ve pansiyon fiyatları', 'max' => 12, 'help' => 'Gecelik oda fiyatları; fiyatı boş pansiyon o odada sunulmaz. Adları "Oda adları"nda, dil dil. Hiç oda tipi yoksa gecelik fiyatla satılır.', 'section' => self::ROOMS, 'columns' => $columns];
        }
        if (! $profile->ranged()) {
            $fields['duration'] = ['type' => 'text', 'label' => 'Süre', 'max' => 60, 'help' => 'Ör. "Tam gün", "3 saat".', 'section' => self::PRICE, 'width' => 'third'];
        }
        if ($profile->has('dates') && $profile->unit !== BookingProfile::DAY) {
            $fields['dates'] = ['type' => 'list', 'label' => 'Tarihler', 'max' => 200, 'help' => 'YYYY-AA-GG, her satıra bir tarih. Boşsa her gün rezerve edilebilir.', 'section' => self::RULES];
        }
        if ($profile->has('packages')) {
            $columns = ['key' => ['type' => 'slug', 'label' => 'Kısa ad (ör. standart)'], 'price' => ['type' => 'money', 'label' => match ($profile->unit) {
                BookingProfile::PERSON => 'Yetişkin fiyatı', BookingProfile::DAY => 'Günlük fiyat', default => 'Fiyat',
            }]];
            if ($profile->unit === BookingProfile::PERSON) {
                $columns['price_child'] = ['type' => 'money', 'label' => 'Çocuk fiyatı'];
            }
            $columns['compare'] = ['type' => 'money', 'label' => 'İndirimsiz fiyat'];
            $fields['packages'] = ['type' => 'rows', 'label' => 'Paketler', 'max' => 8, 'help' => 'Ziyaretçi bir paket seçer; paketin fiyatı temel fiyatın yerine geçer. Adları "Paket adları"nda, dil dil.', 'section' => self::PACKAGES, 'columns' => $columns];
        }
        if ($profile->has('groups')) {
            $fields['group_discounts'] = ['type' => 'rows', 'label' => 'Grup indirimi', 'max' => 10, 'help' => 'En az kişi sayısına ulaşınca yüzde indirim (en büyük eşleşen).', 'section' => self::RULES, 'columns' => [
                'min' => $int('En az kişi'), 'percent' => $int('% indirim'),
            ]];
        }
        if ($profile->has('seasons')) {
            $fields['seasons'] = ['type' => 'rows', 'label' => 'Sezon fiyatı', 'max' => 12, 'help' => 'Tarih aralığında yüzde fark: 20 zam, -15 indirim.', 'section' => self::RULES, 'columns' => [
                'from' => ['type' => 'text', 'label' => 'Başlangıç (YYYY-AA-GG)', 'max' => 10], 'to' => ['type' => 'text', 'label' => 'Bitiş (YYYY-AA-GG)', 'max' => 10], 'percent' => $int('% fark'),
            ]];
        }
        if ($profile->has('early')) {
            $fields['early_bird'] = ['type' => 'rows', 'label' => 'Erken rezervasyon', 'max' => 10, 'help' => 'En az N gün kala yapılan rezervasyonda yüzde indirim.', 'section' => self::RULES, 'columns' => [
                'days' => $int('En az gün önce'), 'percent' => $int('% indirim'),
            ]];
        }
        if ($profile->section('languages')) {
            $fields['guide_languages'] = ['type' => 'list', 'label' => 'Rehberlik dilleri', 'max' => 10, 'help' => 'Ör. Türkçe, English.', 'section' => self::SECTIONS];
        }

        return $fields;
    }

    /** @return array<string,array<string,mixed>> */
    public static function localized(BookingProfile $profile): array
    {
        $fields = [];
        if ($profile->unit === BookingProfile::NIGHT) {
            $fields['room_texts'] = ['type' => 'rows', 'label' => 'Oda adları', 'max' => 12, 'help' => 'Oda tipleri listesindeki sırayla: ad ve kısa açıklama (ör. "Deniz manzaralı, 28 m²").', 'section' => self::ROOMS, 'columns' => [
                'name' => ['type' => 'text', 'label' => 'Ad', 'max' => 60], 'subtitle' => ['type' => 'text', 'label' => 'Kısa açıklama', 'max' => 120],
            ]];
        }
        if ($profile->has('packages')) {
            $fields['package_texts'] = ['type' => 'rows', 'label' => 'Paket adları', 'max' => 8, 'help' => 'Paketler listesindeki sırayla: ad ve kısa açıklama.', 'section' => self::PACKAGES, 'columns' => [
                'name' => ['type' => 'text', 'label' => 'Ad', 'max' => 60], 'subtitle' => ['type' => 'text', 'label' => 'Kısa açıklama', 'max' => 120],
            ]];
        }
        $sections = [
            'highlights' => ['type' => 'list', 'label' => 'Öne çıkanlar', 'max' => 12],
            'amenities' => ['type' => 'list', 'label' => 'Olanaklar', 'max' => 30, 'help' => 'Ör. Ücretsiz Wi-Fi, Açık havuz, Otopark.'],
            'itinerary' => ['type' => 'rows', 'label' => 'Program', 'max' => 30, 'help' => 'Gün gün ya da saat saat; her satır bir adım.', 'columns' => [
                'title' => ['type' => 'text', 'label' => 'Başlık (ör. 1. gün: Mostar)', 'max' => 120], 'text' => ['type' => 'textarea', 'label' => 'Açıklama', 'max' => 1000],
            ]],
            'includes' => ['type' => 'list', 'label' => 'Dahil olanlar', 'max' => 20],
            'excludes' => ['type' => 'list', 'label' => 'Dahil olmayanlar', 'max' => 20],
            'faq' => ['type' => 'rows', 'label' => 'Sık sorulanlar', 'max' => 20, 'columns' => [
                'q' => ['type' => 'text', 'label' => 'Soru', 'max' => 200], 'a' => ['type' => 'textarea', 'label' => 'Yanıt', 'max' => 1500],
            ]],
            'meeting' => ['type' => 'text', 'label' => 'Buluşma noktası', 'max' => 200],
        ];
        foreach ($sections as $name => $field) {
            if ($profile->section($name)) {
                $fields[$name] = $field + ['section' => self::SECTIONS];
            }
        }

        return $fields;
    }
}
