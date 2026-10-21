<?php

namespace App\Modules\Seo\Services;

use App\Core\Support\Clock;
use App\Core\Support\Options;
use Illuminate\Support\Facades\DB;

/**
 * Yedekteki `seo` bölümü (Content\Backup): sabit sayfa düzenlemeleri, robots.txt ek
 * kuralları ve yönlendirmeler. Kayıt SEO'su kaydın kendi yedeğindedir (`seo`).
 * SEO ayarları (modüller, doğrulama kodları…) genel ayarlarla birlikte gelir.
 */
final class SeoBackup
{
    /** @return array{pages:array,robots:string,redirects:list<array>,media:list<int>} */
    public static function export(): array
    {
        $pages = PageMeta::all();
        $media = [];
        foreach ($pages as $locales) {
            foreach ((array) $locales as $row) {
                if (! empty($row['image_id'])) {
                    $media[] = (int) $row['image_id'];
                }
            }
        }

        return [
            'pages' => (object) $pages,
            'robots' => (string) Options::get(SeoConfig::ROBOTS_OPTION, ''),
            'redirects' => DB::table('seo_redirects')->orderBy('position')->orderBy('id')->get(['source', 'target', 'code', 'is_regex', 'active', 'note'])
                ->map(static fn ($r) => (array) $r)->all(),
            'media' => $media,
        ];
    }

    /** Yedekten: var olanların yerine geçer. @return int yönlendirme sayısı */
    public static function import(array $seo, array $mediaMap): int
    {
        $pages = [];
        foreach ((array) ($seo['pages'] ?? []) as $route => $locales) {
            foreach ((array) $locales as $locale => $row) {
                $row = (array) $row;
                $row['image_id'] = $mediaMap[(int) ($row['image_id'] ?? 0)] ?? 0;
                $pages[(string) $route][(string) $locale] = $row;
            }
        }
        Options::set(PageMeta::OPTION, $pages);
        Options::set(SeoConfig::ROBOTS_OPTION, (string) ($seo['robots'] ?? ''));

        DB::table('seo_redirects')->delete();
        $n = 0;
        foreach ((array) ($seo['redirects'] ?? []) as $i => $r) {
            DB::table('seo_redirects')->insert([
                'source' => (string) ($r['source'] ?? ''),
                'target' => (string) ($r['target'] ?? ''),
                'code' => (int) ($r['code'] ?? 301),
                'is_regex' => (bool) ($r['is_regex'] ?? false),
                'active' => (bool) ($r['active'] ?? true),
                'note' => (string) ($r['note'] ?? ''),
                'position' => $i + 1,
                'created_at' => Clock::now(),
            ]);
            $n++;
        }

        return $n;
    }
}
