<?php

namespace App\Modules\Content;

use App\Core\Content\Fields;
use App\Core\Content\Locales;
use App\Core\Content\PageCache;
use App\Core\Support\Options;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\Site\Render\Router;

/**
 * Panelden değiştirilebilen sayfa metinleri (ana sayfa başlıkları, süreç
 * adımları, SSS, hesaplayıcı seçenekleri…).
 *
 * Hangi metinlerin değiştirilebileceğini ön yüz derlemesi belirler: şablonda
 * `tx('home.hero.title')` ile basılan her metin `km-manifest.json`'un `texts`
 * listesine (sözlükteki halleriyle) yazılır ve sayfada işaretlenir:
 *
 *     <!--km:t home.hero.title-->Dijital dünyada…<!--/km:t-->
 *
 * Sayfa üretilirken (Site\Render\Renderer) işaretlerin arası kayıtlı metinle
 * değişir; kayıt yoksa sözlükteki metin kalır. JSON-LD bloklarındaki (ör. SSS)
 * aynı metin de değiştirilir.
 *
 * Saklama: `page_texts` seçeneği = { tr: { anahtar: metin }, en: {…} } — yalnızca
 * sözlükten farklı olanlar. Boş metin kaydı siler (sözlükteki hale döner).
 */
final class PageTexts
{
    public const OPTION = 'page_texts';

    public const MAX = 2000;

    /** @return array<string, array{on: list<string>, tr: string, en: string, de: string}> */
    public static function definitions(): array
    {
        $router = Router::load(Paths::siteRoot());
        $texts = $router ? ($router->manifest()['texts'] ?? []) : [];

        return is_array($texts) ? $texts : [];
    }

    /** @return array<string, array<string, string>> dil → anahtar → metin */
    public static function overrides(): array
    {
        $value = Options::get(self::OPTION, []);

        return is_array($value) ? $value : [];
    }

    /** Sayfadaki hali: kayıtlı metin, yoksa sözlükteki. */
    public static function get(string $key, string $locale, ?array $definitions = null): string
    {
        $definitions ??= self::definitions();

        return (string) (self::overrides()[$locale][$key] ?? $definitions[$key][$locale] ?? '');
    }

    /**
     * Değişiklikleri yazar ({ dil: { anahtar: metin } }; verilmeyenler kalır).
     * Bilinmeyen anahtar ve dil yok sayılır.
     */
    public static function save(array $values): array
    {
        $definitions = self::definitions();
        $current = self::overrides();
        foreach ($values as $locale => $texts) {
            if (! Locales::valid((string) $locale) || ! is_array($texts)) {
                continue;
            }
            foreach ($texts as $key => $text) {
                $key = (string) $key;
                if (! isset($definitions[$key]) || ! is_scalar($text)) {
                    continue;
                }
                $text = mb_substr(Fields::plain((string) $text, true), 0, self::MAX);
                if ($text === '' || $text === (string) ($definitions[$key][$locale] ?? '')) {
                    unset($current[$locale][$key]);
                } else {
                    $current[$locale][$key] = $text;
                }
            }
        }
        $current = array_filter($current);
        Options::set(self::OPTION, $current);
        PageCache::flush();

        return $current;
    }

    /** Yedekten: bütün kayıtlar değiştirilir (bilinmeyen anahtarlar da saklanır; sonraki derlemede çıkabilir). */
    public static function replace(array $values): int
    {
        $clean = [];
        foreach ($values as $locale => $texts) {
            if (! Locales::valid((string) $locale) || ! is_array($texts)) {
                continue;
            }
            foreach ($texts as $key => $text) {
                if (is_scalar($text) && preg_match('/^[\w.-]+$/', (string) $key)) {
                    $clean[$locale][(string) $key] = mb_substr(Fields::plain((string) $text, true), 0, self::MAX);
                }
            }
        }
        Options::set(self::OPTION, $clean);

        return array_sum(array_map('count', $clean));
    }

    /** Sayfa kabuğundaki işaretleri doldurur. */
    public static function apply(string $html, string $locale, ?array $definitions = null): string
    {
        $over = self::overrides()[$locale] ?? [];
        $html = (string) preg_replace_callback(
            '#<!--km:t ([\w.-]+)-->(.*?)<!--/km:t-->#s',
            static fn ($m) => isset($over[$m[1]]) ? nl2br(htmlspecialchars($over[$m[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) : $m[2],
            $html
        );
        if (! $over) {
            return $html;
        }

        $definitions ??= self::definitions();
        $pairs = [];
        foreach ($over as $key => $text) {
            $default = (string) ($definitions[$key][$locale] ?? '');
            if ($default !== '') {
                $pairs[self::jsonString($default)] = self::jsonString($text);
            }
        }
        if (! $pairs) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#(<script type="application/ld\+json">)(.*?)(</script>)#s',
            static fn ($m) => $m[1].strtr($m[2], $pairs).$m[3],
            $html
        );
    }

    /** Derlemedeki JSON ile aynı kodlama (JSON.stringify + `<` kaçışı). */
    private static function jsonString(string $text): string
    {
        return str_replace('<', '<', Text::json($text));
    }
}
