<?php

namespace App\Modules\Site;

use App\Core\Ai\Ai;
use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Content\PageCache;
use App\Core\Content\Types;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Settings\SettingsService;
use App\Core\Sites\Sites;
use App\Core\Support\Text;
use App\Modules\Content\PageTexts;
use App\Modules\I18n\ContentTranslator;
use App\Modules\I18n\I18nConfig;
use App\Modules\I18n\LanguageService;
use App\Modules\Seo\Services\PageMeta;
use App\Modules\Seo\Services\SeoConfig;
use Throwable;

/**
 * Site Sihirbazı (docs/done-yenilik-raporu.md): işletme bilgisinden yeni alt site önerisi, kurulumda uygulanır.
 *
 *   plan()   ana sitede; etkin yapay zekâ sağlayıcısıyla (Ayarlar > Yapay zekâ) tek çağrı: logo yazısı ve alt yazısı,
 *            renkler, site açıklaması, derlemedeki bütün sayfa metinlerinin (tx) işletmeye uyarlanmışı, 3–6 hizmet.
 *            Seçilen başka diller ContentTranslator ile çevrilir. Anahtar yoksa yalnızca kimlik ve renkler (kural
 *            tabanlı). Kaydetmez: panel önizler, kullanıcı düzenler.
 *   apply()  kurulan sitenin bağlamında (Sites::run): Kimlik ayarları, sayfa metinleri, ana sayfanın SEO başlığı ve
 *            açıklaması, seçilmeyen dillerin kapatılması, hizmetler TASLAK olarak.
 *
 * Kurallar: rakam, yıl, müşteri adı uydurulmaz (yalnızca "gerçek bilgiler"den); kişisel veri gönderilmez (yönetici
 * bilgileri öneriye girmez). Öneri panelden geri geldiği için apply() onu yeniden doğrular.
 *
 * Testte motor kapsayıcıdan değiştirilir: app()->instance('sites.wizard', fn (array $request) => [...]).
 */
final class SiteWizard
{
    public const TONES = ['kurumsal' => 'professional and trustworthy', 'samimi' => 'warm and friendly', 'premium' => 'refined and premium', 'teknik' => 'precise and technical'];

    /** Erişilebilir (beyaz yazıyla okunur) ana renkler ve koyu bant eşleri; anahtar yoksa sektöre göre biri. */
    public const PALETTE = [
        ['#1f5eff', '#0a1633'], ['#0e7490', '#082f3a'], ['#047857', '#052e22'], ['#b45309', '#2a1606'],
        ['#be123c', '#2a0a14'], ['#6d28d9', '#1c0f3a'], ['#0f766e', '#062a27'], ['#334155', '#0f172a'],
    ];

