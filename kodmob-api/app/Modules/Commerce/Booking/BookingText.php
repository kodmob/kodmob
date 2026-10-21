<?php

namespace App\Modules\Commerce\Booking;

use App\Core\Content\Locales;

/** Rezervasyonun ziyaretçiye giden metinleri (sunucunun ürettikleri; sayfa metinleri çatının sözlüğünde `shop.booking`). */
final class BookingText
{
    private const TEXT = [
        'tr' => [
            'adults' => '{n} yetişkin', 'children' => '{n} çocuk', 'infants' => '{n} bebek',
            'error.tour' => 'Bu rezervasyon bulunamadı ya da artık yapılamıyor.',
            'error.date' => 'Geçerli bir tarih seçin.',
            'error.closed' => 'Bu tarihte tur yok; listeden bir tarih seçin.',
            'error.persons' => 'En az 1 yetişkin, toplam en çok {max} kişi.',
            'error.full' => 'Bu tarihte yeterli yer yok (kalan: {left}).',
            'days' => '{n} gün', 'persons' => '{n} kişi', 'quantity' => '{n} adet',
            'error.days' => 'En az {min}, en çok {max} gün seçin (teslim tarihi alıştan sonra).',
            'error.quantity' => 'Adet 1 ile {max} arasında olmalı.',
            'error.package' => 'Geçerli bir paket seçin.',
            'error.max' => 'Kişi sayısı 1 ile {max} arasında olmalı.',
            'nights' => '{n} gece', 'rooms' => '{n} oda',
            'error.nights' => 'En az {min}, en çok {max} gece seçin (çıkış girişten sonra).',
            'error.rooms' => 'Oda sayısı 1 ile {max} arasında olmalı.',
            'error.room' => 'Geçerli bir oda ve pansiyon seçin.',
            'error.roomPersons' => 'En az 1 yetişkin; seçtiğiniz odalarda en çok {max} kişi kalabilir (oda sayısını artırın).',
            'board.ro' => 'Sadece oda', 'board.bb' => 'Oda kahvaltı', 'board.hb' => 'Yarım pansiyon', 'board.fb' => 'Tam pansiyon', 'board.ai' => 'Her şey dahil',
        ],
        'en' => [
            'adults' => '{n} adult(s)', 'children' => '{n} child(ren)', 'infants' => '{n} infant(s)',
            'error.tour' => 'This booking was not found or is no longer available.',
            'error.date' => 'Please choose a valid date.',
            'error.closed' => 'No tour on this date; please choose a date from the list.',
            'error.persons' => 'At least 1 adult, at most {max} people in total.',
            'error.full' => 'Not enough places on this date ({left} left).',
            'days' => '{n} day(s)', 'persons' => '{n} person(s)', 'quantity' => '{n} unit(s)',
            'error.days' => 'Choose at least {min} and at most {max} days (return after pick-up).',
            'error.quantity' => 'Quantity must be between 1 and {max}.',
            'error.package' => 'Please choose a valid package.',
            'error.max' => 'Number of people must be between 1 and {max}.',
            'nights' => '{n} night(s)', 'rooms' => '{n} room(s)',
            'error.nights' => 'Choose at least {min} and at most {max} nights (check-out after check-in).',
            'error.rooms' => 'Number of rooms must be between 1 and {max}.',
            'error.room' => 'Please choose a valid room and board.',
            'error.roomPersons' => 'At least 1 adult; at most {max} guests fit in the chosen rooms (add a room).',
            'board.ro' => 'Room only', 'board.bb' => 'Bed & breakfast', 'board.hb' => 'Half board', 'board.fb' => 'Full board', 'board.ai' => 'All inclusive',
        ],
        'de' => [
            'adults' => '{n} Erwachsene', 'children' => '{n} Kinder', 'infants' => '{n} Kleinkinder',
            'error.tour' => 'Diese Buchung wurde nicht gefunden oder ist nicht mehr möglich.',
            'error.date' => 'Bitte wählen Sie ein gültiges Datum.',
            'error.closed' => 'An diesem Datum findet keine Tour statt; bitte wählen Sie ein Datum aus der Liste.',
            'error.persons' => 'Mindestens 1 Erwachsener, insgesamt höchstens {max} Personen.',
            'error.full' => 'An diesem Datum sind nicht genug Plätze frei (noch {left}).',
            'days' => '{n} Tag(e)', 'persons' => '{n} Person(en)', 'quantity' => '{n} Stück',
            'error.days' => 'Wählen Sie mindestens {min} und höchstens {max} Tage (Rückgabe nach Abholung).',
            'error.quantity' => 'Die Anzahl muss zwischen 1 und {max} liegen.',
            'error.package' => 'Bitte wählen Sie ein gültiges Paket.',
            'error.max' => 'Die Personenzahl muss zwischen 1 und {max} liegen.',
            'nights' => '{n} Nacht/Nächte', 'rooms' => '{n} Zimmer',
            'error.nights' => 'Wählen Sie mindestens {min} und höchstens {max} Nächte (Abreise nach Anreise).',
            'error.rooms' => 'Die Zimmeranzahl muss zwischen 1 und {max} liegen.',
            'error.room' => 'Bitte wählen Sie ein gültiges Zimmer und eine Verpflegung.',
            'error.roomPersons' => 'Mindestens 1 Erwachsener; in den gewählten Zimmern höchstens {max} Gäste (Zimmer hinzufügen).',
            'board.ro' => 'Nur Übernachtung', 'board.bb' => 'Übernachtung mit Frühstück', 'board.hb' => 'Halbpension', 'board.fb' => 'Vollpension', 'board.ai' => 'All inclusive',
        ],
    ];

    /** @param array<string,string|int> $vars */
    public static function get(string $key, ?string $locale = null, array $vars = []): string
    {
        $locale ??= Locales::default();
        $text = self::TEXT[$locale][$key] ?? self::TEXT['en'][$key] ?? $key;
        foreach ($vars as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }

        return $text;
    }

    public static function persons(int $adults, int $children, int $infants, string $locale): string
    {
        $parts = [self::get('adults', $locale, ['n' => $adults])];
        if ($children > 0) {
            $parts[] = self::get('children', $locale, ['n' => $children]);
        }
        if ($infants > 0) {
            $parts[] = self::get('infants', $locale, ['n' => $infants]);
        }

        return implode(', ', $parts);
    }
}
