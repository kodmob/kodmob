<?php

namespace App\Modules\Site\Blocks;

use App\Core\Content\Locales;
use App\Core\Content\PageCache;
use App\Core\Exceptions\NotFoundException;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use App\Core\Support\UserNames;
use App\Modules\Site\Pages\PageType;
use Illuminate\Support\Facades\DB;

/**
 * Sayfa düzenleyicinin kaydı: bölge başına yayındaki ve taslak blok listesi (`page_layouts`).
 *
 * - Satırı olmayan (hiç yayınlanmamış) bölge derlemenin varsayılan bloklarıyla çizilir (kabuktaki hali).
 * - Düzenleyici her değişikliği taslağa yazar; ziyaretçi yalnızca yayındakini görür. "Yayınla" bütün taslakları
 *   (ya da verilen bölgeleri) yayına alır, eski hali geçmişe (`page_layout_revisions`, bölge başına son 20) yazar
 *   ve sayfa önbelleğini temizler.
 * - Önizleme (düzenleyicinin çerçevesi) taslak varsa onu çizer.
 */
final class Layouts
{
    private const KEEP = 20;

    /** @param list<string> $zones @return array<string,array{published:?array,draft:?array,draftAt:?string,draftBy:?string,publishedAt:?string,publishedBy:?string}> */
    public static function state(array $zones): array
    {
        $rows = $zones ? DB::table('page_layouts')->whereIn('zone', $zones)->get()->keyBy('zone') : collect();
        UserNames::map($rows->pluck('draft_by')->merge($rows->pluck('published_by')));
        $out = [];
        foreach ($zones as $zone) {
            $row = $rows[$zone] ?? null;
            $out[$zone] = [
                'published' => $row ? self::decode($row->published) : null,
                'draft' => $row ? self::decode($row->draft) : null,
                'draftAt' => $row ? Clock::atom($row->draft_at) : null,
                'draftBy' => $row ? UserNames::of($row->draft_by ? (int) $row->draft_by : null) : null,
                'publishedAt' => $row ? Clock::atom($row->published_at) : null,
                'publishedBy' => $row ? UserNames::of($row->published_by ? (int) $row->published_by : null) : null,
            ];
        }

        return $out;
    }

    /**
     * Sayfa üretimi: bölge → çizilecek bloklar; null = düzenlenmemiş (kabuktaki varsayılan kalır). Önizlemede
     * taslak önceliklidir.
     *
     * @param  list<string>  $zones
     * @return array<string,?list<array>>
     */
    public static function forRender(array $zones, bool $preview = false): array
    {
        $out = array_fill_keys($zones, null);
        if (! $zones) {
            return $out;
        }
        foreach (DB::table('page_layouts')->whereIn('zone', $zones)->get(['zone', 'published', 'draft']) as $row) {
            $out[$row->zone] = ($preview ? self::decode($row->draft) : null) ?? self::decode($row->published);
        }

        return $out;
    }

    /** Taslak (düzenleyicinin otomatik kaydı). @return list<array> temizlenmiş bloklar */
    public static function saveDraft(string $zone, mixed $blocks, ?int $userId): array
    {
        self::known($zone);
        $clean = BlockData::cleanZone($blocks, Locales::all());
        DB::table('page_layouts')->updateOrInsert(['zone' => $zone], ['draft' => self::encode($clean), 'draft_by' => $userId, 'draft_at' => Clock::now()]);

        return $clean;
    }

    /** Taslağı atar (yayındaki kalır). */
    public static function discard(string $zone): void
    {
        DB::table('page_layouts')->where('zone', $zone)->update(['draft' => null, 'draft_by' => null, 'draft_at' => null]);
        DB::table('page_layouts')->where('zone', $zone)->whereNull('published')->delete();
    }

    /**
     * Taslakları yayına alır.
     *
     * @param  list<string>|null  $zones  null: taslağı olan bütün bölgeler
     * @return list<string> yayınlanan bölgeler
     */
    public static function publish(?array $zones, ?int $userId): array
    {
        $query = DB::table('page_layouts')->whereNotNull('draft');
        if ($zones !== null) {
            $query->whereIn('zone', $zones);
        }
        $published = [];
        DB::transaction(static function () use ($query, $userId, &$published) {
            $now = Clock::now();
            foreach ($query->get(['id', 'zone', 'published', 'draft']) as $row) {
                if ($row->published !== null) {
                    self::remember((string) $row->zone, (string) $row->published, $userId, $now);
                }
                DB::table('page_layouts')->where('id', $row->id)->update([
                    'published' => $row->draft, 'draft' => null, 'draft_by' => null, 'draft_at' => null,
                    'published_by' => $userId, 'published_at' => $now,
                ]);
                $published[] = (string) $row->zone;
            }
        });
        if ($published) {
            PageCache::flush();
        }

        return $published;
    }

    /** @return list<array{id:int,createdAt:?string,user:?string,count:int}> */
    public static function revisions(string $zone): array
    {
        $rows = DB::table('page_layout_revisions')->where('zone', $zone)->orderByDesc('id')->limit(self::KEEP)->get();
        UserNames::map($rows->pluck('user_id'));

        return $rows->map(static fn ($r) => [
            'id' => (int) $r->id,
            'createdAt' => Clock::atom($r->created_at),
            'user' => UserNames::of($r->user_id ? (int) $r->user_id : null),
            'count' => count(self::decode($r->blocks) ?? []),
        ])->all();
    }

