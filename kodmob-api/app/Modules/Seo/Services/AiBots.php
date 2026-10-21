<?php

namespace App\Modules\Seo\Services;

/**
 * Yapay zekâ tarayıcıları kataloğu (SEO > Yapay zekâ; robots.txt engeli; render.php ziyaret kaydı).
 *
 * Anahtar, tarayıcının User-Agent'ında geçen addır (büyük/küçük harf duyarsız). Amaç:
 *   training  modeli eğitmek için toplar          search  yapay zekâ aramasının dizini
 *   user      kullanıcı sorunca o an okur          engine  klasik arama motoru (yapay zekâ özetleri de buradan beslenir)
 *
 * Google'ın Gemini'si ve yapay zekâ özetleri ayrı bir tarayıcıyla gelmez, Googlebot'la gelir; Apple Intelligence
 * da Applebot'la. Bunların engeli yalnızca robots.txt adıyla yapılır (Google-Extended, Applebot-Extended), ziyaret
 * olarak görünmezler. Liste 18 Ekim 2026 itibarıyla; yeni tarayıcı bir satırla eklenir.
 */
final class AiBots
{
    public const PURPOSES = [
        'training' => 'Eğitim',
        'search' => 'Yapay zekâ araması',
        'user' => 'Kullanıcı isteği',
        'engine' => 'Arama motoru',
    ];

    /** User-Agent adı → [şirket, ürün, amaç]. Sıra önemli değil; eşleşmede en uzun ad önce denenir. */
    public const BOTS = [
        'GPTBot' => ['OpenAI', 'ChatGPT (eğitim)', 'training'],
        'OAI-SearchBot' => ['OpenAI', 'ChatGPT arama', 'search'],
        'ChatGPT-User' => ['OpenAI', 'ChatGPT', 'user'],
        'ClaudeBot' => ['Anthropic', 'Claude (eğitim)', 'training'],
        'Claude-SearchBot' => ['Anthropic', 'Claude arama', 'search'],
        'Claude-User' => ['Anthropic', 'Claude', 'user'],
        'Claude-Web' => ['Anthropic', 'Claude (eski)', 'user'],
        'anthropic-ai' => ['Anthropic', 'Anthropic (eski)', 'training'],
        'PerplexityBot' => ['Perplexity', 'Perplexity arama', 'search'],
        'Perplexity-User' => ['Perplexity', 'Perplexity', 'user'],
        'MistralAI-User' => ['Mistral', 'Le Chat', 'user'],
        'meta-externalagent' => ['Meta', 'Meta AI (eğitim)', 'training'],
        'meta-externalfetcher' => ['Meta', 'Meta AI', 'user'],
        'Bytespider' => ['ByteDance', 'Doubao (eğitim)', 'training'],
        'CCBot' => ['Common Crawl', 'Açık eğitim verisi', 'training'],
        'Amazonbot' => ['Amazon', 'Alexa ve Amazon AI', 'search'],
        'DuckAssistBot' => ['DuckDuckGo', 'Duck.ai', 'user'],
        'cohere-ai' => ['Cohere', 'Cohere', 'training'],
        'YouBot' => ['You.com', 'You.com arama', 'search'],
        'Googlebot' => ['Google', 'Google arama, Gemini ve yapay zekâ özetleri', 'engine'],
        'bingbot' => ['Microsoft', 'Bing ve Copilot', 'engine'],
        'Applebot' => ['Apple', 'Siri, Spotlight ve Apple Intelligence', 'engine'],
    ];

    /** Yalnızca robots.txt'de geçen adlar (ayrı tarayıcı değil, kullanım izni). */
    public const ROBOTS_ONLY = ['Google-Extended', 'Applebot-Extended'];

    /** robots.txt'de "yapay zekâ engelle" seçilince yazılan adlar: arama motorları hariç hepsi. */
    public static function robotsTokens(): array
    {
        $tokens = array_keys(array_filter(self::BOTS, static fn ($b) => $b[2] !== 'engine'));

        return [...$tokens, ...self::ROBOTS_ONLY];
    }

    /** render.php'nin kullandığı düzenli ifade (derlenmiş SEO yapılandırmasına yazılır). */
    public static function pattern(): string
    {
        $names = array_keys(self::BOTS);
        usort($names, static fn ($a, $b) => strlen($b) <=> strlen($a));

        return '/('.implode('|', array_map(static fn ($n) => preg_quote($n, '/'), $names)).')/i';
    }

    /** User-Agent'tan katalogdaki ad (yoksa null). */
    public static function match(string $userAgent): ?string
    {
        if ($userAgent === '' || ! preg_match(self::pattern(), $userAgent, $m)) {
            return null;
        }
        foreach (array_keys(self::BOTS) as $name) {
            if (strcasecmp($name, $m[1]) === 0) {
                return $name;
            }
        }

        return null;
    }

    /** @return array{company:string,product:string,purpose:string,purpose_label:string} */
    public static function describe(string $name): array
    {
        [$company, $product, $purpose] = self::BOTS[$name] ?? ['?', $name, 'training'];

        return ['company' => $company, 'product' => $product, 'purpose' => $purpose, 'purpose_label' => self::PURPOSES[$purpose]];
    }
}
