<?php

namespace App\Modules\Domains\Skills;

use App\Core\Support\PanelUrl;
use App\Modules\Domains\DomainService;
use App\Modules\Jarvis\Skills\Skill;

/** Büyük domain taraması: arka planda iş olarak çalışır. */
final class DomainScanSkill implements Skill
{
    public function name(): string
    {
        return 'domain_scan';
    }

    public function title(): string
    {
        return 'Domain taraması';
    }

    public function description(): string
    {
        return 'Çok sayıda aday adı arka planda tarayan bir iş başlatır ve iş numarasını döner; sonuç dakikalar sürebilir. '
            .'Türler (mode): "short" = verilen uzunluklardaki bütün kombinasyonlar (2–3 karakter tam tarama gibi; lengths, charset: letters|alnum|digits|consonants, isteğe bağlı prefix/suffix); '
            .'"pattern" = telaffuz edilebilir 4–5 karakterli adlar için kalıp (C ünsüz, V ünlü, L harf, D rakam, A harf/rakam, küçük harfler sabit; ör. CVCV, CVCVC, CVCio), büyük uzaylarda limit ile örneklem; '
            .'"words" = jenerik kelimeler (wordsets: en-short, en-tech, en-business, tr-genel, tr-is, hack-si; ya da words ile kendi kelimeleri; affixes: true yaygın ön/son ekleri ekler; max_length); '
            .'"list" = verilen adların hepsi (names; hack: true ise uzantıyla biten kelimeler domain hilesine çevrilir, ör. kahvesi → kahve.si). '
            .'Bir işte en çok 1.000.000 aday olabilir. Önce kullanıcıya kaç aday çıkacağını söyle; çok büyükse daraltmayı öner. '
            .'İlerlemeyi jarvis_job ile, sonuçları domain_results ile al.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['short', 'pattern', 'words', 'list']],
                'tlds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Noktasız uzantılar, ör. ["si"]. En çok 10.'],
                'lengths' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'short: uzunluklar, ör. [2, 3].'],
                'charset' => ['type' => 'string', 'enum' => ['letters', 'alnum', 'digits', 'consonants']],
                'prefix' => ['type' => 'string'],
                'suffix' => ['type' => 'string'],
                'patterns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'pattern: ör. ["CVCV", "CVCVC"].'],
                'limit' => ['type' => 'integer', 'description' => 'pattern: örneklem sınırı (0 = hepsi).'],
                'wordsets' => ['type' => 'array', 'items' => ['type' => 'string']],
                'words' => ['type' => 'array', 'items' => ['type' => 'string']],
                'affixes' => ['type' => 'boolean'],
                'max_length' => ['type' => 'integer'],
                'names' => ['type' => 'array', 'items' => ['type' => 'string']],
                'hack' => ['type' => 'boolean'],
                'title' => ['type' => 'string', 'description' => 'İsteğe bağlı iş başlığı.'],
            ],
            'required' => ['mode', 'tlds'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $input): array
    {
        $job = (new DomainService)->scan($input);

        return [
            'job' => $job->toArray(),
            'panel_url' => PanelUrl::to('domains', ['job' => $job->id()]),
        ];
    }
}
