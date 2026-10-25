<?php

namespace App\Modules\Site\Blocks;

use App\Core\Modules;
use App\Core\Support\Options;
use App\Core\Support\Paths;

/**
 * Sayfa düzenleyicinin blok kitaplığı: ön yüz derlemesinin yazdığı `km-blocks.json` (site kökü; kodmob-frontend/vite/blocks.js).
 *
 *   blocks   ad → { label, description, category, icon, library, needs, module, fields, template, defaults, preview }
 *   zones    bölge → { label, routes, blocks }   (blocks: derlemenin varsayılan örnekleri, her dilde çözülmüş)
 *   pages    düzenlenebilir sayfalar: { name, label, dynamic, paths: { dil: yol }, zones }
 *   icons    ikon alanının seçenekleri (sprite)
 *
 * Kitaplıkta olan bloklar: `library: true` olanlar ve Keşfet'ten eklenenler (`blocks_library` seçeneği); kapalı
 * modülün bloğu (`module`) görünmez. Kitaplıktan çıkarılan blok sayfada kalır, yalnızca yeni eklenemez.
 */
final class BlockLibrary
{
    public const OPTION = 'blocks_library';

    /** Dosya yolu → [değişme zamanı, içerik]: yol siteye göre değiştiğinden site değişiminde sıfırlanması gerekmez. */
    private static array $files = [];

    public static function file(): string
    {
        return Paths::siteRoot().'/km-blocks.json';
    }

    /** @return array{blocks:array<string,array>,zones:array<string,array>,pages:list<array>,icons:list<string>,categories:array<string,string>} */
    public static function data(): array
    {
        $file = self::file();
        $time = is_file($file) ? (int) filemtime($file) : 0;
        if (! isset(self::$files[$file]) || self::$files[$file][0] !== $time) {
            $data = $time ? json_decode((string) file_get_contents($file), true) : null;
            self::$files[$file] = [$time, is_array($data) ? $data : []];
        }
        $data = self::$files[$file][1];

        return [
            'blocks' => (array) ($data['blocks'] ?? []),
            'zones' => (array) ($data['zones'] ?? []),
            'pages' => array_values((array) ($data['pages'] ?? [])),
            'icons' => array_values((array) ($data['icons'] ?? [])),
            'categories' => (array) ($data['categories'] ?? []),
        ];
    }

    /** Bu projede kullanılabilen bloklar (modülü kapalı olan çıkar). @return array<string,array> */
    public static function blocks(): array
    {
        return array_filter(self::data()['blocks'], static fn (array $b) => empty($b['module']) || Modules::enabled((string) $b['module']));
    }

    public static function block(string $type): ?array
    {
        return self::blocks()[$type] ?? null;
    }

    /** @return array<string,array> */
    public static function zones(): array
    {
        return self::data()['zones'];
    }

    /** Kayıt başına bölge (`page.main@12`; blok sayfası) şablondaki bölgenin (`page.main`) tanımını taşır. */
    public static function zone(string $key): ?array
    {
        return self::zones()[self::base($key)] ?? null;
    }

    /** `page.main@12` → `page.main` */
    public static function base(string $zone): string
    {
        return (string) preg_replace('/@\d+$/', '', $zone);
    }

    /** @return list<string> rotanın bölgeleri (sayfadaki sırayla) */
    public static function zonesFor(string $route): array
    {
        foreach (self::data()['pages'] as $page) {
            if (($page['name'] ?? '') === $route) {
                return array_values(array_filter((array) ($page['zones'] ?? []), 'is_string'));
            }
        }

        return [];
    }

    /** @return list<array> düzenlenebilir sayfalar (modülü kapalı olan sayfa çıkar) */
    public static function pages(): array
    {
        return array_values(array_filter(self::data()['pages'], static fn (array $p) => empty($p['module']) || Modules::enabled((string) $p['module'])));
    }

    /** @return list<string> */
    public static function icons(): array
    {
        return self::data()['icons'];
    }

    /** Keşfet'ten eklenen bloklar. @return list<string> */
    public static function added(): array
    {
        return array_values(array_filter((array) Options::get(self::OPTION, []), 'is_string'));
    }

    /** Blok kitaplıkta mı (düzenleyicide eklenebilir). */
    public static function inLibrary(string $type): bool
    {
        $block = self::block($type);

        return $block !== null && (($block['library'] ?? true) || in_array($type, self::added(), true));
    }

    /** Keşfet: bloğu kitaplığa ekler ya da çıkarır (varsayılan kitaplıktakiler çıkarılamaz). */
    public static function setAdded(string $type, bool $added): void
    {
        $list = array_values(array_diff(self::added(), [$type]));
        if ($added) {
            $list[] = $type;
        }
        Options::set(self::OPTION, $list);
    }
}