    private const SYSTEM = <<<'TXT'
        You are setting up a new website for a small business from a website template. You receive the business
        facts and a JSON object of template texts (key → text written for the template's original company).
        Rewrite every template text for the new business in the requested language and tone.
        Rules:
        - Keep every key. Keep each text's role and length (±30%), line breaks and placeholders like {year} or {name}.
        - Never invent facts: numbers, years, counts, prices, client or partner names, awards, certificates. Use
          only what the facts state. Where a template text is a statistic (keys with ".stats.") and no fact fits,
          write a short non-numeric value or label instead (e.g. "Yerel", "Hızlı dönüş").
        - Plain text only (no HTML, no markdown). Natural, native wording; short labels stay short.
        Return JSON only, no code fences, exactly:
        {"identity": {"logo": "short brand word, lowercase, max 14 chars", "logo_sub": "1-2 words about the sector, max 16 chars",
          "color": "#rrggbb brand color readable with white text", "color_dark": "#rrggbb very dark shade for dark bands"},
         "description": "site description, max 155 chars",
         "services": [{"title": "max 60 chars", "text": "max 220 chars"}],
         "texts": {"<same keys>": "<rewritten text>"}}
        Give 3 to 6 services that the facts state or clearly imply.
        TXT;

    /**
     * @param  array<string,mixed>  $input  name, sector, city, about, facts, tone, color, languages[]
     * @return array<string,mixed>
     */
    public static function plan(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $sector = trim((string) ($input['sector'] ?? ''));
        $about = trim((string) ($input['about'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Site adı gerekli (en çok 100 karakter).';
        }
        if (mb_strlen($sector) < 2 || mb_strlen($sector) > 80) {
            $errors['sector'] = 'Sektörü yazın (ör. diş kliniği).';
        }
        if (mb_strlen($about) < 20) {
            $errors['about'] = 'İşletmeyi birkaç cümleyle anlatın (en az 20 karakter).';
        }
        $color = strtolower(trim((string) ($input['color'] ?? '')));
        if ($color !== '' && ! preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $errors['color'] = 'Renk #1f5eff biçiminde olmalı.';
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }
        $tone = isset(self::TONES[$input['tone'] ?? '']) ? (string) $input['tone'] : 'kurumsal';
        $default = Locales::default();
        $languages = array_values(array_unique([$default, ...array_intersect(array_map('strval', (array) ($input['languages'] ?? [])), Locales::active())]));
        $facts = ['name' => $name, 'sector' => $sector, 'city' => trim((string) ($input['city'] ?? '')), 'about' => mb_substr($about, 0, 1200), 'facts' => mb_substr(trim((string) ($input['facts'] ?? '')), 0, 800)];

        [$primary, $dark] = self::PALETTE[abs(crc32(mb_strtolower($sector))) % count(self::PALETTE)];
        $plan = [
            'ai' => false,
            'provider' => '',
            'default' => $default,
            'languages' => $languages,
            'sector' => $sector,
            'identity' => ['logo' => self::logo($name), 'logo_sub' => mb_substr(mb_strtolower($sector), 0, 16), 'color' => $color ?: $primary, 'color_dark' => $dark],
            'description' => mb_strimwidth(preg_replace('/\s+/', ' ', $about) ?? $about, 0, 155, '…'),
            'texts' => [],
            'services' => [],
            'i18n' => (object) [],
            'counts' => ['texts' => 0],
            'warnings' => [],
        ];
        $engine = app()->bound('sites.wizard') ? app('sites.wizard') : (Ai::available() ? static fn (array $request) => self::model($request) : null);
        if ($engine === null) {
            $plan['warnings'][] = 'Yapay zekâ anahtarı girilmemiş (Ayarlar > Yapay zekâ): yalnızca logo yazısı ve renkler önerildi; sayfa metinleri şablondaki gibi kalır.';

            return $plan;
        }

        $source = array_filter(array_map(static fn ($d) => (string) ($d[$default] ?? ''), PageTexts::definitions()), static fn ($t) => $t !== '');
        $result = $engine(['facts' => $facts, 'tone' => self::TONES[$tone], 'language' => self::languageName($default), 'texts' => $source]);
        if (! is_array($result) || ! is_array($result['texts'] ?? null)) {
            throw new DomainException('km_wizard_failed', 'Yapay zekâ önerisi beklenen biçimde dönmedi; yeniden deneyin.', 502);
        }
        $identity = (array) ($result['identity'] ?? []);
        $hex = static fn ($v, string $fallback) => preg_match('/^#[0-9a-f]{6}$/', strtolower((string) $v)) ? strtolower((string) $v) : $fallback;
        $plan['ai'] = true;
        $plan['provider'] = self::providerLabel();
        $plan['identity'] = [
            'logo' => mb_substr(trim((string) ($identity['logo'] ?? '')), 0, 24) ?: $plan['identity']['logo'],
            'logo_sub' => mb_substr(trim((string) ($identity['logo_sub'] ?? '')), 0, 24) ?: $plan['identity']['logo_sub'],
            'color' => $color ?: $hex($identity['color'] ?? '', $primary),
            'color_dark' => $hex($identity['color_dark'] ?? '', $dark),
        ];
        $plan['description'] = mb_substr(trim((string) ($result['description'] ?? '')), 0, 160) ?: $plan['description'];
        $plan['services'] = self::services((array) ($result['services'] ?? []));
        $texts = array_map(static fn ($t) => mb_substr(trim((string) $t), 0, PageTexts::MAX), array_intersect_key(array_filter((array) $result['texts'], 'is_scalar'), $source));
        $plan['texts'] = [$default => $texts];
        $plan['counts']['texts'] = count($texts);
        if (count($texts) < count($source) * 0.8) {
            $plan['warnings'][] = sprintf('Yapay zekâ %d metnin %d tanesini uyarladı; kalanlar şablondaki gibi kalır.', count($source), count($texts));
        }

        // Diğer diller: aynı içerik çevrilir (metinler, hizmetler, açıklama).
        $i18n = [];
        foreach (array_diff($languages, [$default]) as $locale) {
            try {
                $out = (new ContentTranslator)->translate(['texts' => $texts, 'services' => $plan['services'], 'description' => $plan['description']], $default, $locale);
                $plan['texts'][$locale] = array_map('strval', (array) $out['texts']);
                $i18n[$locale] = ['services' => self::services((array) $out['services']), 'description' => mb_substr((string) $out['description'], 0, 160)];
            } catch (Throwable $e) {
                $plan['warnings'][] = self::languageName($locale).' çevrilemedi ('.$e->getMessage().'); bu dil şablondaki metinlerle açılır.';
            }
        }
        $plan['i18n'] = (object) $i18n;

        return $plan;
    }

    /**
     * Kurulan sitenin bağlamında (Sites::run içinde) öneriyi uygular. Öneri panelden geldiği için yeniden doğrulanır.
     *
     * @param  array<string,mixed>  $plan
     * @return array{texts:int,services:int,languages:list<string>}
     */
    public static function apply(array $plan, string $siteName): array
    {
        $settings = SettingsService::instance();
        $identity = (array) ($plan['identity'] ?? []);
        $values = [
            SiteProfile::NAME => $siteName,
            SiteProfile::LOGO => mb_substr(trim((string) ($identity['logo'] ?? '')), 0, 24),
            SiteProfile::LOGO_SUB => mb_substr(trim((string) ($identity['logo_sub'] ?? '')), 0, 24),
            SiteProfile::COLOR => (string) ($identity['color'] ?? ''),
            SiteProfile::COLOR_DARK => (string) ($identity['color_dark'] ?? ''),
        ];
        foreach ($values as $key => $value) {
            try {
                $settings->set($key, $value);
            } catch (ValidationException) {
                // Geçersiz renk: derlemedeki kalır.
            }
        }

        $default = Locales::default();
        $languages = array_values(array_unique([$default, ...array_intersect(array_map('strval', (array) ($plan['languages'] ?? [])), Locales::active())]));
        $texts = array_intersect_key((array) ($plan['texts'] ?? []), array_flip($languages));
        $saved = PageTexts::save($texts);

        // Ana sayfanın arama sonucu başlığı ve açıklaması (dil başına).
        $i18n = (array) ($plan['i18n'] ?? []);
        $sector = trim((string) ($plan['sector'] ?? ''));
        foreach ($languages as $locale) {
            $description = $locale === $default ? (string) ($plan['description'] ?? '') : (string) ($i18n[$locale]['description'] ?? '');
            try {
                PageMeta::save('home', $locale, ['title' => trim($siteName.($sector !== '' && $locale === $default ? ' · '.$sector : '')), 'description' => $description]);
            } catch (ValidationException) {
                // Derlemede o dilde ana sayfa yoksa atlanır.
            }
        }

        // Seçilmeyen diller kapatılır (varsayılan dil açık kalır).
        foreach (Locales::active() as $locale) {
            if (! in_array($locale, $languages, true)) {
                (new LanguageService)->update($locale, ['active' => false]);
            }
        }
        Languages::forget();

        // Hizmetler taslak: sahibi gözden geçirip yayınlar.
        $count = 0;
        $keys = [];
        foreach (self::services((array) ($plan['services'] ?? [])) as $index => $service) {
            $key = Text::slug($service['title']) ?: 'hizmet-'.($index + 1);
            while (in_array($key, $keys, true)) {
                $key .= '-'.($index + 1);
            }
            $keys[] = $key;
            $i18nIn = [$default => ['title' => $service['title'], 'text' => $service['text']]];
            foreach ($languages as $locale) {
                if ($locale !== $default && isset($i18n[$locale]['services'][$index])) {
                    $i18nIn[$locale] = ['title' => (string) $i18n[$locale]['services'][$index]['title'], 'text' => (string) $i18n[$locale]['services'][$index]['text']];
                }
            }
            // Projenin "services" türü varsa (kodmob.com) taslak hizmet; yoksa atlanır.
            Types::get('services')?->save(null, ['status' => 'draft', 'key' => $key, 'icon' => 'layers', 'i18n' => $i18nIn]);
            $count++;
        }

        I18nConfig::compile();
        SeoConfig::compile();
        PageCache::flush();

        return ['texts' => array_sum(array_map('count', $saved)), 'services' => $count, 'languages' => $languages];
    }

    /** @return list<array{title:string,text:string}> */
    private static function services(array $items): array
    {
        $out = [];
        foreach (array_slice($items, 0, 6) as $item) {
            $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 80);
            if ($title !== '') {
                $out[] = ['title' => $title, 'text' => mb_substr(trim((string) ($item['text'] ?? '')), 0, 300)];
            }
        }

        return $out;
    }

    /** Ad → logo yazısı: ilk kelime, küçük harf (ör. "Gülümse Diş Kliniği" → "gülümse"). */
    private static function logo(string $name): string
    {
        $first = preg_split('/\s+/u', trim($name))[0] ?? $name;

        return mb_substr(mb_strtolower($first, 'UTF-8'), 0, 14);
    }

    private static function languageName(string $code): string
    {
        $language = Languages::find($code);

        return $language ? sprintf('%s (%s)', $language['native'], $language['name']) : $code;
    }

    private static function providerLabel(): string
    {
        try {
            return Ai::provider()->label();
        } catch (Throwable) {
            return 'yapay zekâ';
        }
    }

    /** @param array{facts:array<string,string>,tone:string,language:string,texts:array<string,string>} $request */
    private static function model(array $request): array
    {
        $facts = array_filter($request['facts'], static fn ($v) => $v !== '');
        $prompt = sprintf("Language: %s\nTone: %s\n\nBusiness facts (JSON):\n%s\n\nTemplate texts (JSON):\n%s", $request['language'], $request['tone'], Text::json($facts), Text::json($request['texts']));
        $text = Ai::complete(self::SYSTEM, $prompt, 'low', 32000);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

        return is_array($data) ? $data : [];
    }
}
