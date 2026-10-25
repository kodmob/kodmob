<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\MediaPayload;
use App\Core\Settings\SettingsService;
use App\Core\Support\SiteManifest;
use App\Modules\Media\MediaLibrary;
use App\Modules\Seo\Seo;
use App\Modules\Site\SiteModule;

/**
 * Sayfa üretilirken (Site\Render\Renderer) `<head>`'e SEO katkısı. SEO kapalıysa
 * hiçbir şey yapmaz; açıksa alt modüllere göre:
 *
 * - meta: sabit sayfada panelden yazılan başlık / açıklama / noindex (PageMeta)
 * - social: paylaşım görseli (sayfanın, yoksa varsayılan) ve twitter:site
 * - webmaster: Google, Bing, Yandex, Baidu doğrulama etiketleri
 * - schema: kurum / yerel işletme ve WebSite şeması (derlemedekinin yerine)
 *
 * Kayıt sayfalarının başlık/açıklaması Renderer'da SeoContent::resolve ile kurulur.
 */
final class SeoHead
{
    /** Sabit sayfa (kabuk derlemeden gelir). */
    public static function staticPage(string $html, array $route, string $locale, string $site): string
    {
        if (! Seo::enabled()) {
            return $html;
        }
        $image = '';
        if (Seo::on('meta') && ($o = PageMeta::get((string) $route['name'], $locale))) {
            if ($o['title'] !== '') {
                $html = preg_replace('#<title>.*?</title>#s', '<title>'.self::e($o['title']).'</title>', $html, 1) ?? $html;
                $html = self::setMeta($html, 'property', 'og:title', $o['title']);
            }
            if ($o['description'] !== '') {
                $html = self::setMeta($html, 'name', 'description', $o['description']);
                $html = self::setMeta($html, 'property', 'og:description', $o['description']);
            }
            if ($o['noindex']) {
                $html = self::noindex($html);
            }
            if ($o['image_id'] && Seo::on('social')) {
                $image = self::imageUrl($o['image_id'], $locale, $site);
            }
        }

        return self::common($html, $locale, $site, $image);
    }

    /** Kayıt sayfası: Renderer etiketleri kurduktan sonra ortak katkılar. */
    public static function entryPage(string $html, string $locale, string $site, bool $noindex, bool $hasImage): string
    {
        if (! Seo::enabled()) {
            return $html;
        }
        if ($noindex) {
            $html = self::noindex($html);
        }

        return self::common($html, $locale, $site, '', $hasImage);
    }

    private static function common(string $html, string $locale, string $site, string $image, bool $hasImage = false): string
    {
        $tags = [];
        if (Seo::on('social')) {
            if (! $hasImage && ! str_contains($html, 'property="og:image"')) {
                $image = $image !== '' ? $image : self::imageUrl((int) Seo::setting('seo.default_image'), $locale, $site);
                if ($image !== '') {
                    $tags[] = '<meta property="og:image" content="'.self::e($image).'">';
                    $tags[] = '<meta name="twitter:card" content="summary_large_image">';
                }
            }
            $handle = trim((string) Seo::setting('seo.twitter'));
            if ($handle !== '') {
                $tags[] = '<meta name="twitter:site" content="'.self::e('@'.ltrim($handle, '@')).'">';
            }
        }
        if (Seo::on('webmaster')) {
            foreach (['google' => 'google-site-verification', 'bing' => 'msvalidate.01', 'yandex' => 'yandex-verification', 'baidu' => 'baidu-site-verification'] as $key => $name) {
                $value = trim((string) Seo::setting('seo.verify_'.$key));
                if ($value !== '') {
                    $tags[] = '<meta name="'.$name.'" content="'.self::e($value).'">';
                }
            }
        }
        if ($tags) {
            $html = preg_replace('#(<meta name="robots" content="[^"]*">)#', '$1'."\n  ".implode("\n  ", $tags), $html, 1) ?? $html;
        }
        if (Seo::on('schema')) {
            $html = preg_replace_callback(
                '#<script type="application/ld\+json">\{"@context":"https://schema\.org","@type":"[A-Za-z]+","@id":"[^"]*\#organization"[^<]*</script>#',
                static fn () => '<script type="application/ld+json">'.self::json(self::organization($locale, $site)).'</script>',
                $html,
                1
            ) ?? $html;
        }

        return $html;
    }

