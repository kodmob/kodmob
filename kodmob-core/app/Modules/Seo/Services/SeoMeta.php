<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\Locales;
use App\Core\Content\MediaPayload;
use App\Core\Content\Types;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * Kayıt başına SEO (`seo_meta`): blog yazısı ve kendi sayfası olan türlerin kayıtları (ör. proje).
 *
 *     { locales: { tr: { title, description, focus }, en: … }, noindex, canonical, image_id }
 *
 * Boş başlık/açıklama varsayılana döner (türün seoDefaults kancası, ör. proje "Başlık · Kısa tanım"
 * ve özet; yazı: başlık ve özet). Blog yazısı tek dillidir: yalnızca kendi dili dolar.
 */
final class SeoMeta
{
    /**
     * Kendi sayfası olan kayıt türleri (`recordKind`): sitenin kayıt rotaları (Types::paged) ve blog.
     * Kayıt SEO uçları, IndexNow ve olay dinleyicisi buradan okur.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [...array_keys(Types::paged()), 'post'];
    }

    public const TITLE_MAX = 120;

    public const DESCRIPTION_MAX = 320;

    /** @return array{locales:array<string,array{title:string,description:string,focus:string}>,noindex:bool,canonical:string,image_id:int} */
    public static function get(string $type, int $id): array
    {
        $row = DB::table('seo_meta')->where('subject_type', $type)->where('subject_id', $id)->first();
        $locales = $row ? (json_decode((string) $row->locales, true) ?: []) : [];
        $out = [];
        foreach (Locales::all() as $code) {
            $l = (array) ($locales[$code] ?? []);
            $out[$code] = ['title' => (string) ($l['title'] ?? ''), 'description' => (string) ($l['description'] ?? ''), 'focus' => (string) ($l['focus'] ?? '')];
        }

        return [
            'locales' => $out,
            'noindex' => (bool) ($row->noindex ?? false),
            'canonical' => (string) ($row->canonical ?? ''),
            'image_id' => (int) ($row->image_id ?? 0),
        ];
    }

    /** Panel için (görsel önizlemesiyle). */
    public static function admin(string $type, int $id): array
    {
        $meta = self::get($type, $id);
        $meta['image'] = $meta['image_id'] ? MediaPayload::admin($meta['image_id']) : null;

        return $meta;
    }

    /** @return array<string,array{label:string,from:string,to:string}> değişenler */
    public static function save(string $type, int $id, array $input): array
    {
        self::assertType($type);
        $before = self::get($type, $id);
        $locales = [];
        foreach (Locales::all() as $code) {
            $l = (array) ($input['locales'][$code] ?? []);
            $locales[$code] = [
                'title' => mb_substr(Text::field($l['title'] ?? ''), 0, self::TITLE_MAX),
                'description' => mb_substr(Text::field($l['description'] ?? ''), 0, self::DESCRIPTION_MAX),
                'focus' => mb_substr(Text::field($l['focus'] ?? ''), 0, 80),
            ];
        }
        $canonical = trim((string) ($input['canonical'] ?? ''));
        if ($canonical !== '' && ! str_starts_with($canonical, '/') && Text::url($canonical) === '') {
            throw ValidationException::field('canonical', 'Canonical adres / ile başlamalı ya da tam adres olmalı.');
        }
        $values = [
            'locales' => Text::json(array_filter($locales, static fn ($l) => implode('', $l) !== '')),
            'noindex' => filter_var($input['noindex'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'canonical' => mb_substr($canonical, 0, 500),
            'image_id' => max(0, (int) ($input['image_id'] ?? 0)),
            'updated_at' => Clock::now(),
        ];
        DB::table('seo_meta')->updateOrInsert(['subject_type' => $type, 'subject_id' => $id], $values);

        $after = self::get($type, $id);
        $changes = [];
        foreach (Locales::all() as $code) {
            foreach (['title' => 'SEO başlığı', 'description' => 'SEO açıklaması', 'focus' => 'Odak kelime'] as $field => $label) {
                if ($before['locales'][$code][$field] !== $after['locales'][$code][$field]) {
                    $changes["{$code}.{$field}"] = ['label' => $label.' ('.strtoupper($code).')', 'from' => $before['locales'][$code][$field], 'to' => $after['locales'][$code][$field]];
                }
            }
        }
        if ($before['noindex'] !== $after['noindex']) {
            $changes['noindex'] = ['label' => 'Arama motorlarından gizle', 'from' => $before['noindex'], 'to' => $after['noindex']];
        }
        if ($before['canonical'] !== $after['canonical']) {
            $changes['canonical'] = ['label' => 'Canonical', 'from' => $before['canonical'], 'to' => $after['canonical']];
        }
        if ($before['image_id'] !== $after['image_id']) {
            $changes['image'] = ['label' => 'Paylaşım görseli', 'from' => $before['image_id'] ? '#'.$before['image_id'] : '', 'to' => $after['image_id'] ? '#'.$after['image_id'] : ''];
        }

        return $changes;
    }

    /** Kayıt silinince (kalıcı) ya da yedek dönüşünde. */
    public static function forget(string $type, int $id): void
    {
        DB::table('seo_meta')->where('subject_type', $type)->where('subject_id', $id)->delete();
    }

    /** Yedek için (kayıt dışa aktarımına eklenir); boşsa null. */
    public static function export(string $type, int $id): ?array
    {
        $row = DB::table('seo_meta')->where('subject_type', $type)->where('subject_id', $id)->first();

        return $row ? [
            'locales' => json_decode((string) $row->locales, true) ?: [],
            'noindex' => (bool) $row->noindex,
            'canonical' => (string) $row->canonical,
            'image_id' => (int) $row->image_id,
        ] : null;
    }

    /** Yedekten: yeni kayıt kimliğine, görsel kimliği eşlenmiş olarak. */
    public static function import(string $type, int $id, array $meta, array $mediaMap = []): void
    {
        self::save($type, $id, ['image_id' => $mediaMap[(int) ($meta['image_id'] ?? 0)] ?? 0] + $meta);
    }

    /** @return array<string,true> "type:id" => noindex kayıtlar (site haritası) */
    public static function noindexSet(): array
    {
        $out = [];
        foreach (DB::table('seo_meta')->where('noindex', true)->get(['subject_type', 'subject_id']) as $r) {
            $out[$r->subject_type.':'.$r->subject_id] = true;
        }

        return $out;
    }

    private static function assertType(string $type): void
    {
        if (! in_array($type, self::types(), true)) {
            throw ValidationException::field('type', 'Bu tür kayıt için SEO ayarı yok.');
        }
    }
}
