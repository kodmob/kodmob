<?php

namespace App\Core\Content;

use App\Core\Modules;
use App\Core\Support\SiteManifest;
use App\Modules\Blog\BlogPosts;

/**
 * İçerik türlerinin tek kaydı. Türler projenin kaydındandır (`config/project.php` → `content_types`;
 * kodmob.com'un projeler, hizmetler, referanslar türleri app/Project'te) ve açık modüllerin
 * `contentTypes()`'ındandır (Mağaza'nın ürünleri gibi; modülle açılıp kapanır). Sitenin profili
 * (tv/src/profiles/<ad>/site.config.js `profile.contentTypes`) hangilerinin açık olduğunu söyler, vermezse
 * hepsi açıktır. Çatı belirli bir türü bilmez: türe özgü davranış türün kancalarındadır (ContentType:
 * seoDefaults, seoText, structuredData, searchItem). Her tür kendini tanımlar (EditableContent: ad, kayıt
 * adı, yetki, etiket). API rotaları, işlem kaydı, değişiklik akışı, Silinenler, yetki listesi, hazır roller
 * ve yedek buradan türetir; yeni tür için başka dosyaya dokunulmaz (php artisan kodmob:module).
 *
 * `all()`: üç dilli içerik türleri (ContentType); `editable()`: bunlar + blog (BlogPosts);
 * `paged()`: kendi sayfası olanlar (sitenin rotalarında `dynamic: '<kayıt adı>'`).
 * Panel adresi profilin panel.js'indedir (/panel/projeler).
 */
final class Types
{
    /** @var array<string,ContentType>|null */
    private static ?array $types = null;

    /** @return array<string,ContentType> ad → tür (yapılandırmadaki sırayla) */
    public static function all(): array
    {
        if (self::$types === null) {
            self::$types = [];
            $wanted = SiteManifest::profile()['contentTypes'] ?? null;
            foreach ((array) config('project.content_types', []) as $class) {
                $type = new $class;
                if (! is_array($wanted) || in_array($type->name(), $wanted, true)) {
                    self::$types[$type->name()] = $type;
                }
            }
            // Açık modüllerin türleri (Module::contentTypes; profil listesine bağlı değil). Ad çakışırsa projeninki kalır.
            foreach (Modules::contentTypes() as $class) {
                $type = new $class;
                self::$types[$type->name()] ??= $type;
            }
        }

        return self::$types;
    }

    /** @return array<string,EditableContent> panelde düzenlenen her şey: içerik türleri + blog */
    public static function editable(): array
    {
        return self::all() + (Modules::enabled('blog') ? ['posts' => new BlogPosts] : []);
    }

    public static function get(string $name): ?ContentType
    {
        return self::all()[$name] ?? null;
    }

    /** Kayıt adından (project, post): işlem kaydı ve Silinenler. */
    public static function byKind(string $kind): ?EditableContent
    {
        foreach (self::editable() as $type) {
            if ($type->recordKind() === $kind) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Kendi sayfası olan içerik türleri (blog hariç; o `post`): kayıt adı → tür, sitenin rota sırasıyla.
     * Adres geçmişi, site haritası, kayıt sayfasının başlığı, SEO > İçerik, llms.txt, IndexNow ve 404
     * önerileri bunları dolaşır.
     *
     * @return array<string,ContentType>
     */
    public static function paged(): array
    {
        $out = [];
        foreach (array_keys(SiteManifest::pageRoutes()) as $kind) {
            foreach (self::all() as $type) {
                if ($type->recordKind() === $kind) {
                    $out[$kind] = $type;
                }
            }
        }

        return $out;
    }

    /** Kayıt adından sayfası olan tür (yoksa ya da profilde kapalıysa null). */
    public static function page(string $kind): ?ContentType
    {
        return self::paged()[$kind] ?? null;
    }

    /** Rota deseni: projects|services|references|posts */
    public static function pattern(): string
    {
        return implode('|', array_map('preg_quote', array_keys(self::editable())));
    }

    /** İçerik uçlarının yetkisi (`cap:` ara katmanı): türlerden biri yeterlidir, tür ayrıca denetlenir. */
    public static function capabilities(): string
    {
        $caps = array_unique(array_map(static fn (EditableContent $type) => $type->capability(), array_values(self::editable())));

        return implode('|', $caps ?: ['km_manage_posts']);
    }

    /** Yapılandırma değişince (testler) yeniden kurulsun. */
    public static function forget(): void
    {
        self::$types = null;
    }
}
