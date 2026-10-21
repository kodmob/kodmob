<?php

namespace App\Core\Support;

use App\Core\Content\Locales;
use App\Modules\Site\Render\Router;

/**
 * Sitenin derleme bilgisi (`km-manifest.json`, site kökünde): siteye özgü olan her şeyin sunucudaki
 * tek kaynağı. Proje başına değişenler kodda değil derlemededir (tv):
 *
 * - `strings`: dil başına site adı, açıklama, sunucunun bastığı metinler (brif yanıt e-postası,
 *   içindekiler başlığı) — tv/src/profiles/<ad>/i18n/<dil>.json;
 * - `vocab`: sözlükler (proje ve blog kategorileri, brif hizmetleri): dil → anahtar → etiket —
 *   anahtarlar varsayılan dilin sözlüğündeki sırayla. Kayıt yalnızca bu anahtarları taşıyabilir;
 *   yeni kategori sözlüğe eklenip build alınınca panelde ve doğrulamada görünür.
 *
 * Laravel içinden okunur (render.php Router ile kendisi okur). İstek içinde bir kez çözülür.
 */
final class SiteManifest
{
    /** @var array<string,array> dosya yolu → içerik */
    private static array $cache = [];

    public static function data(): array
    {
        $file = Paths::manifest();
        if (! array_key_exists($file, self::$cache)) {
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            // Panelden verilen site adı ve logo derlemedekinin yerine (Site\SiteProfile).
            self::$cache[$file] = is_array($data) ? Router::profiled($data) : [];
        }

        return self::$cache[$file];
    }

    /** @var array<string,array> dosya yolu → profil */
    private static array $profiles = [];

    /**
     * Projenin profili (tv/src/profiles/<ad>/site.config.js `profile`; `name`: profilin adı): açık isteğe bağlı modüller (`modules`), içerik
     * türleri (`contentTypes`) ve her sayfada gelen türler (`needs`). Ham okunur: modül listesi uygulama
     * açılırken, ayarlar okunmadan gerekir.
     *
     * @return array{name?:string,modules?:list<string>,contentTypes?:list<string>,needs?:list<string>}
     */
    public static function profile(): array
    {
        $file = Paths::manifest();
        if (! array_key_exists($file, self::$profiles)) {
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$profiles[$file] = is_array($data['profile'] ?? null) ? $data['profile'] : [];
        }

        return self::$profiles[$file];
    }

    public static function forget(): void
    {
        self::$cache = [];
        self::$profiles = [];
    }

    /**
     * Kayıt sayfaları: sitenin rotalarındaki `dynamic` (kayıt adı: post, project…) → rota adı (ilki).
     * Hangi içerik türünün kendi sayfası olduğu buradan bilinir (Types::paged()).
     *
     * @return array<string,string>
     */
    public static function pageRoutes(): array
    {
        $out = [];
        foreach ((array) (self::data()['routes'] ?? []) as $route) {
            $kind = (string) ($route['dynamic'] ?? '');
            if ($kind !== '' && ! isset($out[$kind])) {
                $out[$kind] = (string) ($route['name'] ?? $kind);
            }
        }

        return $out;
    }

    /** Dilin metni: istenen dil → varsayılan dil → `$fallback`. */
    public static function string(string $key, ?string $locale = null, string $fallback = ''): string
    {
        $strings = (array) (self::data()['strings'] ?? []);
        foreach (array_unique([$locale ?? Locales::default(), Locales::default()]) as $code) {
            $value = $strings[$code][$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    /** Site adı (manifest; derleme yoksa uygulama adı). */
    public static function siteName(?string $locale = null): string
    {
        return self::string('siteName', $locale, (string) config('app.name', 'Site'));
    }

    /**
     * Sözlük: anahtar → etiket (istenen dilde; eksik etiket varsayılan dilden, o da yoksa anahtar).
     * Derlemede sözlük yoksa boş dizi: seçim alanları o zaman değeri olduğu gibi korur (Fields).
     *
     * @return array<string,string>
     */
    public static function vocab(string $name, ?string $locale = null): array
    {
        $vocab = (array) (self::data()['vocab'][$name] ?? []);
        $base = (array) ($vocab[Locales::default()] ?? reset($vocab) ?: []);
        $wanted = (array) ($vocab[$locale ?? Locales::default()] ?? []);
        $out = [];
        foreach ($base as $key => $label) {
            $out[(string) $key] = (string) ($wanted[$key] ?? $label ?: $key);
        }

        return $out;
    }

    /** Değer sözlükte mi; sözlük yoksa (derleme eski) her anahtar geçerli sayılır. */
    public static function inVocab(string $name, string $key): bool
    {
        $vocab = self::vocab($name);

        return $vocab === [] ? $key !== '' : array_key_exists($key, $vocab);
    }

    /** Dilin ana sayfası: `/`, `/en/` (önek derlemeden). */
    public static function home(string $locale): string
    {
        $prefix = (string) (self::data()['locales'][$locale]['prefix'] ?? $locale);

        return $prefix === '' ? '/' : '/'.$prefix.'/';
    }
}
