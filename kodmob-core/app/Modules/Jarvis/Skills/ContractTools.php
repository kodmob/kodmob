<?php

namespace App\Modules\Jarvis\Skills;

use App\Core\Content\Locales;
use App\Core\Content\Types;

/**
 * Sözleşmedeki panel uçlarından Jarvis araçları (docs/done-sozlesme-raporu.md §4.5, yenilik raporu "Jarvis Operatör").
 * Okuma araçları hemen çalışır; yazma araçları kullanıcı onayıyla (JarvisActions). Her araç kullanıcının yetkisiyle
 * sınırlıdır (rotanın `cap:`'ı) ve yaptığı İşlem Geçmişi'ne kullanıcının adıyla düşer.
 *
 * Kişisel veri yapay zekâya gitmez: talepler, siparişler, aboneler ve kullanıcılar araç değildir; 404 kaydında
 * yönlendiren adresin yalnızca alan adı gider. Yeni araç: bu listeye bir RouteSkill (uç sözleşmede olmalı).
 */
final class ContractTools
{
    /** @return list<RouteSkill> */
    public static function all(): array
    {
        $only = static fn (array $keys) => static fn (array $row) => array_intersect_key($row, array_flip($keys));
        $items = static fn (array $body, array $keys) => array_map($only($keys), (array) ($body['items'] ?? $body['data'] ?? []));

        return [
            new RouteSkill('seo_overview', 'SEO durumu', 'Sitenin SEO özeti: puan, eksik başlık ve açıklamalar, 404 ve yönlendirme sayıları, öneriler.', 'GET', '/v1/seo'),
            new RouteSkill(
                'seo_not_found', '404 kayıtları',
                'Ziyaretçilerin ve botların rastladığı bulunamayan adresler: yol, kaç kez, iç bağlantıdan mı, önerilen hedef. Kırık adresleri onarmak için redirect_create ile birlikte kullan.',
                'GET', '/v1/seo/404',
                ['status' => ['type' => 'string', 'enum' => ['new', 'ignored', 'fixed'], 'description' => 'Durum süzgeci (varsayılan hepsi)'], 'per_page' => ['type' => 'integer', 'maximum' => 100]],
                pick: static fn (array $b) => ['total' => $b['total'] ?? null, 'items' => array_map(static fn ($r) => [
                    'path' => $r['path'] ?? '', 'hits' => $r['hits'] ?? 0, 'internal' => $r['internal'] ?? false, 'bot' => $r['bot'] ?? false,
                    'suggestion' => $r['suggestion'] ?? '', 'status' => $r['status'] ?? '', 'referrer_host' => (string) parse_url((string) ($r['referrer'] ?? ''), PHP_URL_HOST),
                ], (array) ($b['items'] ?? []))],
            ),
            new RouteSkill('seo_broken_links', 'Kırık bağlantılar', 'Sitedeki iç bağlantı denetiminin son sonucu: hangi sayfada hangi bağlantı kırık.', 'GET', '/v1/seo/links'),
            new RouteSkill(
                'content_list', 'İçerik listesi',
                'Bir içerik türünün kayıtları (başlık, durum, adres, güncelleme). Tür: '.implode(', ', array_keys(Types::editable())).'.',
                'GET', '/v1/admin/content/{type}',
                ['type' => ['type' => 'string', 'enum' => array_keys(Types::editable())], 'q' => ['type' => 'string', 'description' => 'Başlıkta arama'], 'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'future']]],
                ['type'],
                pick: static fn (array $b) => ['total' => $b['total'] ?? null, 'items' => $items($b, ['id', 'title', 'status', 'slug', 'locale', 'locales', 'updated_at', 'date'])],
            ),
            new RouteSkill(
                'system_health', 'Sistem sağlığı', 'Sunucu, güvenlik, hız, veritabanı, depolama, arka plan işleri ve yedek özeti; uyarılar.',
                'GET', '/v1/system/health',
                pick: static fn (array $b) => ['level' => $b['level'] ?? null, 'sections' => array_map(static fn ($s) => [
                    'title' => $s['title'] ?? '', 'level' => $s['level'] ?? '', 'flags' => array_map(static fn ($f) => trim(($f['text'] ?? '').' '.($f['fix'] ?? '')), (array) ($s['flags'] ?? [])),
                ], (array) ($b['sections'] ?? []))],
            ),
            new RouteSkill('languages_health', 'Çeviri sağlığı', 'Eksik ve eskimiş çeviriler, dil adresleri ve dil sorunları.', 'GET', '/v1/languages/health'),
            new RouteSkill(
                'legal_status', 'Yasal belgeler', 'Projede açık yasal belgeler, dil başına özelleştirilmiş mi, Proje Ayarları › Yasal\'da eksik bilgiler.',
                'GET', '/v1/admin/legal',
                pick: static fn (array $b) => ['missing' => $b['missing'] ?? [], 'items' => array_map(static fn ($d) => [
                    'name' => $d['name'] ?? '', 'title' => $d['title'] ?? '', 'customized' => array_keys(array_filter((array) ($d['locales'] ?? []), static fn ($l) => ! empty($l['customized']))),
                ], (array) ($b['items'] ?? []))],
            ),

            // Operatör: yazma araçları (onaylı).
            new RouteSkill(
                'redirect_create', 'Yönlendirme ekle',
                'Eski ya da kırık bir adresi yeni adrese yönlendirir (301 kalıcı, 302 geçici, 410 kaldırıldı). source ve target kök-göreli adres (/eski-sayfa/).',
                'POST', '/v1/seo/redirects',
                ['source' => ['type' => 'string'], 'target' => ['type' => 'string'], 'code' => ['type' => 'integer', 'enum' => [301, 302, 410]], 'note' => ['type' => 'string']],
                ['source', 'target'],
                write: true,
                pick: $only(['id', 'source', 'target', 'code', 'active']),
                describe: static fn (array $in) => sprintf('Yönlendirme ekle: %s → %s (%d)', $in['source'] ?? '?', ($in['target'] ?? '') ?: '—', (int) ($in['code'] ?? 301)),
            ),
            new RouteSkill(
                'post_translate', 'Yazıyı çevir',
                'Bir blog yazısını yapay zekâyla başka bir dile çevirip taslak olarak ekler (yayına almaz). id yazının kimliği (content_list type=posts), locale hedef dil: '.implode(', ', Locales::all()).'.',
                'POST', '/v1/admin/content/posts/{id}/translations',
                ['id' => ['type' => 'integer'], 'locale' => ['type' => 'string', 'enum' => Locales::all()], 'mode' => ['type' => 'string', 'enum' => ['ai'], 'default' => 'ai']],
                ['id', 'locale'],
                write: true,
                pick: $only(['id', 'title', 'status', 'locale']),
                describe: static fn (array $in) => sprintf('Yazı #%s için %s çevirisini taslak olarak oluştur', $in['id'] ?? '?', strtoupper((string) ($in['locale'] ?? '?'))),
            ),
        ];
    }
}
