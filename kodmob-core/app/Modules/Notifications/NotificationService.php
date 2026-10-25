<?php

namespace App\Modules\Notifications;

use App\Core\Security\Roles;
use App\Core\Support\Clock;
use App\Core\Support\PanelUrl;
use App\Core\Support\Text;
use App\Modules\Live\PulseFile;
use Illuminate\Support\Facades\DB;

/**
 * Panel bildirimleri, kullanıcı başına (her kullanıcının kendi okundu durumu).
 * Bildirim, işin yetkisi olan kullanıcılara gider:
 *
 *     NotificationService::notifyCapable(Capability::USE_DOMAINS, 'jarvis.job_finished',
 *         'Domain taraması bitti', '18 boş ad bulundu', PanelUrl::to('domains', ['job' => 12]), 'success');
 *
 * Her yazma nabzı (`notification`) dokunur: açık paneller zili hemen günceller.
 */
final class NotificationService
{
    public const KEEP_DAYS = 90;

    private const LIST_LIMIT = 50;

    private const TONES = ['primary', 'success', 'warning', 'danger', 'info', 'neutral'];

    /** Yetkisi olan bütün aktif kullanıcılara (ör. yeni talep → `km_view_briefs`). */
    public static function notifyCapable(string $capability, string $type, string $title, string $message = '', string $url = '', string $tone = 'primary', array $data = []): void
    {
        $service = new self;
        $roles = array_keys(array_filter(Roles::all(), static fn (array $r) => in_array($capability, $r['caps'], true)));
        if (! $roles) {
            return;
        }
        foreach (DB::table('users')->whereIn('role', $roles)->where('status', 'active')->pluck('id') as $userId) {
            $service->create((int) $userId, $type, $title, $message, $url, $tone, $data);
        }
    }

    public function create(int $userId, string $type, string $title, string $message = '', string $url = '', string $tone = 'primary', array $data = []): int
    {
        $id = (int) DB::table('notifications')->insertGetId([
            'user_id' => $userId,
            'type' => substr($type, 0, 60),
            'tone' => in_array($tone, self::TONES, true) ? $tone : 'primary',
            'title' => mb_substr($title, 0, 190),
            'message' => $message !== '' ? mb_substr($message, 0, 500) : null,
            'url' => $url !== '' ? substr($url, 0, 255) : null,
            'data' => $data ? Text::json($data) : null,
            'is_read' => 0,
            'created_at' => Clock::now(),
        ]);
        PulseFile::touch('notification');

        return $id;
    }

    /** @return array{items:list<array>,unread:int} */
    public function forUser(int $userId, int $limit = 30): array
    {
        $rows = DB::table('notifications')->where('user_id', $userId)->orderByDesc('id')->limit(max(1, min(self::LIST_LIMIT, $limit)))->get();

        return [
            'items' => $rows->map(fn ($row) => $this->present((array) $row))->all(),
            'unread' => $this->unread($userId),
        ];
    }

    public function unread(int $userId): int
    {
        return DB::table('notifications')->where('user_id', $userId)->where('is_read', 0)->count();
    }

    /** @param list<int>|null $ids null → hepsi */
    public function markRead(int $userId, ?array $ids = null): int
    {
        $query = DB::table('notifications')->where('user_id', $userId)->where('is_read', 0);
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (! $ids) {
                return 0;
            }
            $query->whereIn('id', $ids);
        }
        $count = $query->update(['is_read' => 1, 'read_at' => Clock::now()]);
        if ($count > 0) {
            PulseFile::touch('notification');
        }

        return $count;
    }

    /** Günlük: KEEP_DAYS'ten eski bildirimler silinir. */
    public function prune(): int
    {
        return DB::table('notifications')->where('created_at', '<', now()->subDays(self::KEEP_DAYS)->format('Y-m-d H:i:s'))->delete();
    }

    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'tone' => $row['tone'],
            'title' => $row['title'],
            'message' => (string) $row['message'],
            // Eski kayıtlar `#/…` saklıyordu (göç çevirir; kaçan olursa burada).
            'url' => (string) PanelUrl::normalize((string) $row['url']),
            'data' => $row['data'] ? json_decode($row['data'], true) : null,
            'is_read' => (bool) $row['is_read'],
            'created_at' => Clock::local($row['created_at']),
        ];
    }
}
