<?php

namespace App\Modules\Jarvis\Skills;

use App\Modules\Content\Http\AdminContentController;

/**
 * Site özeti: içerik sayıları ve talepler. Talep sahiplerinin kişisel verisi
 * (ad, e-posta, telefon, mesaj) yapay zekâ sağlayıcısına gönderilmez; yalnızca
 * tarih, hizmetler, bütçe ve durum.
 */
final class SiteOverviewSkill implements Skill
{
    public function name(): string
    {
        return 'site_overview';
    }

    public function title(): string
    {
        return 'Site özeti';
    }

    public function description(): string
    {
        return (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'Sitenin').' sitesinin durumu: projeler, hizmetler, referanslar ve blog yazılarının sayıları (yayında/taslak), '
            .'son değişen içerikler ve proje taleplerinin özeti (yeni talep sayısı, son 5 talebin tarihi, hizmetleri, bütçesi). '
            .'Kişisel veri içermez; talep sahibinin kimliği sorulursa panelde Talepler ekranına yönlendir.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['include_recent' => ['type' => 'boolean', 'description' => 'Son değişen içerikleri de getir (varsayılan: evet).']],
            'additionalProperties' => false,
        ];
    }

    public function run(array $input): array
    {
        // Sohbetteki istek (kullanıcısıyla); zamanlanmış işte kullanıcı yok: talepler gösterilmez.
        $overview = (new AdminContentController)->overview(request());
        $overview['briefs'] ??= ['new' => 0, 'latest' => []];

        return [
            'counts' => $overview['counts'],
            'recent_content' => ($input['include_recent'] ?? true) === false ? [] : array_map(static fn ($r) => array_intersect_key($r, array_flip(['type', 'title', 'status', 'modified'])), $overview['recent']),
            'briefs' => [
                'new' => $overview['briefs']['new'],
                'latest' => array_map(static fn ($b) => [
                    'id' => $b['id'] ?? null,
                    'date' => $b['created_at'] ?? null,
                    'services' => $b['services'] ?? [],
                    'budget' => $b['budget'] ?? null,
                    'status' => $b['status'] ?? null,
                ], (array) $overview['briefs']['latest']),
            ],
        ];
    }
}
