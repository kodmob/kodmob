<?php

namespace App\Core\Ai;

use App\Core\Ai\Providers\AnthropicProvider;
use App\Core\Ai\Providers\GeminiProvider;
use App\Core\Ai\Providers\OpenAiProvider;
use App\Core\Exceptions\DomainException;

/**
 * Yapay zekâ için tek giriş noktası (Jarvis sohbeti, içerik çevirisi, ileride başka modüller).
 * Etkin sağlayıcı ve modeli Ayarlar > Yapay zekâ'dan gelir (AiSettings).
 *
 *   Ai::send($messages, $system, $tools)          // sohbet turu (araç çağrısıyla)
 *   Ai::complete($system, $prompt)                // tek seferlik metin
 *   Ai::available()                               // etkin sağlayıcının anahtarı var mı
 *
 * Yeni sağlayıcı: AiProvider'ı uygulayan sınıf + PROVIDERS'a bir satır.
 */
final class Ai
{
    /** @var array<string,class-string<AiProvider>> panelde bu sırayla */
    public const PROVIDERS = [
        'anthropic' => AnthropicProvider::class,
        'openai' => OpenAiProvider::class,
        'google' => GeminiProvider::class,
    ];

    /** @var array<string,AiProvider>|null */
    private static ?array $instances = null;

    /** @return array<string,AiProvider> */
    public static function all(): array
    {
        return self::$instances ??= array_map(static fn (string $class) => app($class), self::PROVIDERS);
    }

    public static function provider(?string $key = null): AiProvider
    {
        $key ??= AiSettings::get()['provider'];

        return self::all()[$key] ?? throw new DomainException('km_ai_unknown_provider', 'Bilinmeyen yapay zekâ sağlayıcısı: '.$key, 422);
    }

    public static function available(): bool
    {
        return AiSettings::active()['api_key'] !== '';
    }

    /**
     * Etkin sağlayıcıyla bir tur. `$extra`: effort, max_tokens, web_search (verilmezse ayarlardan).
     *
     * @return array{content:list<array>,stop_reason:string,model:string,usage:array,native:?array,provider:string}
     */
    public static function send(array $messages, array $system, array $tools = [], array $extra = []): array
    {
        $settings = AiSettings::get();
        $active = AiSettings::active();
        $provider = self::provider($active['provider']);
        if ($active['api_key'] === '') {
            throw new DomainException('km_ai_not_configured', $provider->label().' için API anahtarı girilmemiş. Ayarlar > Yapay zekâ\'dan ekleyin.', 422);
        }
        $options = $extra + [
            'api_key' => $active['api_key'],
            'model' => $active['model'],
            'effort' => $settings['effort'],
            'web_search' => (bool) $settings['web_search'] && $provider->supports('web_search'),
            'max_tokens' => 16000,
        ];

        return $provider->send($messages, $system, $tools, $options) + ['provider' => $provider->key()];
    }

    /** Tek seferlik metin: sistem talimatı + istem → yanıt metni. */
    public static function complete(string $system, string $prompt, string $effort = 'low', int $maxTokens = 16000): string
    {
        $reply = self::send([['role' => 'user', 'content' => $prompt]], [['type' => 'text', 'text' => $system]], [], ['effort' => $effort, 'max_tokens' => $maxTokens, 'web_search' => false]);
        $text = '';
        foreach ($reply['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text;
    }

    /** Testler için (kapsayıcıya sahte sağlayıcı konunca). */
    public static function forget(): void
    {
        self::$instances = null;
    }
}
