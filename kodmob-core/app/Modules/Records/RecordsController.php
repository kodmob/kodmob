<?php

namespace App\Modules\Records;

use App\Core\Activity\Activity;
use App\Core\Content\ContentType;
use App\Core\Content\Types;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Http\Controller;
use App\Core\Security\Capability;
use App\Core\Support\Clock;
use App\Core\Support\UserNames;
use App\Modules\Blog\BlogPosts;
use App\Modules\Briefs\Service\PurgeBriefs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Genel "Sil" ve "Silinenler" (panelde
 * ui/records/records.js ve ortak tablo kullanır):
 *
 *   GET  /records/plan?type=&id=       silme penceresi: başlık, engeller, notlar, kalıcı mı
 *   POST /records/hide { type, id }    sil (içerik çöpe gider; talep kalıcı silinir)
 *   POST /records/restore { type, id } geri yükle
 *   GET  /records/hidden?type=         bu türün silinen kayıtları
 *
 * Yetki türe göredir: içerik silmek `km_delete_content` + türün düzenleme yetkisi,
 * talep `km_delete_briefs`; Silinenler ve geri yükleme `km_restore_records`.
 */
final class RecordsController extends Controller
{
    /**
     * tür => [etiket, tablo, yönetim yetkisi, kalıcı mı]. İçerik türleri kayıttan (Types), talep ayrıca.
     *
     * @return array<string,array{0:string,1:string,2:string,3:bool}>
     */
    private static function types(): array
    {
        $types = [];
        foreach (Types::editable() as $content) {
            $types[$content->recordKind()] = [$content->labels()[1], $content instanceof ContentType ? 'contents' : 'posts', $content->capability(), false];
        }

        return $types + ['brief' => ['Talep', 'briefs', Capability::VIEW_BRIEFS, true]];
    }

    public function plan(Request $request): array
    {
        [$type, $id] = $this->target($request->query());
        $this->authorizeHide($request, $type);
        $row = $this->row($type, $id, false);
        [$label, , , $permanent] = self::types()[$type];
        $notes = [];
        if ($type === 'post' && ! empty($row['translation_group'])) {
            $others = DB::table('posts')->where('translation_group', $row['translation_group'])->where('id', '<>', $id)->whereNull('deleted_at')->count();
            if ($others) {
                $notes[] = "Diğer dillerdeki {$others} çevirisi silinmez.";
            }
        }
        if (Types::byKind($type) !== null && ($row['status'] ?? '') === 'publish') {
            $notes[] = 'Sitede yayından kalkar.';
        }
        if ($permanent) {
            $notes[] = 'Talep sahibinin kişisel verisi silinir (KVKK silme talebi).';
        }

        return [
            'type' => $type,
            'id' => $id,
            'title' => $this->title($type, $row) ?: "{$label} #{$id}",
            'blockers' => [],
            'groups' => [],
            'options' => [],
            'notes' => $notes,
            'permanent' => $permanent,
        ];
    }

    public function hide(Request $request): array
    {
        [$type, $id] = $this->target($this->input($request));
        $this->authorizeHide($request, $type);
        $row = $this->row($type, $id, false);
        $title = $this->title($type, $row);

        if ($type === 'brief') {
            (new PurgeBriefs)->one($id); // olayı (brief.deleted) servis yazar
            Activity::skip();

            return ['permanent' => true, 'linked' => 0, 'message' => 'Talep kalıcı olarak silindi.'];
        }
        $this->handler($type)->delete($id);
        Activity::type($type.'.deleted');
        Activity::subject($type, $id, $title);

        return ['permanent' => false, 'linked' => 0];
    }

    public function restore(Request $request): array
    {
        [$type, $id] = $this->target($this->input($request));
        if (self::types()[$type][3]) {
            throw new DomainException('km_record_permanent', 'Bu kayıt kalıcı silinir; geri yüklenemez.', 409);
        }
        $row = $this->row($type, $id, true);
        if (! $this->handler($type)->untrash($id)) {
            throw NotFoundException::message('Silinen kayıt bulunamadı.');
        }
        Activity::type('records.restored');
        Activity::subject($type, $id, $this->title($type, $row));
        Activity::with(['type_label' => self::types()[$type][0]]);

        return ['restored' => true];
    }

    public function hidden(Request $request): array
    {
        $type = $this->type((string) $request->query('type'));
        if (self::types()[$type][3]) {
            return ['items' => []];
        }
        $query = DB::table(self::types()[$type][1])->whereNotNull('deleted_at')->orderByDesc('deleted_at')->limit(200);
        if (self::types()[$type][1] === 'contents') {
            $query->where('type', $this->handler($type)->postType());
        }
        $rows = $query->get()->map(fn ($r) => (array) $r)->all();
        $names = UserNames::map(array_column($rows, 'deleted_by'));

        return ['items' => array_map(fn (array $r) => [
            'id' => (int) $r['id'],
            'object_type' => $type,
            'object_id' => (int) $r['id'],
            'label' => $this->title($type, $r),
            'hidden_at' => Clock::local((string) $r['deleted_at']),
            'hidden_by_name' => $r['deleted_by'] ? ($names[(int) $r['deleted_by']] ?? null) : null,
            'linked' => 0,
        ], $rows)];
    }

    // ------------------------------------------------------------ yardımcılar

    /** @return array{0:string,1:int} */
    private function target(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            throw NotFoundException::message('Kayıt belirtilmedi.');
        }

        return [$this->type((string) ($input['type'] ?? '')), $id];
    }

    private function type(string $type): string
    {
        // Panel adresindeki tür (projects) de kabul edilir → kayıt türü (project).
        $type = (Types::editable()[$type] ?? null)?->recordKind() ?? ($type === 'briefs' ? 'brief' : $type);
        if (! isset(self::types()[$type])) {
            throw NotFoundException::message('Bu tür kayıt silinemez.');
        }

        return $type;
    }

    private function authorizeHide(Request $request, string $type): void
    {
        $user = $request->user();
        $allowed = $type === 'brief'
            ? $user->can(Capability::DELETE_BRIEFS)
            : $user->can(Capability::DELETE_CONTENT) && $user->can(self::types()[$type][2]);
        if (! $allowed) {
            throw new DomainException('km_forbidden', 'Bu kaydı silme yetkiniz yok.', 403, ['capability' => $type === 'brief' ? Capability::DELETE_BRIEFS : Capability::DELETE_CONTENT]);
        }
    }

    /** @return array<string,mixed> */
    private function row(string $type, int $id, bool $deleted): array
    {
        [$label, $table] = self::types()[$type];
        $query = DB::table($table)->where('id', $id);
        if ($table === 'contents') {
            $query->where('type', $this->handler($type)->postType());
        }
        if ($table !== 'briefs') {
            $deleted ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');
        }
        $row = $query->first();
        if (! $row) {
            throw NotFoundException::message($deleted ? "Silinen {$label} bulunamadı." : "{$label} bulunamadı. Silinmiş olabilir.");
        }

        return (array) $row;
    }

    private function title(string $type, array $row): string
    {
        return (string) match ($type) {
            'brief' => $row['name'] ?? '',
            default => $row['title'] ?? '',
        };
    }

    private function handler(string $type): ContentType|BlogPosts
    {
        return Types::byKind($type) ?? throw NotFoundException::message('Bu tür kayıt silinemez.');
    }
}
