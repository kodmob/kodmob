<?php

namespace App\Core\Ai;

/**
 * Yapay zekâ sağlayıcısı (Claude, OpenAI, Gemini…). Yenisi: bu arayüzü uygulayan sınıf + Ai::PROVIDERS'a
 * bir satır; panel (Ayarlar > Yapay zekâ) listeyi sunucudan alır.
 *
 * Ortak biçim (kanonik): mesajlar Claude'un blok yapısındadır — `text`, `tool_use` {id, name, input},
 * kullanıcı mesajında `tool_result` {tool_use_id, content, is_error}. Sağlayıcı bunları kendi biçimine
 * çevirir, yanıtını da bu biçime döndürür. Ayrıca yanıtın kendi biçimini `native` olarak verir: aynı
 * sağlayıcıyla devam ederken geçmiş aynen geri gönderilir (OpenAI düşünme öğeleri, Gemini imzaları).
 */
interface AiProvider
{
    /** anthropic | openai | google */
    public function key(): string;

    public function label(): string;

    /** @return array<string,string> önerilen modeller: id => panelde görünen ad */
    public function models(): array;

    public function defaultModel(): string;

    /** Panelde anahtar alanının ipucu (ör. "sk-ant-…"). */
    public function keyPlaceholder(): string;

    /** `web_search` (sunucu tarafı web araması), `effort` (düşünme düzeyi). */
    public function supports(string $feature): bool;

    /**
     * @param  list<array{role:string,content:string|array,native?:?array}>  $messages  ortak biçimde geçmiş
     * @param  list<array{type:string,text:string}>  $system  metin blokları (Claude önbellek işaretini taşıyabilir)
     * @param  list<array{name:string,description:string,inputSchema:array}>  $tools
     * @param  array{api_key:string,model:string,effort?:string,max_tokens?:int,web_search?:bool}  $options
     * @return array{content:list<array>,stop_reason:string,model:string,usage:array{input_tokens:int,output_tokens:int},native:?array}
     */
    public function send(array $messages, array $system, array $tools, array $options): array;

    /** @return list<array{id:string,label:string}> anahtarın erişebildiği modeller (sağlayıcıdan) */
    public function listModels(string $apiKey): array;
}