    /** Geçmişteki hali taslağa alır (yayınlamak düzenleyicide). @return list<array> */
    public static function restore(string $zone, int $id, ?int $userId): array
    {
        $row = DB::table('page_layout_revisions')->where('zone', $zone)->where('id', $id)->first();
        if (! $row) {
            throw NotFoundException::message('Bu kayıt bulunamadı.');
        }

        return self::saveDraft($zone, self::decode($row->blocks) ?? [], $userId);
    }

    /**
     * Yedek: bölgelerin yayındaki ve taslak halleri ve bloklardaki görseller.
     *
     * @return array{zones:array<string,array{published:?array,draft:?array}>,media:list<int>}
     */
    public static function export(): array
    {
        $zones = [];
        $media = [];
        foreach (DB::table('page_layouts')->orderBy('zone')->get() as $row) {
            $zones[(string) $row->zone] = ['published' => self::decode($row->published), 'draft' => self::decode($row->draft)];
            foreach ([$zones[$row->zone]['published'], $zones[$row->zone]['draft']] as $blocks) {
                array_push($media, ...self::images($blocks ?? []));
            }
        }

        return ['zones' => $zones, 'media' => array_values(array_unique($media))];
    }

    /**
     * Yedekten: bütün bölgeler yazılır (görsel kimlikleri yeni kurulumdakine çevrilir). Blok sayfalarının bölgesi
     * (`page.main@<kimlik>`) sayfanın yeni kimliğine bağlanır; sayfası yedekte olmayan bölge alınmaz.
     *
     * @param  array<int,int>  $mediaMap
     * @param  array<int,int>  $pageMap  yedekteki sayfa kimliği → yeni kimlik
     */
    public static function import(array $zones, array $mediaMap, array $pageMap = []): int
    {
        DB::table('page_layouts')->delete();
        $count = 0;
        foreach ($zones as $zone => $state) {
            if (! is_string($zone) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9-]*(\.[a-zA-Z0-9-]+)+(@\d+)?$/', $zone) || ! is_array($state)) {
                continue;
            }
            if (preg_match('/^(.+)@(\d+)$/', $zone, $m)) {
                if (! isset($pageMap[(int) $m[2]])) {
                    continue;
                }
                $zone = $m[1].'@'.$pageMap[(int) $m[2]];
            }
            $map = static fn ($blocks) => is_array($blocks) ? self::encode(self::mapImages($blocks, $mediaMap)) : null;
            DB::table('page_layouts')->insert(['zone' => $zone, 'published' => $map($state['published'] ?? null), 'draft' => $map($state['draft'] ?? null)]);
            $count++;
        }

        return $count;
    }

    /** Bölge kitaplıkta tanımlı mı (derlemede `zone()` ile açılmış); kayıt bölgesinin sayfası var mı. */
    private static function known(string $zone): void
    {
        if (BlockLibrary::zone($zone) === null) {
            throw NotFoundException::message('Bu bölge sitede yok (derleme eski olabilir).');
        }
        if (preg_match('/@(\d+)$/', $zone, $m) && (BlockLibrary::base($zone) !== PageType::ZONE || ! (new PageType)->find((int) $m[1]))) {
            throw NotFoundException::message('Bu sayfa bulunamadı.');
        }
    }

    private static function remember(string $zone, string $blocks, ?int $userId, string $now): void
    {
        DB::table('page_layout_revisions')->insert(['zone' => $zone, 'blocks' => $blocks, 'user_id' => $userId, 'created_at' => $now]);
        $keep = DB::table('page_layout_revisions')->where('zone', $zone)->orderByDesc('id')->limit(self::KEEP)->pluck('id');
        DB::table('page_layout_revisions')->where('zone', $zone)->whereNotIn('id', $keep->all())->delete();
    }

    /** @return list<int> blokların görsel alanlarındaki kimlikler */
    public static function images(array $blocks): array
    {
        $ids = [];
        self::walkImages($blocks, static function (int $id) use (&$ids) {
            $ids[] = $id;

            return $id;
        });

        return $ids;
    }

    /** @param array<int,int> $map */
    private static function mapImages(array $blocks, array $map): array
    {
        return self::walkImages($blocks, static fn (int $id) => $map[$id] ?? 0);
    }

    /** Bloklardaki görsel alanlarını (satırlardakiler dahil) dolaşır; `$fn` yeni kimliği döner. */
    private static function walkImages(array $blocks, callable $fn): array
    {
        foreach ($blocks as $b => $block) {
            $fields = (array) (BlockLibrary::data()['blocks'][$block['type'] ?? '']['fields'] ?? []);
            foreach ($fields as $field) {
                $apply = static function (&$value) use ($field, $fn) {
                    if ($field['type'] === 'image' && (int) $value > 0) {
                        $value = $fn((int) $value);
                    } elseif ($field['type'] === 'rows' && is_array($value)) {
                        foreach ($value as &$row) {
                            foreach ((array) ($field['columns'] ?? []) as $column) {
                                if ($column['type'] === 'image' && (int) ($row[$column['name']] ?? 0) > 0) {
                                    $row[$column['name']] = $fn((int) $row[$column['name']]);
                                }
                            }
                        }
                        unset($row);
                    }
                };
                if (isset($blocks[$b]['data'][$field['name']])) {
                    $apply($blocks[$b]['data'][$field['name']]);
                }
                foreach (array_keys((array) ($block['i18n'] ?? [])) as $locale) {
                    if (isset($blocks[$b]['i18n'][$locale][$field['name']])) {
                        $apply($blocks[$b]['i18n'][$locale][$field['name']]);
                    }
                }
            }
        }

        return $blocks;
    }

    private static function encode(array $blocks): string
    {
        return Text::json(BlockData::objects($blocks));
    }

    /** @return list<array>|null */
    private static function decode(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $value = json_decode($json, true);

        return is_array($value) ? array_values($value) : null;
    }
}
