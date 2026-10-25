<?php

namespace App\Modules\Content\Http;

use App\Core\Activity\Activity;
use App\Core\Content\EditableContent;
use App\Core\Content\PageCache;
use App\Core\Content\Types;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Http\ListQuery;
use App\Core\Security\Capability;
use App\Core\Setup\Onboarding;
use App\Core\Support\Text;
use App\Modules\Blog\BlogPosts;
use App\Modules\Briefs\Domain\Brief;
use App\Modules\Briefs\Repository\BriefRepository;
use App\Modules\Briefs\Service\PurgeBriefs;
use App\Modules\I18n\ContentTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Panel (/panel/) uçları — yalnızca yönetici. `{type}`: projects, services,
 * references, posts.
 *
 *   GET    /admin/overview                 sayılar, son değişenler, yeni talepler
 *   GET    /admin/schema/{type}            form şeması
 *   GET    /admin/content/{type}           liste
 *   GET    /admin/content/{type}/new       boş kayıt
 *   GET    /admin/content/{type}/{id}      kayıt
 *   POST   /admin/content/{type}           yeni kayıt → kayıt
 *   PUT    /admin/content/{type}/{id}      güncelle → kayıt
 *   DELETE /admin/content/{type}/{id}      çöpe taşı
 *   POST   /admin/content/{type}/order     { ids: [] } sürükle-bırak sırası
 *   GET    /admin/options/{type}           seçim kutusu için [{ value, label }]
 *   POST   /admin/translate/{type}         { from, to, fields } Jarvis ile çeviri (kaydetmez)
 *   POST   /admin/content/posts/{id}/translations  { locale, mode: empty|copy|ai } çeviri yazısı oluştur
 *   POST   /admin/content/posts/{id}/link          { post_id } var olan yazıyı çeviri olarak bağla
 *   POST   /admin/content/posts/{id}/unlink        yazıyı çeviri grubundan ayır
 *   GET    /admin/settings                 ayar tanımları ve değerleri
 *   GET    /admin/briefs                   talepler (sayfalı; ?status=new|read|archived&search=)
 *   GET    /admin/briefs/{id}              talep (açılınca "okundu" olur)
 *   POST   /admin/briefs/{id}/status       { status: archived | read }
 *   DELETE /admin/briefs/{id}              kalıcı sil (KVKK silme talebi)
 */
final class AdminContentController extends Controller
{
    public function schema(Request $request, string $type): array
    {
        return $this->type($type, $request)->schema();
    }

    public function index(Request $request, string $type): array
    {
        return ['items' => $this->type($type, $request)->adminList()];
    }

    public function blank(Request $request, string $type): array
    {
        return $this->type($type, $request)->blankItem();
    }

    public function show(Request $request, string $type, int $id): array
    {
        return $this->type($type, $request)->adminItem($id);
    }

    public function create(Request $request, string $type): JsonResponse
    {
        $content = $this->type($type, $request);
        $input = $this->publishGuard($request, $type, null, $this->input($request));
        $id = $content->save(null, $input);
        $item = $content->adminItem($id);
        $this->activity($type, 'created', $id, $item);

        return $this->created($item);
    }

    public function update(Request $request, string $type, int $id): array
    {
        $content = $this->type($type, $request);
        $input = $this->publishGuard($request, $type, $id, $this->input($request));
        $id = $content->save($id, $input);
        $item = $content->adminItem($id);
        $this->activity($type, 'updated', $id, $item);

        return $item;
    }

    public function delete(Request $request, string $type, int $id): Response
    {
        $content = $this->type($type, $request);
        if (! $request->user()->can(Capability::DELETE_CONTENT)) {
            throw new DomainException('km_forbidden', 'İçerik silme yetkiniz yok.', 403, ['capability' => Capability::DELETE_CONTENT]);
        }
        $content->delete($id);
        $this->activity($type, 'deleted', $id, []);

        return $this->noContent();
    }

    public function order(Request $request, string $type): Response
    {
        $this->type($type, $request);
        $content = Types::get($type) ?? throw NotFoundException::message('Bu içerik sıralanamaz.');
        $ids = (array) ($this->input($request)['ids'] ?? []);
        $content->reorder($ids);
        Activity::type('content.reordered');
        Activity::subject($content->recordKind(), 0, $content->labels()[0]);
        Activity::with(['label' => $content->labels()[0], 'count' => count($ids), 'fields' => []]);

        return $this->noContent();
    }

