<?php

namespace App\Modules\Domains\Skills;

use App\Modules\Domains\DomainService;
use App\Modules\Jarvis\Skills\Skill;

/** Anlık domain kontrolü: birkaç ad, beklemeden sonuç. */
final class DomainCheckSkill implements Skill
{
    public function name(): string
    {
        return 'domain_check';
    }

    public function title(): string
    {
        return 'Domain kontrolü';
    }

    public function description(): string
    {
        return 'Belirli alan adlarının boşta olup olmadığını hemen kontrol eder (en çok 100 ad). '
            .'Uzantısız yazılan ad (ör. "kodmob") `tlds` listesindeki her uzantıyla denenir; uzantılı ad (ör. "kodmob.si") yalnızca kendisiyle. '
            .'Sonuç durumları: available (boş), taken (kayıtlı), expiring (silinme sürecinde / süresi geçmiş), unknown (doğrulanamadı), invalid (kurala aykırı). '
            .'Kontrol DNS ve kayıt kuruluşunun resmî RDAP servisiyle yapılır; tahmin yürütme, her zaman bu aracın sonucunu bildir. '
            .'Yüzlerce/binlerce aday için bunun yerine domain_scan kullan.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Denenecek adlar; uzantılı ya da uzantısız.'],
                'tlds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Uzantısız adlar için uzantılar, noktasız (ör. ["si", "com"]). Varsayılan ["si"].'],
            ],
            'required' => ['names'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $input): array
    {
        return (new DomainService)->check((array) ($input['names'] ?? []), (array) ($input['tlds'] ?? ['si']));
    }
}
