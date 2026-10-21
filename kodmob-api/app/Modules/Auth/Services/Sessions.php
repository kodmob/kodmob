<?php

namespace App\Modules\Auth\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Support\Clock;
use App\Core\Support\Options;
use App\Core\Support\UserAgent;
use App\Core\Support\UserNames;
use App\Modules\Security\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Açık oturumlar (cihazlar): Profilim > Oturumlarım ve Güvenlik > Oturumlar.
 * jwt-kodmob'daki "Sessions" ekranının karşılığı; oturum kimliği olarak
 * `refresh_tokens.id` kullanılır (yenilemede değişmez, jeton değildir).
 */
final class Sessions
{
    /**
     * @param  int|null  $userId  null: herkesin oturumları
     * @return list<array>
     */
    public static function list(?int $userId = null, string $currentSid = ''): array
    {
        $query = DB::table('refresh_tokens')->where('expires_at', '>', Clock::now())
            ->orderByDesc('last_seen_at')->orderByDesc('id');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        $rows = $query->limit(500)->get();
        $names = UserNames::map($rows->pluck('user_id'));

        return $rows->map(static fn ($r) => [
            'id' => (int) $r->id,
            'user_id' => (int) $r->user_id,
            'user_name' => $names[(int) $r->user_id] ?? null,
            'device' => UserAgent::describe((string) $r->user_agent),
            'user_agent' => (string) $r->user_agent,
            'ip' => (string) $r->ip,
            'started_at' => Clock::local((string) $r->started_at),
            'last_seen_at' => $r->last_seen_at ? Clock::local((string) $r->last_seen_at) : null,
            'expires_at' => Clock::local((string) $r->expires_at),
            'current' => $currentSid !== '' && hash_equals((string) $r->session_id, $currentSid),
        ])->all();
    }

    /** @return array{id:int,user_id:int,device:string,ip:string} kapatılan oturum */
    public static function end(int $id, ?int $userId = null): array
    {
        $query = DB::table('refresh_tokens')->where('id', $id);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        $row = $query->first();
        if (! $row) {
            throw NotFoundException::message('Oturum bulunamadı ya da zaten kapanmış.');
        }
        DB::table('refresh_tokens')->where('id', $id)->delete();

        return ['id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'device' => UserAgent::describe((string) $row->user_agent), 'ip' => (string) $row->ip];
    }

    /** Kullanıcının (ya da herkesin) oturumları; `$exceptSid` açık kalır. */
    public static function endAll(?int $userId = null, string $exceptSid = ''): int
    {
        $query = DB::table('refresh_tokens');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if ($exceptSid !== '') {
            $query->where('session_id', '<>', $exceptSid);
        }

        return $query->delete();
    }

    public static function count(?int $userId = null): int
    {
        $query = DB::table('refresh_tokens')->where('expires_at', '>', Clock::now());
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->count();
    }

    /**
     * İmza anahtarını yeniler: bütün erişim jetonları geçersiz, bütün oturumlar
     * kapanır (yenileme jetonları da silinir). Anahtarın sızdığından şüphelenince.
     */
    public static function rotateKey(): array
    {
        $version = Jwt::version() + 1;
        Options::set(Jwt::VERSION_OPTION, $version);
        $ended = self::endAll();
        DB::table('revoked_tokens')->delete();
        app()->forgetInstance(Jwt::class);
        SecurityLog::record(SecurityLog::KEY_ROTATED, null, 'Sürüm '.$version.' · '.$ended.' oturum kapandı');

        return ['version' => $version, 'ended' => $ended, 'fingerprint' => app(Jwt::class)->fingerprint()];
    }
}
