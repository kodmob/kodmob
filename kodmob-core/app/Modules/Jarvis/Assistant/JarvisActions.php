<?php

namespace App\Modules\Jarvis\Assistant;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use App\Modules\Jarvis\Skills\RouteSkill;
use App\Modules\Jarvis\Skills\SkillRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Jarvis Operatör: yazma aracının önerisi (`jarvis_actions`). Model yazma aracını çağırınca araç çalışmaz; öneri
 * kaydedilir, sohbet onay bekler. Kullanıcı onaylarsa araç onun adına çalışır (panel ucu, İşlem Geçmişi onun adıyla),
 * reddederse model "onaylanmadı" sonucunu alır. İki durumda da aynı turdaki diğer araçların sonuçlarıyla birlikte tek
 * araç sonucu mesajı eklenir ve sohbet sürer.
 */
final class JarvisActions
{
    private const TABLE = 'jarvis_actions';

    private const MAX_RESULT_BYTES = 20000;

    public function __construct(private readonly ConversationRepository $conversations = new ConversationRepository) {}

    /** @param list<array> $deferred aynı turun diğer tool_result blokları */
    public function propose(int $conversationId, int $userId, RouteSkill $skill, string $toolUseId, array $input, array $deferred): int
    {
        return (int) DB::table(self::TABLE)->insertGetId([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'tool' => $skill->name(),
            'tool_use_id' => mb_substr($toolUseId, 0, 120),
            'input' => Text::json($input),
            'summary' => mb_substr($skill->summary($input), 0, 300),
            'deferred' => Text::json($deferred),
            'status' => 'pending',
            'created_at' => Clock::now(),
        ]);
    }

    /** @return list<array{id:int,tool:string,tool_use_id:string,summary:string,input:array,status:string}> sohbetin bekleyen önerileri */
    public function pending(int $conversationId): array
    {
        return DB::table(self::TABLE)->where('conversation_id', $conversationId)->where('status', 'pending')->orderBy('id')->get()
            ->map(static fn ($row) => [
                'id' => (int) $row->id,
                'tool' => (string) $row->tool,
                'tool_use_id' => (string) $row->tool_use_id,
                'summary' => (string) $row->summary,
                'input' => (array) json_decode((string) $row->input, true),
                'status' => (string) $row->status,
            ])->all();
    }

    /**
     * Onay ya da ret: araç sonucu sohbete eklenir.
     *
     * @return array{conversation_id:int,summary:string,status:string}
     */
    public function decide(int $id, int $userId, bool $approve): array
    {
        $row = DB::table(self::TABLE)->where('id', $id)->where('user_id', $userId)->first();
        if (! $row) {
            throw NotFoundException::message('Öneri bulunamadı.');
        }
        if ($row->status !== 'pending') {
            throw new ConflictException('km_jarvis_decided', 'Bu öneri için zaten karar verildi.');
        }
        $input = (array) json_decode((string) $row->input, true);
        $status = 'rejected';
        $isError = true;
        $text = 'Kullanıcı bu işlemi onaylamadı. Başka bir şey önermeden önce ne istediğini sor.';
        if ($approve) {
            try {
                SkillRegistry::authorize((string) $row->tool, Auth::user());
                $output = SkillRegistry::get((string) $row->tool)->run($input);
                $text = (string) Text::json(['approved' => true, 'result' => $output]);
                $status = 'approved';
                $isError = false;
            } catch (DomainException $e) {
                $details = $e->details();
                $text = 'Onaylandı ama uygulanamadı: '.$e->getMessage().(! empty($details['fields']) ? ' '.Text::json($details['fields']) : '');
                $status = 'failed';
            } catch (\Throwable $e) {
                Log::warning('[Kodmob Jarvis] öneri #'.$id.': '.$e->getMessage());
                $text = 'Onaylandı ama beklenmeyen bir hata oluştu.';
                $status = 'failed';
            }
        }
        if (strlen($text) > self::MAX_RESULT_BYTES) {
            $text = mb_strcut($text, 0, self::MAX_RESULT_BYTES).'… [sonuç kısaltıldı]';
        }
        $blocks = (array) json_decode((string) $row->deferred, true);
        $blocks[] = ['type' => 'tool_result', 'tool_use_id' => (string) $row->tool_use_id, 'content' => $text, 'is_error' => $isError];
        DB::table(self::TABLE)->where('id', $id)->update(['status' => $status, 'result' => mb_substr($text, 0, 60000), 'decided_at' => Clock::now()]);
        $this->conversations->append((int) $row->conversation_id, 'user', $blocks);

        return ['conversation_id' => (int) $row->conversation_id, 'summary' => (string) $row->summary, 'status' => $status];
    }
}
