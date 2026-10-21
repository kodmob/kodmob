<?php

namespace App\Modules\Content;

use App\Core\Content\Locales;
use App\Core\Content\Types;
use App\Core\Support\Paths;
use App\Core\Support\SiteManifest;
use App\Modules\Blog\BlogPosts;
use App\Modules\I18n\I18nSettings;
use App\Modules\I18n\LanguageReport;
use App\Modules\Site\Render\Router;
use App\Modules\Site\SiteModule;

/**
 * Bir sayfanın ihtiyaç duyduğu içerik (tek dil). Sayfa kabuğuna render.php
 * gömer; gömülmemişse (geliştirme sunucusu) tarayıcı `GET /page-data` ile ister.
 *
 * Her sayfada: `site` (iletişim, sosyal medya, yasal), diller ve profilin her sayfada istediği
 * içerik türleri (`profile.needs`; kodmob.com: hizmetler, alt bilgi listesi). Rotanın istediği ek
 * veriler (`needs`): içerik türü adları (projects, references…), posts (blog), estimate (teklif
 * hesaplayıcının fiyatları). Kayıt sayfasında (`dynamic` rota) `entry` (adresle bulunan kayıt);
 * yoksa `notFound: true`.
 */
final class PageData
{
    /** İçerik türü olmayan ihtiyaçlar. */
    public const NEEDS = ['posts', 'estimate'];

    public static function build(string $route, string $locale, string $slug = '', array $needs = []): array
    {
        $locale = Locales::normalize($locale);
        $kind = self::kindOf($route);
        $types = Types::all();
        $needs = array_values(array_filter($needs, static fn ($need) => in_array($need, self::NEEDS, true) || isset($types[$need])));
        $page = $kind !== null && $kind !== 'post' ? Types::page($kind) : null;
        if ($page !== null) {
            $needs[] = $page->name();
        }
        if ($kind === 'post') {
            $needs[] = 'posts';
        }

        $data = [
            'site' => SiteModule::payload(),
            // Yayındaki diller: dil seçici ve öneri bandı pasif dili göstermez (i18n/client.js isLive).
            'languages' => Locales::active(),
            // Dil önerisi bandı (Çoklu Dil > Ziyaretçi; tek dilli modda kapalı).
            'langBanner' => I18nSettings::banner(),
        ];
        foreach (self::everyPage() as $name) {
            if (isset($types[$name])) {
                $data[$name] = $types[$name]->publicList($locale);
            }
        }
        foreach (array_unique($needs) as $need) {
            if ($need === 'posts') {
                $data['posts'] = (new BlogPosts)->publicList($locale);
            } elseif ($need === 'estimate') {
                $data['estimate'] = EstimateConfig::get();
            } else {
                $data[$need] ??= $types[$need]->publicList($locale);
            }
        }

        if ($kind === 'post') {
            $data['entry'] = $slug !== '' ? (new BlogPosts)->findBySlug($slug, $locale) : null;
        } elseif ($kind !== null) {
            $record = $slug !== '' ? $page?->findBySlug($slug, $locale) : null;
            $data['entry'] = $record ? $page->localize($record, $locale) : null;
        }
        // 404: "sık ziyaret edilenler" (Çoklu Dil > Ziyaretçi; boşsa sayfadaki varsayılan bölümler).
        if (strtolower($route) === 'notfound') {
            $data['popular'] = LanguageReport::popular()[$locale] ?? [];
        }
        if (array_key_exists('entry', $data) && $data['entry'] === null) {
            $data['notFound'] = true;
        }

        return $data;
    }

    /**
     * Site içi arama dizininin içerik kısmı (sayfalar tarayıcıda eklenir). Her kayıt kendi adresini
     * (`url`) ve kayıt adını (`type`: service, project, post; sonuç etiketi) taşır: her sayfada gelen
     * türler (kodmob.com: hizmetler) kendi adındaki sayfanın bölümüne (#anahtar), kendi sayfası olan
     * türler (projeler) ve blog kayıt sayfasına gider.
     */
    public static function search(string $locale): array
    {
        $locale = Locales::normalize($locale);
        $router = Router::load(Paths::siteRoot());
        $routes = SiteManifest::pageRoutes();
        $out = [];
        foreach (self::everyPage() as $name) {
            if ($type = Types::get($name)) {
                $section = $router?->url($name, $locale);
                $out[$name] = array_map(static function ($s) use ($type, $section) {
                    $key = (string) ($s['key'] ?? $s['id']);

                    return ['key' => $key, 'title' => $s['title'], 'text' => $s['text'] ?? $s['summary'] ?? '', 'type' => $type->recordKind(), 'url' => $section !== null ? $section.'#'.$key : null];
                }, $type->publicList($locale));
            }
        }
        foreach (Types::paged() as $kind => $type) {
            $out[$type->name()] = array_map(static fn ($entry) => $type->searchItem($entry) + ['type' => $kind, 'url' => $router?->url($routes[$kind], $locale, (string) $entry['slug'])], $type->publicList($locale));
        }
        $out['posts'] = array_map(static fn ($p) => ['slug' => $p['slug'], 'title' => $p['title'], 'text' => $p['excerpt'], 'type' => 'post', 'url' => $router?->url($routes['post'] ?? 'post', $locale, (string) $p['slug'])], (new BlogPosts)->publicList($locale));

        return $out;
    }

    /** Rotanın kayıt adı (`dynamic`); kayıt sayfası değilse null. Eski çağrılar rota adını verir. */
    private static function kindOf(string $route): ?string
    {
        foreach (SiteManifest::pageRoutes() as $kind => $name) {
            if ($name === $route) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Her sayfada gelen içerik türleri: profilin `needs`'i. Profili olmayan eski derleme (canlıda çekirdek
     * derlemeden önce yüklenirse) eskisi gibi hizmetleri alır.
     *
     * @return list<string>
     */
    private static function everyPage(): array
    {
        $profile = SiteManifest::profile();

        return $profile === [] ? ['services'] : ($profile['needs'] ?? []);
    }
}
