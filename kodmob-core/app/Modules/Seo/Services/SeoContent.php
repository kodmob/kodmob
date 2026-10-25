<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\Locales;
use App\Core\Content\MediaPayload;
use App\Core\Content\Types;
use App\Core\Support\Clock;
use App\Core\Support\PanelUrl;
use App\Core\Support\Paths;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Seo\Seo;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;

/**
 * Kaydın arama motoruna giden başlık ve açıklaması (varsayılan + SEO düzenlemesi) ve
 * SEO > İçerik listesi (yayındaki her yazı ve kendi sayfası olan kayıt, dil başına analiz puanıyla).
 */
final class SeoContent
{
    /**
     * Varsayılanlar: yazı başlık ve özet; kayıt türün kancasından (ContentType::seoDefaults; proje
     * "Başlık · Kısa tanım" ve özet).
     *
     * @return array{title:string,description:string}
     */
    public static function defaults(string $type, array $entry): array
    {
        if ($type !== 'post' && ($page = Types::page($type))) {
            return $page->seoDefaults($entry);
        }

        return ['title' => (string) ($entry['title'] ?? ''), 'description' => (string) ($entry['excerpt'] ?? '')];
    }

    /**
     * Sayfaya yazılacak SEO (SEO modülü ve meta alt modülü açıksa düzenlemeler uygulanır).
     *
     * @return array{title:string,description:string,noindex:bool,canonical:string,image:string,focus:string}
     */
    public static function resolve(string $type, array $entry, string $locale): array
    {
        $base = self::defaults($type, $entry);
        $out = $base + ['noindex' => false, 'canonical' => '', 'image' => '', 'focus' => ''];
        if (! Seo::on('meta') || empty($entry['id'])) {
            return $out;
        }
        $meta = SeoMeta::get($type, (int) $entry['id']);
        $l = $meta['locales'][$locale] ?? [];
        $out['title'] = ($l['title'] ?? '') !== '' ? $l['title'] : $base['title'];
        $out['description'] = ($l['description'] ?? '') !== '' ? $l['description'] : $base['description'];
        $out['focus'] = (string) ($l['focus'] ?? '');
        $out['noindex'] = $meta['noindex'];
        $out['canonical'] = $meta['canonical'];
        if ($meta['image_id'] && Seo::on('social')) {
            $out['image'] = (string) (MediaPayload::image($meta['image_id'], 'large', $locale)['src'] ?? '');
        }

        return $out;
    }

    public static function titleSuffix(string $locale): string
    {
        $router = Router::load(Paths::siteRoot());

        return (string) ($router?->manifest()['strings'][$locale]['titleSuffix'] ?? '');
    }

    /**
     * SEO > İçerik: yayındaki kayıtlar (dil başına bir satır) ve analiz puanları.
     *
     * @return list<array>
     */
    public static function audit(): array
    {
        $rows = [];
        $router = Router::load(Paths::siteRoot());
        $titles = [];

        $routes = SiteManifest::pageRoutes();
        foreach (Locales::active() as $locale) {
            $suffix = self::titleSuffix($locale);
            // Kendi sayfası olan türlerin kayıtları (ör. projeler); analiz metni türün kancasından.
            foreach (Types::paged() as $kind => $type) {
                foreach ($type->publicList($locale) as $entry) {
                    $seo = self::resolve($kind, $entry, $locale);
                    $rows[] = self::row($kind, $entry, $locale, $seo, SeoAnalyzer::analyze([
                        'kind' => $kind, 'title' => $seo['title'], 'suffix' => $suffix, 'description' => $seo['description'], 'focus' => $seo['focus'],
                        'slug' => (string) $entry['slug'], 'text' => $type->seoText($entry), 'cover' => ! empty($entry['cover']), 'noindex' => $seo['noindex'],
                    ]), $router?->url($routes[$kind], $locale, (string) $entry['slug']), PanelUrl::to('editor', ['type' => $type->name(), 'id' => $entry['id']]));
                }
            }
            $posts = DB::table('posts')->whereNull('deleted_at')->where('status', 'publish')->where('published_at', '<=', Clock::now())->where('locale', $locale)->orderByDesc('published_at')->get();
            foreach ($posts as $post) {
                $entry = ['id' => (int) $post->id, 'modified' => Clock::atom($post->updated_at), 'title' => (string) $post->title, 'excerpt' => (string) ($post->excerpt !== '' ? $post->excerpt : Text::words(Text::stripTags((string) $post->html), 30, '…')), 'slug' => (string) $post->slug];
                $seo = self::resolve('post', $entry, $locale);
                $rows[] = self::row('post', $entry, $locale, $seo, SeoAnalyzer::analyze([
                    'kind' => 'post', 'title' => $seo['title'], 'suffix' => $suffix, 'description' => $seo['description'], 'focus' => $seo['focus'],
                    'slug' => (string) $post->slug, 'html' => (string) $post->html, 'cover' => (int) $post->cover_id > 0, 'noindex' => $seo['noindex'],
                ]), $router?->url('post', $locale, (string) $post->slug), PanelUrl::to('editor', ['type' => 'posts', 'id' => $post->id]));
            }
        }

        // Aynı dilde aynı başlık: arama motoru sayfaları ayırt edemez.
        foreach ($rows as $row) {
            $titles[$row['locale'].'|'.mb_strtolower($row['seo_title'])][] = $row['key'];
        }
        foreach ($rows as &$row) {
            $row['duplicate'] = count($titles[$row['locale'].'|'.mb_strtolower($row['seo_title'])] ?? []) > 1;
            if ($row['duplicate']) {
                $row['issues']++;
            }
        }
        unset($row);

        return $rows;
    }

    private static function row(string $type, array $entry, string $locale, array $seo, array $analysis, ?string $url, string $edit): array
    {
        $issues = array_values(array_filter($analysis['checks'], static fn ($c) => $c['status'] === 'bad'));

        return [
            'key' => $type.'-'.$entry['id'].'-'.$locale,
            'type' => $type,
            'type_label' => $type === 'post' ? 'Blog' : (Types::page($type)?->labels()[1] ?? $type),
            'id' => (int) $entry['id'],
            'locale' => $locale,
            'title' => (string) $entry['title'],
            'seo_title' => $seo['title'],
            'title_length' => mb_strlen($seo['title'].self::titleSuffix($locale)),
            'description_length' => mb_strlen($seo['description']),
            'focus' => $seo['focus'],
            'noindex' => $seo['noindex'],
            'score' => $analysis['score'],
            'grade' => $analysis['score'] >= 80 ? 'good' : ($analysis['score'] >= 50 ? 'ok' : 'bad'),
            'issues' => count($issues),
            'issue_text' => implode(' · ', array_map(static fn ($c) => $c['message'], $issues)),
            'url' => $url,
            'edit' => $edit,
            // Yapay zekâ hazırlığı (AiVisibility::content) için.
            'words' => (int) ($analysis['words'] ?? 0),
            'checks' => array_column($analysis['checks'], 'status', 'key'),
            'modified' => $entry['modified'] ?? null,
        ];
    }
}
