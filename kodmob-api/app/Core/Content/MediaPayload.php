<?php

namespace App\Core\Content;

use App\Core\Support\Clock;
use App\Modules\Media\ImageProcessor;
use App\Modules\Media\MediaLibrary;

/**
 * Kitaplıktaki görseli ön yüzün kullanacağı biçime çevirir: adres, boyutlar,
 * srcset (ara boyutlar) ve alt metin. Biçim tv-core ile aynıdır.
 *
 * Alt metin her dilde ayrıdır; Türkçe zorunludur, boş dil Türkçeye düşer.
 * Adresler kök-göreli döner (`/media/…`): site ve API aynı origin'dedir.
 */
final class MediaPayload
{
    /** srcset'e girecek en büyük genişlik (WordPress'teki gibi). */
    private const SRCSET_MAX = 2048;

    /** @return array{id:int,src:string,srcset:string,width:int,height:int,alt:string}|null */
    public static function image(int $id, string $size = 'large', ?string $locale = null): ?array
    {
        $row = MediaLibrary::find($id);
        if (! $row) {
            return null;
        }
        [$src, $width, $height] = MediaLibrary::source($row, $size);

        return [
            'id' => $id,
            'src' => $src,
            'srcset' => self::srcset($row, $width, $height),
            'width' => $width,
            'height' => $height,
            'alt' => self::alt($id, $locale ?? Locales::default()),
        ];
    }

    /** Panel için: önizleme + bütün dillerin alt metni (düşmesiz) + dosya bilgisi. */
    public static function admin(int $id): ?array
    {
        $image = self::image($id, 'medium_large');
        if (! $image) {
            return null;
        }
        $row = MediaLibrary::find($id);

        return $image + [
            'alts' => $row['alts'],
            'thumb' => MediaLibrary::source($row, 'medium')[0],
            'filename' => $row['file'],
            'date' => Clock::atom($row['created_at']),
        ];
    }

    /** Dillerin alt metinleri (boş dil boş döner). @return array<string,string> */
    public static function alts(int $id): array
    {
        return MediaLibrary::find($id)['alts'] ?? array_fill_keys(Locales::all(), '');
    }

    /** Bir dilin alt metni; boşsa Türkçe. */
    public static function alt(int $id, string $locale): string
    {
        $alts = self::alts($id);

        return ($alts[$locale] ?? '') !== '' ? $alts[$locale] : $alts[Locales::default()];
    }

    public static function hasAlt(int $id): bool
    {
        return self::alts($id)[Locales::default()] !== '';
    }

    /** @param array<string,mixed> $alts @return array<string,string> */
    public static function saveAlts(int $id, array $alts): array
    {
        return MediaLibrary::saveAlts($id, $alts);
    }

    /**
     * Aynı en-boy oranındaki boyutlar ve asıl (2048'e kadar). Tek aday varsa
     * srcset boş döner (WordPress'teki gibi).
     */
    private static function srcset(array $row, int $width, int $height): string
    {
        $candidates = [];
        $ratio = $height > 0 ? $width / $height : 0;
        $variants = $row['sizes'];
        $variants['full'] = ['file' => $row['file'], 'width' => $row['width'], 'height' => $row['height']];
        foreach ($variants as $name => $variant) {
            $w = (int) $variant['width'];
            $h = (int) $variant['height'];
            if ($w > self::SRCSET_MAX || $h <= 0 || ($name !== 'full' && ! isset(ImageProcessor::SIZES[$name]))) {
                continue;
            }
            // Oran farkı 1 pikselden azsa aynı görsel sayılır (kırpılmış küçük resim girmez).
            if ($ratio > 0 && abs($w / $ratio - $h) > 1.5) {
                continue;
            }
            $candidates[$w] = MediaLibrary::url($row, $variant['file']).' '.$w.'w';
        }
        if (count($candidates) < 2) {
            return '';
        }
        ksort($candidates);

        return implode(', ', $candidates);
    }
}
