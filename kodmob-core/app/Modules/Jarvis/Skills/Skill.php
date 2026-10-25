<?php

namespace App\Modules\Jarvis\Skills;

/**
 * Jarvis becerisi: tek bir işi yapan, girdisi JSON şemasıyla tanımlı birim.
 * Aynı beceri hem panelden doğrudan (POST /jarvis/skills/{name}) hem de
 * Claude'un aracı olarak çağrılır; ikisi de aynı `run()`'dan geçer.
 *
 * Yeni beceri: bu arayüzü uygulayan sınıf + JarvisModule'de
 * `SkillRegistry::register(new MySkill())`. Başka hiçbir yer değişmez.
 */
interface Skill
{
    /** Araç adı (Claude'a giden): küçük harf, alt çizgi. Ör. `domain_check`. */
    public function name(): string;

    /** Panelde görünen Türkçe ad. */
    public function title(): string;

    /** Modelin aracı ne zaman kullanacağını anlayacağı açıklama. */
    public function description(): string;

    /** Girdinin JSON şeması (`type: object`). */
    public function inputSchema(): array;

    /**
     * Çalıştırır. Dönüş JSON'a çevrilebilir olmalı. Geçersiz girdi için
     * `ValidationException` fırlatır (araç sonucu `is_error` olarak döner).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array;
}
