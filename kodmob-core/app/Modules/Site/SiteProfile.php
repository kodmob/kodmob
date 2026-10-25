<?php

namespace App\Modules\Site;

use App\Core\Settings\SettingsService;
use App\Modules\Media\MediaLibrary;
use Throwable;

/**
 * Site kimliği panelden (Ayarlar > Site ayarları > Kimlik): site adı, logo yazısı ya da görseli, renkler. Boş
 * ayar derlemedeki değeri bırakır (kodmob-frontend/src/i18n: meta.siteName, nav.brand, nav.brandSub; kodmob-frontend/src/styles/tokens.css).
 *
 * Özellikle aynı temayı kullanan alt siteler için (çok site): derleme ana sitenin adını ve rengini taşır, alt
 * site kendininkini buradan verir. Manifest okunurken (Router ve SiteManifest) `strings` değişir, derlemedeki
 * halleri `builtStrings`'te kalır; Router::shell() kabuğa gömülü eski adı (sayfa başlıkları, og:site_name, alt
 * bilgi, yapısal veri) ve logoyu içerik eklenmeden önce değiştirir, renk varsa `theme.css` bağlantısını ekler.
 * Renk belirteçleri `css()`'ten gelir (GET /v1/theme.css; aynı köken, CSP'ye uygun).
 */
final class SiteProfile
{
    public const NAME = 'site.name';

    public const LOGO = 'site.logo';

    public const LOGO_SUB = 'site.logo_sub';

    public const LOGO_IMAGE = 'site.logo_image';

    /** Proje ikonu (ProjectIcon): sekme, telefona eklenen uygulama, anlık bildirim. */
    public const ICON = 'site.icon';

    public const COLOR = 'site.color';

    public const COLOR_DARK = 'site.color_dark';

    /** @return array<string,string> manifest metni → değer (yalnızca dolu ayarlar; Options istek içinde önbellekli) */
    public static function overrides(): array
    {
        try {
            $settings = SettingsService::instance();
            $logo = MediaLibrary::find((int) $settings->get(self::LOGO_IMAGE));

            return array_filter([
                'siteName' => trim((string) $settings->get(self::NAME)),
                'brand' => trim((string) $settings->get(self::LOGO)),
                'brandSub' => trim((string) $settings->get(self::LOGO_SUB)),
                'logoImage' => $logo ? MediaLibrary::url($logo, $logo['file']) : '',
            ], static fn ($v) => $v !== '');
        } catch (Throwable) {
            // Kurulumdan önce (tablo yok) derlemedeki değerler.
            return [];
        }
    }

    /** Manifest'in dil metinlerine ayarları uygular; eski halleri `builtStrings`'e, renk sürümü `themeVersion`'a. */
    public static function apply(array $manifest): array
    {
        if (isset($manifest['builtStrings']) || isset($manifest['themeVersion']) || isset($manifest['iconVersion'])) {
            return $manifest;
        }
        if (($css = self::css()) !== '') {
            $manifest['themeVersion'] = substr(md5($css), 0, 10);
        }
        if (($icon = ProjectIcon::version()) !== null) {
            $manifest['iconVersion'] = $icon;
        }
        $overrides = self::overrides();
        if (! $overrides || ! is_array($manifest['strings'] ?? null)) {
            return $manifest;
        }
        $manifest['builtStrings'] = $manifest['strings'];
        foreach ($manifest['strings'] as $locale => $strings) {
            $built = (string) ($strings['siteName'] ?? '');
            foreach ($overrides as $key => $value) {
                $manifest['strings'][$locale][$key] = $value;
            }
            // Adı içeren metinler (başlık eki, blog başlığı, talep yanıtı e-postası…) yeni adla.
            if (isset($overrides['siteName']) && $built !== '') {
                foreach ($strings as $key => $text) {
                    if (is_string($text) && ! isset($overrides[$key])) {
                        $manifest['strings'][$locale][$key] = str_replace($built, $overrides['siteName'], $text);
                    }
                }
            }
        }

        return $manifest;
    }

    /**
     * Panelde verilen renklerden tasarım belirteçleri (tokens.css'teki adlarla); renk yoksa boş. Tonlar tarayıcıda
     * color-mix ile türetilir; açık ve koyu tema için ayrı (koyu: sınıfla ya da sistem tercihiyle).
     */
    public static function css(): string
    {
        try {
            $settings = SettingsService::instance();
            $primary = (string) $settings->get(self::COLOR);
            $dark = (string) $settings->get(self::COLOR_DARK);
        } catch (Throwable) {
            return '';
        }
        $valid = static fn (string $c) => (bool) preg_match('/^#[0-9a-f]{6}$/', $c);
        $light = $night = [];
        if ($valid($primary)) {
            $light += [
                'primary' => $primary,
                'primary-strong' => $primary,
                'primary-hover' => "color-mix(in oklab, {$primary} 82%, black)",
                'primary-soft' => "color-mix(in oklab, {$primary} 9%, white)",
                'primary-ink' => "color-mix(in oklab, {$primary} 72%, black)",
            ];
            $night += [
                'primary' => "color-mix(in oklab, {$primary} 70%, white)",
                'primary-strong' => $primary,
                'primary-hover' => "color-mix(in oklab, {$primary} 82%, white)",
                'primary-soft' => "color-mix(in oklab, {$primary} 22%, #0B1020)",
                'primary-ink' => "color-mix(in oklab, {$primary} 40%, white)",
            ];
        }
        if ($valid($dark)) {
            $light += [
                'navy' => $dark,
                'navy-2' => "color-mix(in oklab, {$dark} 88%, white)",
                'navy-line' => "color-mix(in oklab, {$dark} 72%, white)",
            ];
            $night += [
                'navy' => "color-mix(in oklab, {$dark} 85%, white)",
                'navy-2' => "color-mix(in oklab, {$dark} 75%, white)",
            ];
        }
        if (! $light) {
            return '';
        }
        $vars = static fn (array $tokens) => implode(' ', array_map(static fn ($k, $v) => "--color-{$k}: {$v};", array_keys($tokens), $tokens));

        return "/* Site kimliği: panelde Ayarlar > Kimlik (Modules\\Site\\SiteProfile). */\n"
            .':root { '.$vars($light)." }\n"
            .':root.theme-dark { '.$vars($night)." }\n"
            .'@media (prefers-color-scheme: dark) { :root:not(.theme-light) { '.$vars($night)." } }\n";
    }
}
