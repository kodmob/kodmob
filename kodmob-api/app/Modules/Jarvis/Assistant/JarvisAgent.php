<?php

namespace App\Modules\Jarvis\Assistant;

use App\Core\Ai\Ai;
use App\Core\Ai\AiSettings;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Text;
use App\Modules\Jarvis\Skills\RouteSkill;
use App\Modules\Jarvis\Skills\SkillRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Jarvis sohbet döngüsü (etkin yapay zekâ sağlayıcısı + beceriler; App\Core\Ai):
 *
 *     kullanıcı mesajı → model → (araç çağrısı → beceri → sonuç → model)* → yanıt
 *
 * Geçmiş ortak biçimde saklanır; her yanıtın sağlayıcıya özgü hali de (`native`) saklanır ve aynı
 * sağlayıcıyla devam ederken aynen gönderilir. Sohbet ortasında sağlayıcı değişse de sürer.
 *
 * Paylaşımlı hostingde tek istek uzun süremez: döngü `$budget` saniyede ya da
 * en çok `max_steps` araç turunda durur, sohbet "bekliyor" işaretlenir ve
 * panel `/continue` ile sürdürür. Her tur hemen kaydedilir (yalnızca ekleme):
 * araç çağrısı içeren yanıt kaydedilince araçlar aynı turda çalıştırılır,
 * sonuçları da kaydedilir; böylece kesilen döngü hep tutarlı bir yerden sürer.
 */
final class JarvisAgent
{
    private const LOCK_TTL = 180;

    private const MAX_RESULT_BYTES = 40000;

    public function __construct(
        private readonly ConversationRepository $conversations = new ConversationRepository,
    ) {}

    /** Yeni kullanıcı mesajı ekler ve döngüyü çalıştırır. */
    public function send(int $conversationId, int $userId, string $text, float $budget = 50.0): array
    {
        $text = trim(mb_scrub($text, 'UTF-8'));
        if ($text === '') {
            throw ValidationException::field('text', 'Mesaj boş olamaz.');
        }
        if (mb_strlen($text) > 20000) {
            throw ValidationException::field('text', 'Mesaj en çok 20.000 karakter olabilir.');
        }
        $conversation = $this->conversations->get($conversationId, $userId);
        if ($this->needsModel($this->conversations->messages($conversationId))) {
            throw new ConflictException('km_jarvis_busy', 'Jarvis önceki mesajı henüz bitirmedi; "Devam et"e basın.');
        }
        if ((new JarvisActions($this->conversations))->pending($conversationId)) {
            throw new ConflictException('km_jarvis_awaiting', 'Önce Jarvis\'in önerisini onaylayın ya da reddedin.');
        }
        $this->conversations->append($conversationId, 'user', $text);
        if (str_starts_with($conversation['title'], 'Yeni sohbet')) {
            $this->conversations->update($conversationId, ['title' => mb_substr(preg_replace('/\s+/', ' ', $text), 0, 80)]);
        }

        return $this->run($conversationId, $userId, $budget);
    }

