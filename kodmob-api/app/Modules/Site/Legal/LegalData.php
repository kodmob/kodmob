<?php

namespace App\Modules\Site\Legal;

use App\Core\Module;
use App\Core\Modules;
use App\Core\Settings\SettingsService;
use App\Core\Support\SiteManifest;
use App\Modules\Site\Blocks\Template;
use App\Modules\Site\Render\Router;
use App\Modules\Site\SiteModule;

/**
 * Yasal belge şablonunun verisi (tv/src/legal/<ad>/<dil>.html):
 *
 *   site.name, site.url                          sitenin adı ve adresi
 *   controller.name|address|email|phone|tax|mersis|kep|court   Proje Ayarları › Yasal (zorunlu üçü boşsa köşeli parantezli uyarı)
 *   controllerCard                               veri sorumlusu kutusu (kaçışlı HTML; `{{{controllerCard}}}`)
 *   modules.<anahtar>                            açık isteğe bağlı modüller (`{{#modules.stripe}}…{{/modules.stripe}}`)
 *   links.<belge>                                açık belgelerin bu dildeki adresi
 *   …                                            modüllerin `legal()` kancası (retention.briefs, commerce.shippingFee…)
 */
final class LegalData
{
    private const PLACEHOLDERS = [
        'tr' => ['name' => '[Unvan: Proje Ayarları › Yasal]', 'address' => '[Adres: Proje Ayarları › Yasal]', 'email' => '[E-posta: Proje Ayarları › Yasal]', 'court' => '[Yetkili mahkeme: Proje Ayarları › Yasal]'],
        'en' => ['name' => '[Company name: Project Settings › Legal]', 'address' => '[Address: Project Settings › Legal]', 'email' => '[E-mail: Project Settings › Legal]', 'court' => '[Competent courts: Project Settings › Legal]'],
        'de' => ['name' => '[Firmenname: Projekteinstellungen › Rechtliches]', 'address' => '[Adresse: Projekteinstellungen › Rechtliches]', 'email' => '[E-Mail: Projekteinstellungen › Rechtliches]', 'court' => '[Gerichtsstand: Projekteinstellungen › Rechtliches]'],
    ];

    private const LABELS = [
        'tr' => ['email' => 'E-posta', 'phone' => 'Telefon', 'mersis' => 'MERSİS no', 'kep' => 'KEP'],
        'en' => ['email' => 'E-mail', 'phone' => 'Phone', 'mersis' => 'MERSIS no.', 'kep' => 'Registered e-mail (KEP)'],
        'de' => ['email' => 'E-Mail', 'phone' => 'Telefon', 'mersis' => 'MERSIS-Nr.', 'kep' => 'KEP (registrierte E-Mail)'],
    ];

    /** @return array<string,mixed> */
    public static function for(string $locale, ?Router $router = null): array
    {
        $controller = self::controller($locale);
        $data = [
            'site' => ['name' => SiteManifest::siteName($locale), 'url' => rtrim((string) config('app.url'), '/')],
            'controller' => $controller,
            'controllerCard' => self::card($controller, $locale),
            'modules' => array_fill_keys(Modules::keys(), true),
            'links' => self::links($locale, $router),
        ];
        foreach (Modules::classes() as $class) {
            /** @var Module $module */
            $module = new $class;
            $data = array_replace_recursive($data, $module->legal($locale));
        }

        return $data;
    }

    /** Yasal bilgilerden eksik olanlar (panelde uyarı): zorunlu alanların etiketleri. */
    public static function missing(): array
    {
        $s = SettingsService::instance();
        $missing = [];
        if (trim($s->string(SiteModule::LEGAL_NAME)) === '') {
            $missing[] = 'Veri sorumlusu (unvan)';
        }
        if (trim($s->string(SiteModule::LEGAL_ADDRESS)) === '' && trim($s->string(SiteModule::ADDRESS)) === '') {
            $missing[] = 'Adres';
        }
        if (trim($s->string(SiteModule::LEGAL_EMAIL)) === '' && trim($s->string(SiteModule::EMAIL)) === '') {
            $missing[] = 'E-posta';
        }
        if (trim($s->string(SiteModule::LEGAL_COURT)) === '') {
            $missing[] = 'Yetkili mahkeme';
        }

        return $missing;
    }

    /** @return array<string,string> */
    private static function controller(string $locale): array
    {
        $s = SettingsService::instance();
        $hint = self::PLACEHOLDERS[$locale] ?? self::PLACEHOLDERS['en'];
        $value = static fn (string ...$keys) => array_values(array_filter(array_map(static fn ($k) => trim($s->string($k)), $keys)))[0] ?? '';

        return [
            'name' => $value(SiteModule::LEGAL_NAME) ?: $hint['name'],
            'address' => $value(SiteModule::LEGAL_ADDRESS, SiteModule::ADDRESS) ?: $hint['address'],
            'email' => $value(SiteModule::LEGAL_EMAIL, SiteModule::EMAIL) ?: $hint['email'],
            'court' => $value(SiteModule::LEGAL_COURT) ?: $hint['court'],
            'phone' => $value(SiteModule::PHONE),
            'tax' => $value(SiteModule::LEGAL_TAX),
            'mersis' => $value(SiteModule::LEGAL_MERSIS),
            'kep' => $value(SiteModule::LEGAL_KEP),
        ];
    }

    /** Veri sorumlusu kutusu: değerler kaçışlı (şablonda `{{{controllerCard}}}`). */
    private static function card(array $c, string $locale): string
    {
        $label = self::LABELS[$locale] ?? self::LABELS['en'];
        $e = static fn (string $v) => Template::escape($v);
        $lines = ['<strong>'.$e($c['name']).'</strong>', $e($c['address'])];
        if ($c['tax'] !== '') {
            $lines[] = $e($c['tax']);
        }
        if ($c['mersis'] !== '') {
            $lines[] = $label['mersis'].': '.$e($c['mersis']);
        }
        $email = str_contains($c['email'], '@') ? '<a href="mailto:'.$e($c['email']).'">'.$e($c['email']).'</a>' : $e($c['email']);
        $lines[] = $label['email'].': '.$email;
        if ($c['phone'] !== '') {
            $lines[] = $label['phone'].': '.$e($c['phone']);
        }
        if ($c['kep'] !== '') {
            $lines[] = $label['kep'].': '.$e($c['kep']);
        }

        return '<p class="legal-card">'.implode('<br>', $lines).'</p>';
    }

    /** @return array<string,string> açık belgelerin bu dildeki adresi */
    private static function links(string $locale, ?Router $router): array
    {
        $links = [];
        foreach (array_keys(LegalDocs::active()) as $name) {
            if ($url = $router?->url($name, $locale)) {
                $links[$name] = $url;
            }
        }

        return $links;
    }
}
