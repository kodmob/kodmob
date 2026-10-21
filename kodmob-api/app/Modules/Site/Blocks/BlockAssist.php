<?php

namespace App\Modules\Site\Blocks;

use App\Core\Ai\Ai;
use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\I18n\ContentTranslator;

/**
 * Sayfa düzenleyicide Jarvis (etkin yapay zekâ sağlayıcısı; Ayarlar › Yapay zekâ):
 *
 * - `write`: seçili bloğun bir dildeki metinlerini isteğe göre yazar ("daha kısa", "kurumsal", "tatil acentesi
 *   için"…); biçim aynı kalır (alanlar, satır sayısı).
 * - `translate`: bloğun metinlerini diğer dillere (ContentTranslator).
 * - `suggest`: sayfaya kitaplıktaki bloklardan sıralı bölümler ve metinleri önerir (taslağa eklenir).
 *
 * Gönderilen yalnızca bloğun metinleri, sitenin adı / tanımı ve kullanıcının isteğidir; kişisel veri yoktur.
 * Testte motor kapsayıcıdan değiştirilir: `blocks.assist` (write/suggest), `i18n.translator` (çeviri).
 */
final class BlockAssist
{
    /** Metin taşıyan alan ve sütun türleri. */
    private const TEXT = ['text', 'textarea', 'html'];

    private const RULES = <<<'TXT'
        Rules:
        - Return JSON only: no comments, no code fences.
        - Keep exactly the same keys, nesting and array lengths unless told otherwise.
        - Preserve HTML tags in HTML values; write plain text elsewhere (no Markdown).
        - Respect each field's maximum length (given as "max" when known). Short labels stay short.
        - Write naturally for native readers; concrete, clear, no clichés or exaggerated claims.
        TXT;

    public static function available(): bool
    {
        return app()->bound('blocks.assist') || ContentTranslator::available();
    }

    public static function ensureAvailable(): void
    {
        if (! self::available()) {
            throw new DomainException('km_ai_not_configured', 'Yapay zekâ sağlayıcısının API anahtarı girilmemiş. Ayarlar › Yapay zekâ\'dan ekleyin.', 422);
        }
    }

    /**
     * Bloğun dile bağlı metin alanları (çevrilecek / yazılacak olanlar); satırlarda metin sütunları.
     *
     * @return array<string,mixed>
     */
    public static function texts(array $block, array $values): array
    {
        $out = [];
        foreach ((array) ($block['fields'] ?? []) as $field) {
            if (empty($field['localized'])) {
                continue;
            }
            $name = (string) $field['name'];
            if (in_array($field['type'], self::TEXT, true)) {
                $out[$name] = is_string($values[$name] ?? null) ? $values[$name] : '';
            } elseif ($field['type'] === 'rows') {
                $columns = array_values(array_filter((array) $field['columns'], static fn ($c) => in_array($c['type'], self::TEXT, true)));
                $out[$name] = array_map(static fn ($row) => array_combine(array_column($columns, 'name'), array_map(static fn ($c) => is_string($row[$c['name']] ?? null) ? $row[$c['name']] : '', $columns)), array_values(array_filter((array) ($values[$name] ?? []), 'is_array')));
            }
        }

        return $out;
    }

