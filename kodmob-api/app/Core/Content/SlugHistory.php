<?php

namespace App\Core\Content;

use App\Core\Support\Clock;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Kayıt adreslerinin geçmişi (`slug_history`): yazının ya da kendi sayfası olan bir kaydın (Types::paged,
 * ör. proje) adresi (slug) değişince eskisi burada kalır. Sayfa bulunamazsa (Site\Render\Renderer):
 *
 * - eski adres kayıtlı ve kayıt bu dilde görünürse → 301 yeni adrese;
 * - kayıt silinmişse (çöpte ya da kalıcı) ya da silinmiş bir kaydın adresiyse → 410 Gone
 *   (çöpten geri alınınca kayıt yine bulunur, 410 kalkar);
 * - aksi halde 404 (taslak kayıt yayına alınınca açılabilir).
 *
 * Bölüm adı ya da dil öneki değişimi buraya değil, build'in adres geçmişine girer
 * (tv/src/profiles/<ad>/i18n/url-history.json).
 */
final class SlugHistory
{
    public static function remember(string $kind, string $locale, string $slug, int $recordId): void
    {
        try {
            DB::table('slug_history')->updateOrInsert(
                ['type' => $kind, 'locale' => $locale, 'slug' => mb_substr($slug, 0, 190)],
                ['record_id' => $recordId, 'created_at' => Clock::now()]
            );
        } catch (QueryException) {
            // Tablo yoksa (kurulum öncesi) geçmiş tutulmaz.
        }
    }

    /** Adres yeniden kullanıldı: artık güncel kayda ait, geçmişten düşer. */
    public static function forget(string $kind, string $locale, string $slug): void
    {
        try {
            DB::table('slug_history')->where('type', $kind)->where('locale', $locale)->where('slug', $slug)->delete();
        } catch (QueryException) {
        }
    }

    /**
     * Yedek için: bütün geçmiş (kayıt kimlikleri yedekteki kimliklerdir).
     *
     * @return list<array{type:string,locale:string,slug:string,record:int}>
     */
    public static function export(): array
    {
        try {
            return DB::table('slug_history')->orderBy('id')->get(['type', 'locale', 'slug', 'record_id'])
                ->map(static fn ($r) => ['type' => (string) $r->type, 'locale' => (string) $r->locale, 'slug' => (string) $r->slug, 'record' => (int) $r->record_id])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Yedekten: geri yüklenen kayıtlar yeni kimlik alır, geçmiş `$maps` ile yeni kimliklere
     * bağlanır (eşlenemeyen satır, yani yedekte olmayan kayıt, atlanır). Aynı adresin mevcut
     * satırı (çöpteki eski kimliği gösteren) yenisiyle değişir.
     *
     * @param  array<string,array<int,int>>  $maps  tür → yedekteki kimlik → yeni kimlik
     * @return int yazılan satır
     */
    public static function restore(array $rows, array $maps): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            $id = $maps[$type][(int) ($row['record'] ?? 0)] ?? null;
            $locale = Locales::normalize((string) ($row['locale'] ?? ''));
            $slug = Text::slug((string) ($row['slug'] ?? ''));
            if ($id === null || $slug === '') {
                continue;
            }
            self::remember($type, $locale, $slug, $id);
            $count++;
        }

        return $count;
    }

    /**
     * Bulunamayan kayıt adresi için karar (done-coklu-dil-raporu.md §4.7), sırayla:
     * 1. adres geçmişi: kayıt bu dilde yayındaysa yeni adresine 301, silinmişse 410;
     * 2. adres başka bir dilde yayındaki bir kayda ait (/en/blog/turkce-yazi/): kayıt bu dilde de
     *    varsa oradaki adresine 301, yoksa 404 + "şu dillerde mevcut";
     * 3. çöpteki bir kaydın adresi: 410.
     *
     * @param  callable(string $locale, string $slug): ?string  $url  kayıt adresi üreticisi (Router)
     * @return array{location:string}|array{gone:true}|array{available:array<string,string>}|null
     *                                                                                            `available`: dil → adres
     */
    public static function resolve(string $kind, string $locale, string $slug, callable $url): ?array
    {
        // Blog yazısı ya da sitede kendi sayfası olan tür (Types::paged).
        if (($kind !== 'post' && ! isset(SiteManifest::pageRoutes()[$kind])) || $slug === '') {
            return null;
        }
        try {
            $recordId = DB::table('slug_history')->where('type', $kind)->where('locale', $locale)->where('slug', $slug)->value('record_id');
        } catch (QueryException) {
            $recordId = null;
        }

        if ($recordId !== null) {
            $current = $kind === 'post' ? self::post((int) $recordId) : self::record($kind, (int) $recordId, $locale);
            if ($current === 'gone') {
                return ['gone' => true];
            }
            if (is_array($current) && ($target = $url($current[0], $current[1])) !== null) {
                return ['location' => $target];
            }
            $available = self::available(self::urlsOf($kind, (int) $recordId), $url);

            return $available ? ['available' => $available] : null;
        }

        if (($elsewhere = self::elsewhere($kind, $locale, $slug)) !== null) {
            if (isset($elsewhere[$locale]) && ($target = $url($locale, $elsewhere[$locale])) !== null) {
                return ['location' => $target];
            }
            $available = self::available($elsewhere, $url);
            if ($available) {
                return ['available' => $available];
            }
        }

        return self::trashedSlug($kind, $locale, $slug) ? ['gone' => true] : null;
    }

