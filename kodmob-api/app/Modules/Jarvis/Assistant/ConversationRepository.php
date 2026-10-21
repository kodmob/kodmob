<?php

namespace App\Modules\Jarvis\Assistant;

use App\Core\Exceptions\NotFoundException;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * Jarvis sohbetleri ve mesajları. Mesajlar yalnızca eklenir (geçmiş
 * düzenlenmez): Claude'un düşünme blokları değiştirilmemiş geçmiş ister.
 */
final class ConversationRepository
{
    public function create(int $userId, string $title, string $model): array
    {
        $now = Clock::now();
        $id = (int) DB::table('jarvis_conversations')->insertGetId([
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 190),
            'model' => $model,
            'pending' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->get($id, $userId);
    }

    public function get(int $id, int $userId): array
    {
        $row = DB::table('jarvis_conversations')->where('id', $id)->where('user_id', $userId)->first();
        if (! $row) {
            throw NotFoundException::for('jarvis_conversation', $id, 'Sohbet bulunamadı.');
        }

        return self::present((array) $row);
    }

    /** @return list<array> */
    public function list(int $userId, int $limit = 50): array
    {
        return DB::table('jarvis_conversations')->where('user_id', $userId)->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($row) => self::present((array) $row))->all();
    }

    public function update(int $id, array $fields): void
    {
        $fields['updated_at'] = Clock::now();
        DB::table('jarvis_conversations')->where('id', $id)->update($fields);
    }

    public function addUsage(int $id, int $in, int $out): void
    {
        DB::table('jarvis_conversations')->where('id', $id)->update([
            'input_tokens' => DB::raw('input_tokens + '.max(0, $in)),
            'output_tokens' => DB::raw('output_tokens + '.max(0, $out)),
        ]);
    }

    public function delete(int $id, int $userId): void
    {
        $this->get($id, $userId);
        DB::table('jarvis_messages')->where('conversation_id', $id)->delete();
        DB::table('jarvis_conversations')->where('id', $id)->delete();
    }

    /** @param string|list<array> $content */
    /** `$native`: yanıtın sağlayıcıya özgü hali (App\Core\Ai); aynı sağlayıcıyla devam ederken aynen gider. */
    public function append(int $conversationId, string $role, string|array $content, array $meta = [], ?array $native = null): int
    {
        $id = (int) DB::table('jarvis_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => Text::json($content),
            'meta' => $meta ? Text::json($meta) : null,
            'native' => $native ? Text::json($native) : null,
            'created_at' => Clock::now(),
        ]);
        $this->update($conversationId, []);

        return $id;
    }

    /** @return list<array{id:int,role:string,content:string|array,meta:array,created_at:string}> */
    public function messages(int $conversationId): array
    {
        return DB::table('jarvis_messages')->where('conversation_id', $conversationId)->orderBy('id')->get()->map(static fn ($r) => [
            'id' => (int) $r->id,
            'role' => $r->role,
            'content' => json_decode((string) $r->content, true),
            'meta' => $r->meta ? (json_decode((string) $r->meta, true) ?: []) : [],
            'native' => isset($r->native) && $r->native ? (json_decode((string) $r->native, true) ?: null) : null,
            'created_at' => Clock::local($r->created_at),
        ])->all();
    }

    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'model' => $row['model'],
            'pending' => (bool) $row['pending'],
            'input_tokens' => (int) $row['input_tokens'],
            'output_tokens' => (int) $row['output_tokens'],
            'created_at' => Clock::local($row['created_at']),
            'updated_at' => Clock::local($row['updated_at']),
        ];
    }
}
