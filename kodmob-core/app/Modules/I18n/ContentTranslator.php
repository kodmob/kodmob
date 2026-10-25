<?php

namespace App\Modules\I18n;

use App\Core\Ai\Ai;
use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Content\Translations;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;

/**
 * "Jarvis ile çevir": içerik alanlarını (başlık, özet, HTML, liste, satırlar) bir dilden
 * diğerine etkin yapay zekâ sağlayıcısıyla (Ayarlar > Yapay zekâ; Claude, OpenAI, Gemini) çevirir. Kaydetmez; panel sonucu forma doldurur ya da yeni çeviri
 * yazısı oluşturur.
 *
 * Gönderilen yalnızca içerik metinleridir. Talep sahiplerinin kişisel verisi buradan asla
 * geçmez (done-coklu-dil-raporu.md §8 karar 8). Sağlayıcının anahtarı yoksa `available()` false
 * döner ve panel düğmeleri gizler.
 *
 * Testte ve özel kurulumda çeviri motoru kapsayıcıdan değiştirilebilir:
 *   app()->instance('i18n.translator', fn (array $fields, string $from, string $to) => [...]);
 */
final class ContentTranslator
{
    private const SYSTEM = <<<'TXT'
        You receive a JSON object. Translate every human-readable string value from the source
        language to the target language and return the translated JSON object only.

        Rules:
        - Keep exactly the same keys, nesting and array lengths. Do not add or remove items.
        - Preserve HTML tags, attributes and entities exactly; translate only the visible text.
        - Do not translate URLs, e-mail addresses, code, file names, brand and product names
          (the site's own name, client and technology names).
        - Keep the meaning, tone and length; write naturally for native readers of the target
          language. Keep short labels short.
        - Return JSON only: no comments, no code fences.
        TXT;

    /** Sitenin adı ve tanımı (derlemeden) + kurallar. */
    private static function system(): string
    {
        $about = trim(SiteManifest::string('description'));

        return 'You translate website content for '.SiteManifest::siteName().'.'.($about !== '' ? ' About the site: '.$about : '')."\n".self::SYSTEM;
    }

    /** Anahtar yoksa 422 km_ai_not_configured (çeviri uçları çağırır). */
    public static function ensureAvailable(): void
    {
        if (! self::available()) {
            throw new DomainException('km_ai_not_configured', 'Yapay zekâ sağlayıcısının API anahtarı girilmemiş. Ayarlar > Yapay zekâ\'dan ekleyin.', 422);
        }
    }

    /** Sağlayıcı anahtarı ya da test motoru var mı (panel düğmeleri buna göre görünür). */
    public static function available(): bool
    {
        return app()->bound('i18n.translator') || Ai::available();
    }

    /**
     * @param  array<string,mixed>  $fields  alan adı → metin, metin listesi ya da satırlar
     * @return array<string,mixed> aynı biçimde çevrilmiş alanlar
     */
    public function translate(array $fields, string $from, string $to): array
    {
        if (! Locales::valid($from) || ! Locales::valid($to) || $from === $to) {
            throw ValidationException::field('to', 'Kaynak ve hedef için kayıtlı iki farklı dil seçin.');
        }
        $fields = array_filter($fields, static fn ($v) => is_string($v) || is_array($v));
        if (! Translations::filled($fields)) {
            throw ValidationException::field('fields', 'Kaynak dilde çevrilecek metin yok.');
        }
        $engine = app()->bound('i18n.translator') ? app('i18n.translator') : fn (array $f, string $a, string $b) => $this->model($f, $a, $b);
        $out = $engine($fields, $from, $to);
        if (! is_array($out) || ! self::sameShape($fields, $out)) {
            throw new DomainException('km_translate_failed', 'Çeviri beklenen biçimde dönmedi; yeniden deneyin.', 502);
        }

        return $out;
    }

    private function model(array $fields, string $from, string $to): array
    {
        $name = static function (string $code): string {
            $language = Languages::find($code);

            return $language ? sprintf('%s (%s, %s)', $language['native'], $language['name'], $language['hreflang']) : $code;
        };
        $prompt = sprintf("Source language: %s\nTarget language: %s\n\nJSON:\n%s", $name($from), $name($to), Text::json($fields));
        $text = Ai::complete(self::system(), $prompt, 'low');
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

        return is_array($data) ? $data : [];
    }

    /** Çeviri kaynağın biçimini korudu mu: aynı anahtarlar, aynı uzunlukta diziler, metin yerine metin. */
    private static function sameShape(mixed $source, mixed $result): bool
    {
        if (is_array($source)) {
            if (! is_array($result) || count($source) !== count($result)) {
                return false;
            }
            foreach ($source as $key => $value) {
                if (! array_key_exists($key, $result) || ! self::sameShape($value, $result[$key])) {
                    return false;
                }
            }

            return true;
        }

        return is_string($result) || is_int($result) || is_float($result);
    }
}