    /** @return array<string,mixed> aynı biçimde yeni metinler */
    public function write(string $type, string $locale, array $values, string $instruction): array
    {
        $block = self::block($type);
        $texts = self::texts($block, $values);
        $instruction = trim(mb_substr($instruction, 0, 600));
        if ($instruction === '') {
            throw ValidationException::field('instruction', 'Ne istediğinizi yazın (ör. "daha kısa", "tatil acentesi için").');
        }
        $prompt = sprintf(
            "Block: %s (%s)\nLanguage: %s\nFields (with limits): %s\n\nRequest: %s\n\nCurrent JSON:\n%s",
            $block['label'], $block['description'] ?? '', self::language($locale), Text::json(self::limits($block)), $instruction, Text::json($texts)
        );
        $out = $this->engine('write', $prompt, ['type' => $type, 'locale' => $locale, 'texts' => $texts, 'instruction' => $instruction]);
        if (! self::sameKeys($texts, $out)) {
            throw new DomainException('km_assist_failed', 'Jarvis\'in yanıtı bloğun biçimine uymadı; yeniden deneyin.', 502);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $to  hedef diller (geçersiz ve kaynak dil atlanır)
     * @return array<string,array<string,mixed>> dil → metinler
     */
    public function translate(string $type, string $from, array $to, array $values): array
    {
        $texts = self::texts(self::block($type), $values);
        $out = [];
        foreach (array_values(array_unique(array_filter($to, static fn ($c) => is_string($c) && $c !== $from && Locales::valid($c)))) as $code) {
            $out[$code] = (new ContentTranslator)->translate($texts, $from, $code);
        }

        return $out;
    }

    /**
     * Sayfaya önerilen bölümler: kitaplıktaki bloklardan, verilen dilde metinleriyle.
     *
     * @param  list<string>  $library  eklenebilecek bloklar
     * @return list<array{type:string,values:array<string,mixed>}>
     */
    public function suggest(string $page, string $locale, string $brief, array $library): array
    {
        $catalog = [];
        foreach ($library as $type) {
            $block = BlockLibrary::block($type);
            if ($block !== null) {
                $catalog[] = ['type' => $type, 'label' => $block['label'], 'description' => $block['description'] ?? '', 'texts' => self::texts($block, (array) ($block['defaults']['i18n'][$locale] ?? []))];
            }
        }
        $prompt = sprintf(
            "Page: %s\nLanguage: %s\nWhat the page should achieve: %s\n\nAvailable blocks (each with an example of its text JSON):\n%s\n\n"
            .'Choose 3 to 7 blocks in a good order and write their texts. Return {"blocks": [{"type": "…", "texts": { same shape as the example; rows may have 2–8 items }}]}.',
            $page, self::language($locale), trim(mb_substr($brief, 0, 800)) ?: 'a clear, trustworthy page for the site', Text::json($catalog)
        );
        $out = $this->engine('suggest', $prompt, ['page' => $page, 'locale' => $locale, 'brief' => $brief, 'library' => $library]);
        $blocks = [];
        foreach (array_slice((array) ($out['blocks'] ?? []), 0, 10) as $item) {
            $type = (string) ($item['type'] ?? '');
            if (in_array($type, $library, true) && is_array($item['texts'] ?? null)) {
                $blocks[] = ['type' => $type, 'values' => $item['texts']];
            }
        }
        if (! $blocks) {
            throw new DomainException('km_assist_failed', 'Jarvis uygun bir öneri döndürmedi; isteği değiştirip yeniden deneyin.', 502);
        }

        return $blocks;
    }

    private static function block(string $type): array
    {
        return BlockLibrary::block($type) ?? throw ValidationException::field('type', 'Bilinmeyen blok.');
    }

    /** @return array<string,int> alan → en çok uzunluk */
    private static function limits(array $block): array
    {
        $out = [];
        foreach ((array) ($block['fields'] ?? []) as $field) {
            if (! empty($field['localized']) && isset($field['max']) && in_array($field['type'], self::TEXT, true)) {
                $out[(string) $field['name']] = (int) $field['max'];
            }
        }

        return $out;
    }

    private static function language(string $code): string
    {
        $language = Languages::find($code);

        return $language ? sprintf('%s (%s)', $language['native'], $language['name']) : $code;
    }

    /** Yeni metinler aynı alanlarda mı (satır sayısı değişebilir; sütunlar aynı). */
    private static function sameKeys(array $source, mixed $result): bool
    {
        if (! is_array($result)) {
            return false;
        }
        foreach ($source as $key => $value) {
            if (! array_key_exists($key, $result)) {
                return false;
            }
            if (is_array($value)) {
                if (! is_array($result[$key])) {
                    return false;
                }
                $columns = $value ? array_keys($value[0]) : [];
                foreach ($result[$key] as $row) {
                    if (! is_array($row) || array_diff($columns, array_keys($row))) {
                        return false;
                    }
                }
            } elseif (! is_string($result[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function engine(string $task, string $prompt, array $request): array
    {
        if (app()->bound('blocks.assist')) {
            return (array) app('blocks.assist')($task, $request);
        }
        $about = trim(SiteManifest::string('description'));
        $system = 'You write website copy for '.SiteManifest::siteName().'.'.($about !== '' ? ' About the site: '.$about : '')."\n".self::RULES;
        $text = Ai::complete($system, $prompt, 'low');
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

        return is_array($data) ? $data : [];
    }
}
