<?php

namespace App\Modules\Site\Legal;

use App\Core\Content\Locales;
use App\Core\Modules;
use App\Core\Support\Paths;

/**
 * Derlemenin yasal belge listesi (site kökünde km-legal.json; kodmob-frontend/vite/legal.js): belge → grup, sıra, modül, şablon
 * güncelleme tarihi, dil başına başlık, açıklama ve şablon. Açık belge: modülü yok ya da projede açık.
 */
final class LegalDocs
{
    /** @var array<string,array> site kökü → km-legal.json */
    private static array $cache = [];

    /** @return array{groups:array<string,array<string,string>>,docs:array<string,array>} */
    public static function manifest(): array
    {
        $root = Paths::siteRoot();
        if (! array_key_exists($root, self::$cache)) {
            $file = $root.'/km-legal.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$cache[$root] = is_array($data) ? $data + ['groups' => [], 'docs' => []] : ['groups' => [], 'docs' => []];
        }

        return self::$cache[$root];
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    /** @return array<string,array> açık belgeler (derlemedeki sırayla) */
    public static function active(): array
    {
        return array_filter(self::manifest()['docs'], static fn (array $doc) => empty($doc['module']) || Modules::enabled((string) $doc['module']));
    }

    public static function find(string $name): ?array
    {
        return self::active()[$name] ?? null;
    }

    /** Belgenin o dildeki şablonu; yoksa İngilizcesi, o da yoksa ilki. */
    public static function template(array $doc, string $locale): string
    {
        $templates = (array) ($doc['templates'] ?? []);

        return (string) ($templates[$locale] ?? $templates['en'] ?? reset($templates) ?: '');
    }

    public static function title(array $doc, string $locale): string
    {
        return self::pick((array) ($doc['titles'] ?? []), $locale);
    }

    public static function description(array $doc, string $locale): string
    {
        return self::pick((array) ($doc['descriptions'] ?? []), $locale);
    }

    public static function groupLabel(string $group, string $locale): string
    {
        return self::pick((array) (self::manifest()['groups'][$group] ?? []), $locale) ?: $group;
    }

    /** @param array<string,string> $byLocale */
    private static function pick(array $byLocale, string $locale): string
    {
        return (string) ($byLocale[$locale] ?? $byLocale[Locales::default()] ?? reset($byLocale) ?: '');
    }
}
