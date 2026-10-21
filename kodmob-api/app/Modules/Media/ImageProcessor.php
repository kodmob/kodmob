<?php

namespace App\Modules\Media;

use App\Core\Exceptions\DomainException;

/**
 * Görsel hattı (GD): yüklenen görsel yeniden kodlanır ve ara boyutları üretilir.
 *
 * - Yeniden kodlama boyuttan bağımsız her zaman yapılır: EXIF/GPS bilgisi
 *   silinir, "resim gibi görünen" çok biçimli dosyalar etkisiz kalır.
 * - 2560 pikselden büyük görsel 2560'a küçültülür (WordPress'teki gibi).
 * - Ara boyutlar WordPress'in adları ve ölçüleriyle üretilir (srcset bunlardan
 *   kurulur); ayar açıksa WebP olarak (Media\MediaModule::WEBP).
 */
final class ImageProcessor
{
    /** ad => [genişlik, yükseklik, kırp]; 0 = sınırsız. */
    public const SIZES = [
        'thumbnail' => [150, 150, true],
        'medium' => [300, 300, false],
        'medium_large' => [768, 0, false],
        'large' => [1024, 1024, false],
        '1536x1536' => [1536, 1536, false],
        '2048x2048' => [2048, 2048, false],
    ];

    public const MAX_EDGE = 2560;

    private const JPEG_QUALITY = 82;

    private const WEBP_QUALITY = 80;

    private const PNG_COMPRESSION = 6;

    public static function supported(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public static function webpSupported(): bool
    {
        return function_exists('imagewebp') && (imagetypes() & IMG_WEBP);
    }

    /**
     * `$source` görselini `$dir/$base.<uzantı>` olarak yazar, ara boyutları üretir.
     *
     * @return array{file:string,mime:string,width:int,height:int,size:int,sizes:array<string,array{file:string,width:int,height:int,mime:string}>}
     */
    public function process(string $source, string $mime, string $dir, string $base, bool $webpSizes): array
    {
        if (! self::supported()) {
            throw new DomainException('km_upload_failed', 'Sunucuda görsel işleme (GD) yok; görsel kaydedilemedi.', 500);
        }
        $image = $this->load($source, $mime);
        if (! $image) {
            throw new DomainException('km_upload_type', 'Görsel okunamadı. Dosya bozuk olabilir.', 422, ['field' => 'file']);
        }
        $image = $this->orient($image, $source, $mime);

        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) > self::MAX_EDGE) {
            [$width, $height] = $this->fit($width, $height, self::MAX_EDGE, self::MAX_EDGE);
            $image = $this->resample($image, $width, $height, 0, 0, imagesx($image), imagesy($image));
        }

        $ext = self::extension($mime);
        $file = $base.'.'.$ext;
        $this->save($image, $dir.'/'.$file, $mime);

        $sizes = [];
        $written = [];
        $sizeMime = $webpSizes && $mime !== 'image/webp' && self::webpSupported() ? 'image/webp' : $mime;
        foreach (self::SIZES as $name => [$w, $h, $crop]) {
            $target = $this->dimensions($width, $height, $w, $h, $crop);
            if ($target === null) {
                continue;
            }
            [$dw, $dh, $sx, $sy, $sw, $sh] = $target;
            $sizeFile = $base.'-'.$dw.'x'.$dh.'.'.self::extension($sizeMime);
            if (! isset($written[$sizeFile])) {
                $resized = $this->resample($image, $dw, $dh, $sx, $sy, $sw, $sh);
                $this->save($resized, $dir.'/'.$sizeFile, $sizeMime);
                imagedestroy($resized);
                $written[$sizeFile] = true;
            }
            $sizes[$name] = ['file' => $sizeFile, 'width' => $dw, 'height' => $dh, 'mime' => $sizeMime];
        }
        imagedestroy($image);

        return ['file' => $file, 'mime' => $mime, 'width' => $width, 'height' => $height, 'size' => (int) filesize($dir.'/'.$file), 'sizes' => $sizes];
    }

    public static function extension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * WordPress'in image_resize_dimensions'ı: hedef, kaynaktan büyük ya da
     * aynıysa boyut üretilmez.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int}|null [hedef g, hedef y, kaynak x, kaynak y, kaynak g, kaynak y]
     */
    private function dimensions(int $width, int $height, int $maxW, int $maxH, bool $crop): ?array
    {
        if ($crop) {
            $dw = min($maxW, $width);
            $dh = min($maxH, $height);
            if ($dw >= $width && $dh >= $height) {
                return null;
            }
            $ratio = max($dw / $width, $dh / $height);
            $sw = (int) round($dw / $ratio);
            $sh = (int) round($dh / $ratio);

            return [$dw, $dh, (int) floor(($width - $sw) / 2), (int) floor(($height - $sh) / 2), $sw, $sh];
        }
        [$dw, $dh] = $this->fit($width, $height, $maxW ?: PHP_INT_MAX, $maxH ?: PHP_INT_MAX);
        if ($dw >= $width && $dh >= $height) {
            return null;
        }

        return [$dw, $dh, 0, 0, $width, $height];
    }

    /** @return array{0:int,1:int} */
    private function fit(int $width, int $height, int $maxW, int $maxH): array
    {
        $ratio = min($maxW / $width, $maxH / $height, 1);

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    private function resample(\GdImage $source, int $dw, int $dh, int $sx, int $sy, int $sw, int $sh): \GdImage
    {
        $target = imagecreatetruecolor($dw, $dh);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        return $target;
    }

    private function load(string $path, string $mime): \GdImage|false
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if ($image && ! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        if ($image) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        return $image;
    }

    /** Telefon fotoğraflarının EXIF yönü uygulanır (exif eklentisi varsa). */
    private function orient(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        return $rotated ?: $image;
    }

    private function save(\GdImage $image, string $path, string $mime): void
    {
        $ok = match ($mime) {
            'image/png' => imagepng($image, $path, self::PNG_COMPRESSION),
            'image/webp' => imagewebp($image, $path, self::WEBP_QUALITY),
            default => imagejpeg($image, $path, self::JPEG_QUALITY),
        };
        if (! $ok) {
            throw new DomainException('km_upload_failed', 'Görsel kaydedilemedi: klasör yazılabilir mi?', 500);
        }
    }
}
