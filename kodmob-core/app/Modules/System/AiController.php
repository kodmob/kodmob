<?php

namespace App\Modules\System;

use App\Core\Ai\Ai;
use App\Core\Ai\AiSettings;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use Illuminate\Http\Request;

/**
 * Ayarlar > Yapay zekâ (App\Core\Ai). Yetki: km_manage_ai. Jarvis kapalı olsa da çalışır (içerik
 * çevirisi de bu ayarları kullanır).
 *
 *   GET  /system/ai                   sağlayıcılar, etkin olan, modeller (anahtarlar gösterilmez)
 *   PUT  /system/ai                   { provider?, providers: { openai: { api_key?, clear_key?, model? } }, effort?, web_search?, max_steps? }
 *   POST /system/ai/test              { provider? } kayıtlı ayarlarla kısa deneme isteği
 *   POST /system/ai/models            { provider } anahtarın erişebildiği modeller (sağlayıcıdan)
 */
final class AiController extends Controller
{
    public function show(): array
    {
        return AiSettings::view();
    }

    public function save(Request $request): array
    {
        return AiSettings::save($this->input($request));
    }

    public function test(Request $request): array
    {
        $key = $this->providerKey($request);
        $settings = AiSettings::get()['providers'][$key];
        if ($settings['api_key'] === '') {
            throw ValidationException::field("providers.{$key}.api_key", 'Önce bu sağlayıcının API anahtarını kaydedin.');
        }
        $started = microtime(true);
        $response = Ai::provider($key)->send(
            [['role' => 'user', 'content' => 'Tek kelimeyle yanıt ver: hazır mısın?']],
            [['type' => 'text', 'text' => 'Kısa yanıt ver.']],
            [],
            ['api_key' => $settings['api_key'], 'model' => $settings['model'], 'effort' => 'low', 'max_tokens' => 512, 'web_search' => false],
        );
        $text = '';
        foreach ($response['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return ['ok' => true, 'provider' => $key, 'model' => $response['model'], 'reply' => trim($text), 'ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    public function models(Request $request): array
    {
        $key = $this->providerKey($request);
        $apiKey = AiSettings::get()['providers'][$key]['api_key'];
        if ($apiKey === '') {
            throw ValidationException::field("providers.{$key}.api_key", 'Önce bu sağlayıcının API anahtarını kaydedin.');
        }

        return ['provider' => $key, 'models' => Ai::provider($key)->listModels($apiKey)];
    }

    private function providerKey(Request $request): string
    {
        $key = (string) ($this->input($request)['provider'] ?? AiSettings::get()['provider']);
        if (! isset(Ai::PROVIDERS[$key])) {
            throw ValidationException::field('provider', 'Bilinmeyen yapay zekâ sağlayıcısı.');
        }

        return $key;
    }
}
