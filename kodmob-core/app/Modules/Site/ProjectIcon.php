<?php

namespace App\Modules\Site;

use App\Core\Settings\SettingsService;
use App\Modules\Media\MediaLibrary;
use Throwable;

/**
 * Proje ikonu (Proje Ayarları › Kimlik): tarayıcı sekmesi, telefona eklenen uygulama (PWA) ve anlık bildirimler.
 * Yüklenen kare görselden PNG boyları üretilir (ortadan kare kırpılır) ve depolamada saklanır; ikon değişince adı
 * (sürümü) değişir. `maskable`: telefonun yuvarlak / damla kırpmasına güvenli alan (görsel %80, zemin ana renk).
 * İkon yoksa ya da görsel işlenemiyorsa (SVG, GD yok) derlemedeki favicon.svg kullanılır.
 */
final class ProjectIcon
{
    public const SIZES = [32, 180, 192, 512];

    /** @return array{id:int,path:string,version:string}|null */
    public static function source(): ?array
    {
        try {
            $id = (int) SettingsService::instance()->get(SiteProfile::ICON);
            $row = $id > 0 ? MediaLibrary::find($id) : null;
        } catch (Throwable) {
            return null;
        }
        if (! $row || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $path = MediaLibrary::filePath($row);
        if (! is_file($path) || preg_match('/\.svg$/i', $path)) {
            return null;
        }

        return ['id' => $id, 'path' => $path, 'version' => substr(md5($id.'|'.filemtime($path)), 0, 10)];
    }

    /** Sürüm (adreslerde `?v=`); ikon yoksa null. */
    public static function version(): ?string
    {
        return self::source()['version'] ?? null;
    }

    /** İkonun istenen boyu (dosya yolu); üretilemezse null. */
    public static function png(int $size, bool $maskable = false): ?string
    {
        if (! in_array($size, self::SIZES, true) || ($maskable && $size !== 512)) {
            return null;
        }
        $source = self::source();
        if ($source === null) {
            return null;
        }
        $dir = storage_path('framework/icons');
        $file = $dir.'/'.$source['version'].'-'.$size.($maskable ? '-maskable' : '').'.png';
        if (is_file($file)) {
            return $file;
        }
        $image = @imagecreatefromstring((string) file_get_contents($source['path']));
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        if ($maskable) {
            // Güvenli alan: zemin ana renk (yoksa beyaz), görsel ortada %80.
            [$r, $g, $b] = self::rgb((string) SettingsService::instance()->get(SiteProfile::COLOR)) ?? [255, 255, 255];
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, $r, $g, $b));
            imagealphablending($canvas, true);
            $inner = (int) round($size * 0.8);
            $offset = (int) (($size - $inner) / 2);
        } else {
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            $inner = $size;
            $offset = 0;
        }
        imagecopyresampled($canvas, $image, $offset, $offset, (int) (($width - $side) / 2), (int) (($height - $side) / 2), $inner, $inner, $side, $side);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Eski sürümlerin dosyaları silinir (ikon değişti).
        foreach (glob($dir.'/*.png') ?: [] as $old) {
            if (! str_starts_with(basename($old), $source['version'].'-')) {
                @unlink($old);
            }
        }
        $ok = imagepng($canvas, $file, 9);

        return $ok ? $file : null;
    }

    /** Web uygulaması bildirimi (manifest): site ya da panel. */
    public static function manifest(string $app, array $strings, string $lang): array
    {
        $name = trim((string) ($strings['siteName'] ?? '')) ?: (string) config('app.name', 'Site');
        $short = trim((string) ($strings['brand'] ?? '')) ?: $name;
        $color = (string) SettingsService::instance()->get(SiteProfile::COLOR_DARK);
        $version = self::version();
        $icons = [];
        if ($version !== null) {
            foreach ([192, 512] as $size) {
                $icons[] = ['src' => "/api/v1/icon/{$size}.png?v={$version}", 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
            }
            $icons[] = ['src' => "/api/v1/icon/512-maskable.png?v={$version}", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
        }
        $icons[] = ['src' => '/assets/favicon/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'];
        $panel = $app === 'panel';

        return [
            'id' => $panel ? '/panel/' : '/',
            'name' => $panel ? $name.' · Panel' : $name,
            'short_name' => mb_substr($panel ? 'Panel' : $short, 0, 24),
            'lang' => $lang,
            'start_url' => $panel ? '/panel/' : '/',
            'scope' => $panel ? '/panel/' : '/',
            'display' => 'standalone',
            'theme_color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#0B1733',
            'background_color' => '#F6F7F9',
            'icons' => $icons,
        ];
    }

    /** @return array{0:int,1:int,2:int}|null */
    private static function rgb(string $hex): ?array
    {
        if (! preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            return null;
        }

        return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3])];
    }
}
