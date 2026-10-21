<?php

namespace App\Core\Content;

use App\Core\Support\Paths;

/**
 * Ziyaretçi sayfalarının (ve sitemap/RSS'in) hazır önbelleği; public/render.php
 * okur, Site\Render\SitePage yazar.
 *
 * Klasör: storage/framework/pages/. İçerik ya da ayar değişince bütünüyle
 * silinir; sayfa ilk ziyarette yeniden üretilir. render.php ayrıca dosyaları
 * kabuktan (dist) eskiyse ya da süresi geçtiyse kullanmaz.
 */
final class PageCache
{
    public static function dir(): string
    {
        return Paths::pageCache();
    }

    public static function flush(): void
    {
        foreach (array_merge(glob(self::dir().'/*.html') ?: [], glob(self::dir().'/*.xml') ?: []) as $file) {
            @unlink($file);
        }
    }

    public static function count(): int
    {
        return count(glob(self::dir().'/*.html') ?: []);
    }
}