    /** Kesilmiş döngüyü sürdürür (bekleyen yoksa bir şey yapmaz). */
    public function run(int $conversationId, int $userId, float $budget = 50.0): array
    {
        $this->conversations->get($conversationId, $userId);
        $lock = Cache::lock('km_jarvis_conv_'.$conversationId, self::LOCK_TTL);
        if (! $lock->get()) {
            throw new ConflictException('km_jarvis_running', 'Jarvis bu sohbette zaten çalışıyor.');
        }
        @set_time_limit((int) $budget + 70);

        $settings = AiSettings::get();
        $started = microtime(true);
        $steps = 0;
        $error = null;
        try {
            $messages = $this->conversations->messages($conversationId);
            while ($this->needsModel($messages)) {
                if ($steps >= (int) $settings['max_steps'] || microtime(true) - $started > $budget) {
                    break;
                }
                $response = Ai::send(
                    array_map(static fn ($m) => ['role' => $m['role'], 'content' => $m['content'], 'native' => $m['native'] ?? null], $messages),
                    SystemPrompt::blocks(),
                    SkillRegistry::tools(Auth::user())
                );
                $this->conversations->addUsage($conversationId, (int) ($response['usage']['input_tokens'] ?? 0), (int) ($response['usage']['output_tokens'] ?? 0));
                $this->conversations->append($conversationId, 'assistant', $response['content'], [
                    'stop_reason' => $response['stop_reason'],
                    'stop_details' => $response['native']['stop_details'] ?? null,
                    'provider' => $response['provider'],
                    'model' => $response['model'],
                    'usage' => $response['usage'],
                ], $response['native']);
                if ($response['stop_reason'] === 'tool_use') {
                    $results = $this->runTools($conversationId, $userId, $response['content']);
                    if ($results === null) {
                        // Operatör: yazma aracı kullanıcı onayı bekliyor (JarvisActions); sohbet onu bekler.
                        break;
                    }
                    if ($results) {
                        $this->conversations->append($conversationId, 'user', $results);
                    }
                }
                $steps++;
                $messages = $this->conversations->messages($conversationId);
            }
        } catch (DomainException $e) {
            $error = $e;
        } finally {
            $lock->release();
        }

        $messages = $this->conversations->messages($conversationId);
        $pending = $this->needsModel($messages);
        $this->conversations->update($conversationId, ['pending' => $pending ? 1 : 0]);

        return [
            'conversation' => $this->conversations->get($conversationId, $userId),
            'transcript' => Transcript::build($messages),
            'pending' => $pending,
            'actions' => (new JarvisActions($this->conversations))->pending($conversationId),
            'error' => $error ? ['code' => $error->errorCode(), 'message' => $error->getMessage()] : null,
        ];
    }

    /**
     * Son mesaj kullanıcıdansa (metin ya da araç sonucu) ya da yanıt
     * duraklatıldıysa (sunucu aracı, `pause_turn`) Claude'un sırası.
     */
    private function needsModel(array $messages): bool
    {
        $last = end($messages);
        if (! $last) {
            return false;
        }

        return $last['role'] === 'user' || ($last['meta']['stop_reason'] ?? '') === 'pause_turn';
    }

    /**
     * Aracı çalıştırır; tool_result blokları (hepsi tek kullanıcı mesajında). Yazma aracı (Operatör) çalışmaz: öneri
     * kaydedilir, diğerlerinin sonuçları onunla bekler ve `null` döner (sohbet onay bekler). Bir turda tek öneri.
     *
     * @return list<array>|null
     */
    private function runTools(int $conversationId, int $userId, array $content): ?array
    {
        $results = [];
        $proposal = null;
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $name = (string) $block['name'];
            $skill = SkillRegistry::has($name) ? SkillRegistry::get($name) : null;
            if ($skill instanceof RouteSkill && $skill->write()) {
                try {
                    SkillRegistry::authorize($name, Auth::user());
                    if ($proposal !== null) {
                        throw new DomainException('km_jarvis_one_action', 'Aynı turda tek işlem önerilebilir; önce ilkinin onaylanmasını bekle.', 409);
                    }
                    $proposal = [$skill, (string) $block['id'], (array) ($block['input'] ?? [])];
                } catch (DomainException $e) {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => (string) $block['id'], 'content' => $e->getMessage(), 'is_error' => true];
                }

                continue;
            }
            $isError = false;
            try {
                SkillRegistry::authorize($name, Auth::user());
                $output = SkillRegistry::get((string) $block['name'])->run((array) ($block['input'] ?? []));
                $text = (string) Text::json($output);
                if (strlen($text) > self::MAX_RESULT_BYTES) {
                    $text = mb_strcut($text, 0, self::MAX_RESULT_BYTES).'… [sonuç kısaltıldı]';
                }
            } catch (DomainException $e) {
                $isError = true;
                $details = $e->details();
                $text = $e->getMessage().(! empty($details['fields']) ? ' '.Text::json($details['fields']) : '');
            } catch (\Throwable $e) {
                Log::warning('[Kodmob Jarvis] araç '.($block['name'] ?? '?').': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
                $isError = true;
                $text = 'Araç çalışırken beklenmeyen bir hata oluştu.';
            }
            $results[] = ['type' => 'tool_result', 'tool_use_id' => (string) $block['id'], 'content' => $text, 'is_error' => $isError];
        }
        if ($proposal !== null) {
            (new JarvisActions($this->conversations))->propose($conversationId, $userId, $proposal[0], $proposal[1], $proposal[2], $results);

            return null;
        }

        return $results;
    }
}