    /** Kurum + WebSite şeması (ayarlardaki adres, iletişim ve sosyal profillerle). */
    public static function organization(string $locale, string $site): array
    {
        $s = SettingsService::instance();
        $type = (string) Seo::setting('seo.org_type') ?: 'ProfessionalService';
        $name = trim((string) Seo::setting('seo.org_name')) ?: SiteManifest::siteName($locale);
        $logo = self::imageUrl((int) Seo::setting('seo.org_logo'), $locale, $site, 'full') ?: $site.'/assets/favicon/favicon.svg';
        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => trim((string) $s->get(SiteModule::ADDRESS)),
            'addressLocality' => trim((string) Seo::setting('seo.org_city')),
            'addressRegion' => trim((string) Seo::setting('seo.org_region')),
            'postalCode' => trim((string) Seo::setting('seo.org_postal')),
            'addressCountry' => strtoupper(trim((string) Seo::setting('seo.org_country'))),
        ]);
        $sameAs = array_values(array_filter(array_map(static fn ($k) => trim((string) $s->get($k)), [SiteModule::LINKEDIN, SiteModule::INSTAGRAM, SiteModule::YOUTUBE, SiteModule::X])));
        $org = array_filter([
            '@type' => $type,
            '@id' => $site.'/#organization',
            'name' => $name,
            'url' => $site.SiteManifest::home($locale),
            'logo' => $logo,
            'image' => $logo,
            'email' => trim((string) $s->get(SiteModule::EMAIL)),
            'telephone' => trim((string) $s->get(SiteModule::PHONE)),
            'address' => count($address) > 1 ? $address : null,
            'areaServed' => array_values(array_filter(array_map('trim', explode(',', (string) Seo::setting('seo.org_area'))))),
            'foundingDate' => trim((string) Seo::setting('seo.org_founded')),
            'sameAs' => $sameAs,
        ], static fn ($v) => $v !== '' && $v !== null && $v !== []);
        if ($type !== 'Organization') {
            $lat = str_replace(',', '.', trim((string) Seo::setting('seo.org_lat')));
            $lng = str_replace(',', '.', trim((string) Seo::setting('seo.org_lng')));
            if (is_numeric($lat) && is_numeric($lng)) {
                $org['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng];
            }
            $org['openingHoursSpecification'] = [[
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'opens' => sprintf('%02d:00', (int) $s->get(SiteModule::HOURS_START)),
                'closes' => sprintf('%02d:00', min(23, (int) $s->get(SiteModule::HOURS_END))).((int) $s->get(SiteModule::HOURS_END) >= 24 ? ':59' : ''),
            ]];
            if (($price = trim((string) Seo::setting('seo.org_price'))) !== '') {
                $org['priceRange'] = $price;
            }
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                $org,
                ['@type' => 'WebSite', '@id' => $site.'/#website', 'url' => $site.'/', 'name' => $name, 'inLanguage' => $locale, 'publisher' => ['@id' => $site.'/#organization']],
            ],
        ];
    }

    public static function imageUrl(int $id, string $locale, string $site, string $size = 'large'): string
    {
        if ($id <= 0 || ! MediaLibrary::find($id)) {
            return '';
        }
        $src = (string) (MediaPayload::image($id, $size, $locale)['src'] ?? '');

        return $src === '' ? '' : (str_starts_with($src, 'http') ? $src : $site.$src);
    }

    private static function noindex(string $html): string
    {
        return preg_replace_callback('#<meta name="robots" content="([^"]*)">#', static fn ($m) => str_contains($m[1], 'noindex') ? $m[0] : '<meta name="robots" content="noindex, follow">', $html, 1) ?? $html;
    }

    private static function setMeta(string $html, string $attr, string $name, string $value): string
    {
        $tag = '<meta '.$attr.'="'.$name.'" content="'.self::e($value).'">';
        $pattern = '#<meta '.$attr.'="'.preg_quote($name, '#').'" content="[^"]*">#';

        return preg_match($pattern, $html) ? (preg_replace($pattern, $tag, $html, 1) ?? $html) : (preg_replace('#</title>#', '</title>'."\n  ".$tag, $html, 1) ?? $html);
    }

    private static function json(mixed $value): string
    {
        return str_replace('<', '<', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
