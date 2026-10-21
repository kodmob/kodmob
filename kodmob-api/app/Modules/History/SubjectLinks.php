<?php

namespace App\Modules\History;

use App\Core\Content\Types;
use App\Core\Support\PanelUrl;

/**
 * Olay kaydının türü → okunur adı ve paneldeki adresi (İşlem Geçmişi'nde
 * "Kayıt" sütunu ve detaydaki bağlantı). Adresler panel rota tablosundan gelir
 * (PanelUrl); `id: true` olan rotaya kaydın kimliği eklenir.
 */
final class SubjectLinks
{
    private const TYPES = [
        'redirect' => ['label' => 'Yönlendirme', 'route' => ['seo', ['tab' => 'redirects']]],
        'seo_page' => ['label' => 'Sayfa SEO\'su', 'route' => ['seo', ['tab' => 'pages']]],
        'seo' => ['label' => 'SEO', 'route' => ['seo', []]],
        'brief' => ['label' => 'Talep', 'route' => ['briefs', []], 'id' => 'id'],
        'user' => ['label' => 'Kullanıcı', 'route' => ['users', []], 'id' => 'id'],
        'role' => ['label' => 'Rol', 'route' => ['roles', []]],
        'jarvis_job' => ['label' => 'Jarvis işi', 'route' => ['domains', []], 'id' => 'job'],
        'conversation' => ['label' => 'Sohbet', 'route' => ['jarvis', []], 'id' => 'id'],
        'media' => ['label' => 'Görsel', 'route' => null],
        'settings' => ['label' => 'Ayarlar', 'route' => ['projectSettings', []]],
        'texts' => ['label' => 'Sayfa metinleri', 'route' => ['texts', []]],
        'language' => ['label' => 'Dil', 'route' => ['languages', ['tab' => 'list']]],
        'estimate' => ['label' => 'Teklif hesaplayıcı', 'route' => ['estimate', []]],
        'tool' => ['label' => 'Araç', 'route' => ['tools', []]],
        'backup' => ['label' => 'Yedek', 'route' => ['settings', ['tab' => 'backup']]],
        'session' => ['label' => 'Oturum', 'route' => ['security', ['tab' => 'sessions']]],
        'ip_block' => ['label' => 'IP engeli', 'route' => ['security', ['tab' => 'blocks']]],
        'security' => ['label' => 'Güvenlik', 'route' => ['security', []]],
        'profile' => ['label' => 'Profil', 'route' => ['profile', []]],
    ];

    /** İçerik türü (kayıttan, Types): düzenleyiciye bağlanır. */
    private static function content(string $kind): ?array
    {
        $content = Types::byKind($kind);

        return $content ? ['label' => $content->labels()[1], 'route' => ['editor', ['type' => $content->name()]], 'id' => 'id'] : null;
    }

    /** @return array{type:string,id:int,label:string,type_label:string,url:?string} */
    public static function of(string $type, int $id, string $label): array
    {
        $def = self::TYPES[$type] ?? self::content($type);
        $url = null;
        if (! empty($def['route'])) {
            [$name, $params] = $def['route'];
            // Kayda bağlı adres kimliksiz üretilmez (silinmiş ya da genel kayıt).
            if (! isset($def['id']) || $id > 0) {
                $url = PanelUrl::to($name, isset($def['id']) ? $params + [$def['id'] => $id] : $params);
            }
        }

        return [
            'type' => $type,
            'id' => $id,
            'label' => $label !== '' ? $label : ($id > 0 ? '#'.$id : ''),
            'type_label' => $def['label'] ?? $type,
            'url' => $url,
        ];
    }
}
