<?php

namespace App\Modules\Jarvis\Http;

use App\Core\Activity\Activity;
use App\Core\Ai\AiSettings;
use App\Core\Http\Controller;
use App\Core\Support\Text;
use App\Modules\Jarvis\Assistant\ConversationRepository;
use App\Modules\Jarvis\Assistant\JarvisActions;
use App\Modules\Jarvis\Assistant\JarvisAgent;
use App\Modules\Jarvis\Assistant\Transcript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Jarvis sohbeti (etkin yapay zekâ sağlayıcısı; ayarları Ayarlar > Yapay zekâ, System\AiController).
 *
 *   GET    /jarvis/conversations                   sohbetler
 *   POST   /jarvis/conversations                   yeni sohbet
 *   GET    /jarvis/conversations/{id}              { conversation, transcript, pending }
 *   PATCH  /jarvis/conversations/{id}              { title }
 *   DELETE /jarvis/conversations/{id}
 *   POST   /jarvis/conversations/{id}/messages     { text } → döngü çalışır
 *   POST   /jarvis/conversations/{id}/continue     kesilen döngüyü sürdür
 */
final class AssistantController extends Controller
{
    public function index(Request $request): array
    {
        return ['items' => (new ConversationRepository)->list((int) $request->user()->id)];
    }

    public function create(Request $request): JsonResponse
    {
        $title = trim((string) ($this->input($request)['title'] ?? '')) ?: 'Yeni sohbet';

        $conversation = (new ConversationRepository)->create((int) $request->user()->id, $title, (string) AiSettings::active()['model']);
        Activity::subject('conversation', (int) $conversation['id'], $title);

        return $this->created($conversation);
    }

    public function show(Request $request, int $id): array
    {
        $repo = new ConversationRepository;
        $conversation = $repo->get($id, (int) $request->user()->id);
        $messages = $repo->messages($conversation['id']);
        $last = end($messages);

        return [
            'conversation' => $conversation,
            'transcript' => Transcript::build($messages),
            'pending' => $last && ($last['role'] === 'user' || ($last['meta']['stop_reason'] ?? '') === 'pause_turn'),
            'actions' => (new JarvisActions($repo))->pending($conversation['id']),
            'error' => null,
        ];
    }

    public function rename(Request $request, int $id): array
    {
        $repo = new ConversationRepository;
        $userId = (int) $request->user()->id;
        $repo->get($id, $userId);
        $title = mb_substr(Text::field($this->input($request)['title'] ?? ''), 0, 190);
        if ($title !== '') {
            $repo->update($id, ['title' => $title]);
        }

        return $repo->get($id, $userId);
    }

    public function delete(Request $request, int $id): Response
    {
        (new ConversationRepository)->delete($id, (int) $request->user()->id);

        return $this->noContent();
    }

    public function send(Request $request, int $id): array
    {
        return (new JarvisAgent)->send($id, (int) $request->user()->id, (string) ($this->input($request)['text'] ?? ''));
    }

    public function continue(Request $request, int $id): array
    {
        return (new JarvisAgent)->run($id, (int) $request->user()->id);
    }

    /** Operatör: yazma önerisini onayla (araç kullanıcının adına çalışır) ya da reddet; sohbet sürer. */
    public function approve(Request $request, int $id): array
    {
        return $this->decide($request, $id, true);
    }

    public function reject(Request $request, int $id): array
    {
        return $this->decide($request, $id, false);
    }

    private function decide(Request $request, int $id, bool $approve): array
    {
        $userId = (int) $request->user()->id;
        $decision = (new JarvisActions)->decide($id, $userId, $approve);
        Activity::subject('jarvis_action', $id, $decision['summary']);
        Activity::with(['summary' => $decision['summary'], 'status' => $decision['status']]);

        return (new JarvisAgent)->run($decision['conversation_id'], $userId);
    }
}
