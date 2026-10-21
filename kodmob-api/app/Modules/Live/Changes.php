<?php

namespace App\Modules\Live;

use App\Core\Content\Types;
use App\Core\Security\Capability;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Değişiklik akışı (dfGrid "canlı güncelleme"): bir tablonun yüklendiği andan beri
 * kendi türünde neler oldu? Kaynak olay defteri (`events`; her kayıtlı işlem bir satır).
 *
 *   GET /changes/cursor                  → { cursor }   şu anki son olay no (tablo veriden önce ister)
 *   GET /changes?since=120&types=project → { cursor, items: [{ type, id, created, deleted, reordered, mine }],
 *                                            truncated, stale }
 *
 * Panel tablosu bu sonuçla "2 yeni kayıt · 1 güncelleme" şeridini çizer, tıklanınca yeni ve değişen
 * satırları sarı vurguyla gösterir. Kullanıcı yalnızca görebildiği türlerin değişikliklerini alır.
 */
final class Changes
{
    /** Tür (olayın konu türü) → görmek için gereken yetki: içerik türleri kayıttan (Types). */
    public static function types(): array
    {
        $types = [];
        foreach (Types::editable() as $content) {
            $types[$content->recordKind()] = $content->capability();
        }

        return $types + ['brief' => Capability::VIEW_BRIEFS, 'user' => Capability::VIEW_USERS];
    }

    /** Bir yanıtta en fazla bu kadar olay (fazlası `truncated`; tablo yeniden sorar). */
    public const LIMIT = 200;

    /** Kayıt doğuran olaylar (yeni satır). */
    private const CREATED = ['post.translation_created', 'brief.received', 'records.restored'];

    public static function cursor(): int
    {
        return (int) DB::table('events')->max('id');
    }

    /** @param list<string> $types */
    public static function since(int $since, array $types, User $user): array
    {
        $known = self::types();
        $types = array_values(array_filter(array_unique($types), static fn ($t) => isset($known[$t]) && $user->can($known[$t])));
        $rows = $types ? DB::table('events')->where('id', '>', $since)->whereIn('subject_type', $types)
            ->orderBy('id')->limit(self::LIMIT + 1)->get(['id', 'event_type', 'subject_type', 'subject_id', 'user_id']) : collect();
        $truncated = $rows->count() > self::LIMIT;
        $rows = $rows->take(self::LIMIT);
        $oldest = (int) DB::table('events')->min('id');

        return [
            'cursor' => $truncated ? (int) $rows->last()->id : max($since, self::cursor()),
            'items' => $rows->map(static fn ($row) => [
                'type' => (string) $row->subject_type,
                'id' => (int) $row->subject_id,
                'created' => str_ends_with($row->event_type, '.created') || in_array($row->event_type, self::CREATED, true),
                'deleted' => str_ends_with($row->event_type, '.deleted'),
                'reordered' => str_ends_with($row->event_type, '.reordered'),
                'mine' => (int) $row->user_id === (int) $user->id,
            ])->values()->all(),
            'truncated' => $truncated,
            // Aradaki olaylar silindiyse (saklama süresi) neyin değiştiği bilinemez: tablo yeniden yüklenmeli.
            'stale' => $since > 0 && $oldest > $since + 1,
        ];
    }
}
