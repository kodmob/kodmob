<?php

namespace App\Core\Support;

/**
 * Dosya yerleşimi (bkz. config/kodmob.php). Canlıda:
 *
 *   public_html/            site kökü          → siteRoot()
 *   public_html/api/        Laravel girişi     → public_path()
 *   public_html/media/      görseller          → mediaRoot(), adresi mediaUrl()
 *   kodmob-core/storage/    sayfa önbelleği, yedekler
 */
final class Paths
{
    public static function siteRoot(): string
    {
        return rtrim((string) (config('kodmob.site_root') ?: dirname(public_path())), '/\\');
    }

    public static function mediaRoot(): string
    {
        return rtrim((string) (config('kodmob.media_root') ?: self::siteRoot().'/media'), '/\\');
    }

    public static function mediaUrl(string $relative = ''): string
    {
        $base = rtrim((string) config('kodmob.media_url', '/media'), '/');

        return $relative === '' ? $base : $base.'/'.ltrim($relative, '/');
    }

    public static function manifest(): string
    {
        return self::siteRoot().'/km-manifest.json';
    }

    /** Ziyaretçi sayfalarının hazır kopyaları (public/render.php de buraya bakar). */
    public static function pageCache(): string
    {
        return storage_path('framework/pages');
    }

    /** Yedekler ve taşımada elle yüklenen görseller (backups/media/). */
    public static function backups(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