    /**
     * Adres başka bir dilde yayındaki bir kayda aitse kaydın yayındaki dilleri: dil → adres (slug).
     *
     * @return array<string,string>|null
     */
    private static function elsewhere(string $kind, string $locale, string $slug): ?array
    {
        if ($kind === 'post') {
            $post = DB::table('posts')->whereNull('deleted_at')->where('status', 'publish')->where('published_at', '<=', Clock::now())
                ->where('slug', $slug)->where('locale', '!=', $locale)->orderBy('id')->first();

            return $post ? self::urlsOf('post', (int) $post->id) : null;
        }
        $type = Types::page($kind);
        foreach ($type?->records(true) ?? [] as $record) {
            if (in_array($slug, $type->slugs($record->data), true)) {
                return $type->slugs($record->data);
            }
        }

        return null;
    }

    /**
     * Kaydın (yazıda çeviri grubunun) yayındaki dilleri: dil → adres (slug).
     *
     * @return array<string,string>
     */
    private static function urlsOf(string $kind, int $id): array
    {
        if ($kind !== 'post') {
            $type = Types::page($kind);
            $record = $type?->find($id);

            return $record && $record->status === 'publish' ? $type->slugs($record->data) : [];
        }
        $group = DB::table('posts')->where('id', $id)->value('translation_group');
        if (! $group) {
            return [];
        }
        $out = [];
        foreach (DB::table('posts')->whereNull('deleted_at')->where('status', 'publish')->where('published_at', '<=', Clock::now())
            ->where('translation_group', $group)->orderBy('id')->get(['locale', 'slug']) as $row) {
            $out[Locales::normalize((string) $row->locale)] ??= (string) $row->slug;
        }

        return $out;
    }

    /**
     * Sitede açık dillerdeki adresler (dil kaydının sırasıyla).
     *
     * @param  array<string,string>  $slugs
     * @return array<string,string> dil → tam yol
     */
    private static function available(array $slugs, callable $url): array
    {
        $out = [];
        foreach (Locales::active() as $code) {
            if (isset($slugs[$code]) && ($target = $url($code, $slugs[$code])) !== null) {
                $out[$code] = $target;
            }
        }

        return $out;
    }

    /** @return array{0:string,1:string}|'gone'|null [dil, adres] */
    private static function record(string $kind, int $id, string $locale): array|string|null
    {
        $type = Types::page($kind);
        $record = $type?->find($id);
        if ($type === null || $record === null) {
            return 'gone';
        }
        $slug = $record->status === 'publish' ? ($type->slugs($record->data)[$locale] ?? null) : null;

        return $slug !== null ? [$locale, $slug] : null;
    }

    /** @return array{0:string,1:string}|'gone'|null */
    private static function post(int $id): array|string|null
    {
        $post = DB::table('posts')->where('id', $id)->first();
        if (! $post || $post->deleted_at !== null) {
            return 'gone';
        }
        if ($post->status !== 'publish' || Clock::carbon((string) $post->published_at)->isFuture()) {
            return null;
        }

        return [(string) $post->locale, (string) $post->slug];
    }

    /** Çöpteki bir kaydın adresi mi (geçmişe girmeden silinmiş kayıt). */
    private static function trashedSlug(string $kind, string $locale, string $slug): bool
    {
        if ($kind === 'post') {
            return DB::table('posts')->whereNotNull('deleted_at')->where('locale', $locale)->where('slug', $slug)->exists();
        }
        $type = Types::page($kind);
        if ($type === null) {
            return false;
        }
        foreach (DB::table('contents')->where('type', $type->postType())->whereNotNull('deleted_at')->pluck('data') as $data) {
            if ((json_decode((string) $data, true)['i18n'][$locale]['slug'] ?? null) === $slug) {
                return true;
            }
        }

        return false;
    }
}