    public function options(Request $request, string $type): array
    {
        return ['items' => array_map(
            static fn (array $row) => ['value' => $row['id'], 'label' => $row['title'], 'status' => $row['status']],
            $this->type($type)->adminList()
        )];
    }

    /** Jarvis ile çeviri: formdaki kaynak dil alanları hedef dile çevrilir (kaydedilmez). */
    public function translate(Request $request, string $type): array
    {
        $this->type($type, $request);
        ContentTranslator::ensureAvailable();
        $in = $this->input($request);

        return ['fields' => (new ContentTranslator)->translate((array) ($in['fields'] ?? []), (string) ($in['from'] ?? ''), (string) ($in['to'] ?? ''))];
    }

    /** Blog: yazının bir dildeki çevirisini oluşturur (taslak). */
    public function createPostTranslation(Request $request, int $id): JsonResponse
    {
        $this->type('posts', $request);
        $in = $this->input($request);
        $locale = (string) ($in['locale'] ?? '');
        $mode = in_array($in['mode'] ?? '', ['copy', 'ai'], true) ? (string) $in['mode'] : 'empty';
        $posts = new BlogPosts;
        $translated = [];
        if ($mode === 'ai') {
            ContentTranslator::ensureAvailable();
            $source = $posts->adminItem($id);
            $translated = (new ContentTranslator)->translate(
                array_filter(['title' => $source['title'], 'excerpt' => $source['excerpt'], 'html' => $source['html']], static fn ($v) => $v !== ''),
                (string) $source['locale'],
                $locale
            );
        }
        $newId = $posts->createTranslation($id, $locale, $mode, $translated);
        $item = $posts->adminItem($newId);
        Activity::type('post.translation_created');
        Activity::subject('post', $newId, Text::stripTags((string) $item['title']));
        Activity::with(['locale' => $locale, 'mode' => $mode, 'source' => $id]);

        return $this->created(['id' => $newId, 'item' => $item]);
    }

    /** Blog: var olan yazıyı bu yazının çevirisi olarak bağlar. */
    public function linkPost(Request $request, int $id): array
    {
        $this->type('posts', $request);
        $other = (int) ($this->input($request)['post_id'] ?? 0);
        $posts = new BlogPosts;
        $posts->link($id, $other);
        Activity::type('post.translation_linked');
        Activity::subject('post', $other, Text::stripTags((string) DB::table('posts')->where('id', $other)->value('title')));
        Activity::with(['source' => $id]);

        return $posts->adminItem($id);
    }

    /** Blog: yazıyı çeviri grubundan ayırır. */
    public function unlinkPost(Request $request, int $id): array
    {
        $this->type('posts', $request);
        $posts = new BlogPosts;
        $posts->unlink($id);
        Activity::type('post.translation_unlinked');
        Activity::subject('post', $id, Text::stripTags((string) DB::table('posts')->where('id', $id)->value('title')));

        return $posts->adminItem($id);
    }

    public function overview(Request $request): array
    {
        $types = Types::editable();
        $counts = [];
        $recent = [];
        foreach ($types as $name => $type) {
            $rows = $type->adminList();
            $counts[$name] = [
                'total' => count($rows),
                'published' => count(array_filter($rows, static fn ($r) => $r['status'] === 'publish')),
                'draft' => count(array_filter($rows, static fn ($r) => $r['status'] === 'draft')),
            ];
            foreach ($rows as $row) {
                $recent[] = ['type' => $name] + array_intersect_key($row, array_flip(['id', 'title', 'status', 'image', 'modified']));
            }
        }
        usort($recent, static fn ($a, $b) => strcmp((string) $b['modified'], (string) $a['modified']));

        $briefs = new BriefRepository;

        return [
            'counts' => $counts,
            'recent' => array_slice($recent, 0, 6),
            // Talep sahiplerinin kişisel verisi yalnızca talepleri görebilene
            'briefs' => $request->user()?->can(Capability::VIEW_BRIEFS) ? [
                'new' => $briefs->countByStatus(Brief::NEW),
                'latest' => $briefs->paginate(new ListQuery(1, 5, 'created_at', 'desc', '', []))->items,
            ] : null,
            'cache' => PageCache::count(),
            // Sıradaki adımlar (modüllerin `steps()`'i; kişinin yetkisine göre).
            'steps' => Onboarding::for(static fn (string $cap) => (bool) $request->user()?->can($cap)),
        ];
    }

