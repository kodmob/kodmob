<?php

namespace App\Modules\Security;

use App\Core\Activity\Activity;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Http\ListQuery;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use App\Core\Support\UserNames;
use App\Modules\Auth\Services\Jwt;
use App\Modules\Auth\Services\LoginThrottle;
use App\Modules\Auth\Services\Sessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Güvenlik sayfası (`km_manage_security`):
 *
 *   GET    /security                    göstergeler + denetim + anahtar parmak izi
 *   GET    /security/events             güvenlik kayıtları (ortak tablo: type, ip, tarih, q)
 *   GET    /security/sessions           herkesin açık oturumları
 *   DELETE /security/sessions/{id}      bir oturumu kapat
 *   POST   /security/sessions/end-all   bu cihaz dışındaki bütün oturumları kapat
 *   POST   /security/key/rotate         imza anahtarını yenile (herkes yeniden girer)
 *   GET    /security/blocks             IP engelleri
 *   POST   /security/blocks             { ip, reason, hours? } engelle
 *   DELETE /security/blocks/{id}        engeli kaldır
 *   GET    /security/lockouts           kilitli girişler
 *   POST   /security/lockouts/unlock    { username, ip } kilidi aç
 */
final class SecurityController extends Controller
{
    public function overview(): array
    {
        return [
            'stats' => SecurityAudit::stats(),
            'audit' => SecurityAudit::run(),
            'key' => ['version' => Jwt::version(), 'fingerprint' => app(Jwt::class)->fingerprint()],
            'types' => array_map(static fn ($key, $t) => ['value' => $key, 'label' => $t['label'], 'tone' => $t['tone']], array_keys(SecurityLog::types()), SecurityLog::types()),
        ];
    }

    public function events(Request $request): array
    {
        return (new SecurityEventRepository)->page(ListQuery::fromRequest($request, 50))->toArray();
    }

    public function sessions(Request $request): array
    {
        return ['items' => Sessions::list(null, $this->sid($request))];
    }

    public function endSession(int $id): array
    {
        $ended = Sessions::end($id);
        Activity::type('sessions.ended');
        Activity::subject('session', $id, trim(UserNames::of($ended['user_id']).' · '.$ended['device'], ' ·'));
        Activity::with(['device' => $ended['device'], 'ip' => $ended['ip'], 'user_id' => $ended['user_id']]);

        return ['ended' => 1];
    }

    public function endAll(Request $request): array
    {
        $count = Sessions::endAll(null, $this->sid($request));
        Activity::type('sessions.ended_all');
        Activity::subject('security', 0);
        Activity::with(['count' => $count]);

        return ['ended' => $count];
    }

    public function rotateKey(): array
    {
        $result = Sessions::rotateKey();
        Activity::type('security.key_rotated');
        Activity::subject('security', 0);
        Activity::with(['version' => $result['version'], 'sessions' => $result['ended']]);

        return $result;
    }

    public function blocks(): array
    {
        $rows = DB::table('ip_blocks')->orderByDesc('created_at')->limit(500)->get();
        $names = UserNames::map($rows->pluck('created_by'));
        $now = Clock::now();

        return ['items' => $rows->map(static fn ($r) => [
            'id' => (int) $r->id,
            'ip' => (string) $r->ip,
            'reason' => (string) $r->reason,
            'source' => (string) $r->source,
            'source_label' => $r->source === 'auto' ? 'Otomatik' : 'Elle',
            'expires_at' => $r->expires_at ? Clock::local((string) $r->expires_at) : null,
            'expired' => $r->expires_at !== null && (string) $r->expires_at < $now,
            'hits' => (int) $r->hits,
            'last_hit_at' => $r->last_hit_at ? Clock::local((string) $r->last_hit_at) : null,
            'created_by_name' => $r->created_by ? ($names[(int) $r->created_by] ?? null) : null,
            'created_at' => Clock::local((string) $r->created_at),
        ])->all()];
    }

    public function block(Request $request): JsonResponse
    {
        $input = $this->input($request);
        $ip = Firewall::normalize((string) ($input['ip'] ?? ''));
        if (IpUtils::checkIp((string) $request->ip(), $ip)) {
            throw ValidationException::field('ip', 'Kendi IP adresinizi engelleyemezsiniz.');
        }
        $hours = (int) ($input['hours'] ?? 0);
        $row = Firewall::block($ip, Text::field($input['reason'] ?? ''), 'manual', $hours > 0 ? min($hours, 24 * 365) : null, (int) $request->user()->id);
        Activity::type('security.ip_blocked');
        Activity::subject('ip_block', (int) $row['id'], $ip);
        Activity::with(['ip' => $ip, 'reason' => (string) $row['reason'], 'hours' => $hours ?: null, 'fields' => []]);

        return $this->created(['id' => (int) $row['id'], 'ip' => $ip]);
    }

    public function unblock(int $id): Response
    {
        $row = Firewall::unblock($id) ?? throw NotFoundException::message('Engel bulunamadı.');
        Activity::type('security.ip_unblocked');
        Activity::subject('ip_block', $id, (string) $row['ip']);
        Activity::with(['ip' => (string) $row['ip']]);

        return $this->noContent();
    }

    public function lockouts(): array
    {
        return ['items' => array_map(static fn ($l) => $l + ['last_at_local' => Clock::local($l['last_at'])], (new LoginThrottle)->lockouts())];
    }

    public function unlock(Request $request): array
    {
        $input = $this->input($request);
        $username = Text::field($input['username'] ?? '');
        $ip = Firewall::normalize((string) ($input['ip'] ?? ''));
        (new LoginThrottle)->unlock($username, $ip);
        Activity::type('security.login_unlocked');
        Activity::subject('security', 0, trim($username.' · '.$ip, ' ·'));
        Activity::with(['username' => $username, 'ip' => $ip, 'fields' => []]);

        return ['unlocked' => true];
    }

    private function sid(Request $request): string
    {
        return (string) ($request->attributes->get('jwt')['sid'] ?? '');
    }
}