    public function briefs(Request $request): mixed
    {
        $status = Text::key($request->query('status', ''));
        $filters = array_key_exists($status, Brief::STATUSES) ? ['status' => $status] : [];
        $page = max(1, (int) $request->query('page', 1));

        return (new BriefRepository)->paginate(new ListQuery($page, 20, 'created_at', 'desc', Text::field($request->query('search', '')), $filters));
    }

    public function brief(int $id): array
    {
        $briefs = new BriefRepository;
        $brief = $briefs->get($id);
        if ($brief->status() === Brief::NEW) {
            $brief->markRead();
            $briefs->save($brief);
        }

        return $brief->toArray();
    }

    public function briefStatus(Request $request, int $id): array
    {
        $briefs = new BriefRepository;
        $brief = $briefs->get($id);
        // Talebin yolu (Brief): okundu, yanıtlandı, arşiv, arşivden geri.
        $status = (string) ($this->input($request)['status'] ?? '');
        match ($status) {
            Brief::READ => $brief->markRead(),
            Brief::REPLIED => $brief->markReplied(),
            Brief::ARCHIVED => $brief->archive(),
            'reopen' => $brief->reopen(),
            default => throw ValidationException::field('status', 'Geçersiz durum.'),
        };
        $briefs->save($brief);
        Activity::type('briefs.status_changed');
        Activity::subject('brief', $id, $brief->toArray()['name'] ?? '');
        Activity::with(['status' => $status]);

        return $brief->toArray();
    }

    public function briefDelete(int $id): Response
    {
        if (! (new PurgeBriefs)->one($id)) {
            throw NotFoundException::message('Talep bulunamadı.');
        }
        Activity::skip(); // brief.deleted olayını servis yazar

        return $this->noContent();
    }

    /** İçerik türü; kullanıcının o türü düzenleme yetkisi yoksa 403. */
    private function type(string $name, ?Request $request = null): EditableContent
    {
        $content = Types::editable()[$name] ?? throw NotFoundException::message('Bilinmeyen içerik türü.');
        if ($request && ! $request->user()->can($content->capability())) {
            throw new DomainException('km_forbidden', 'Bu içeriği düzenleme yetkiniz yok.', 403, ['capability' => $content->capability()]);
        }

        return $content;
    }

    /**
     * Yayınlama yetkisi (`km_publish_content`) yoksa yeni kayıt taslak kalır,
     * var olan kaydın yayın durumu değişmez (Yazar yazar, Editör yayınlar). Aynısı dil başına:
     * dillerin yayın durumu değişmez, yeni eklenen dil taslak gelir.
     */
    private function publishGuard(Request $request, string $type, ?int $id, array $input): array
    {
        if ($request->user()->can(Capability::PUBLISH_CONTENT)) {
            return $input;
        }
        $current = $id ? DB::table($type === 'posts' ? 'posts' : 'contents')->where('id', $id)->value('status') : null;
        $input['status'] = $current ?: 'draft';
        $content = Types::get($type);
        if ($content !== null && is_array($input['locales'] ?? null)) {
            $before = $id && ($record = $content->find($id)) ? $content->languages($record->data) : [];
            foreach (array_keys($input['locales']) as $code) {
                if (is_array($input['locales'][$code])) {
                    $input['locales'][$code]['status'] = $before[$code]['status'] ?? 'draft';
                }
            }
        }

        return $input;
    }

    private function activity(string $type, string $action, int $id, array $item): void
    {
        $kind = $this->type($type)->recordKind();
        $title = (string) DB::table($type === 'posts' ? 'posts' : 'contents')->where('id', $id)->value('title');
        Activity::type("{$kind}.{$action}");
        Activity::subject($kind, $id, Text::stripTags($title));
        if ($action !== 'deleted') {
            Activity::with(['status' => (string) ($item['status'] ?? '')]);
        }
    }
}
