<?php

namespace App\Modules\Members;

use App\Core\Exceptions\DomainException;
use App\Core\Http\Controller;
use App\Core\Push\PushSubscriptions;
use App\Core\Push\VapidKeys;
use App\Core\Support\Clock;
use App\Modules\Commerce\CommerceText;
use App\Modules\Commerce\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Site üyeliği uçları (ziyaretçi; oturum `km_member` çerezinde). Yanıtlar saklanmaz (no-store).
 *
 *   GET  /members/me                    { member | null, google }
 *   POST /members/register | login      → { member } + çerez
 *   POST /members/logout
 *   POST /members/verify  { token }     · POST /members/verify/resend
 *   POST /members/forgot  { email }     · POST /members/reset { token, password }
 *   PUT  /members/me      { name }      · POST /members/password { current, password }
 *   POST /members/delete  { password, confirm }
 *   GET  /members/orders                doğrulanmış e-postanın siparişleri
 *   GET  /members/google  → Google      · GET /members/google/callback → /hesap/
 *   POST /members/push/subscribe | unsubscribe   üyenin anlık bildirim aboneliği
 */
final class MembersController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $member = Members::current($request);

        return $this->json([
            'member' => $member ? Members::present($member) : null,
            'google' => GoogleLogin::enabled(),
            // Sipariş bildirimleri (anlık bildirim): sitenin açık anahtarı.
            'push_key' => $member ? VapidKeys::publicKey() : null,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $member = Members::register($this->input($request), $request);

        return $this->json(['member' => Members::present($member)], Members::startSession($member['id'], $request));
    }

    public function login(Request $request): JsonResponse
    {
        $member = Members::login($this->input($request));

        return $this->json(['member' => Members::present($member)], Members::startSession($member['id'], $request));
    }

    public function logout(Request $request): JsonResponse
    {
        return $this->json(['ok' => true], Members::endSession($request));
    }

    public function verify(Request $request): JsonResponse
    {
        $member = Members::verify((string) ($this->input($request)['token'] ?? ''));
        $current = Members::current($request);

        return $this->json(['member' => $current && $current['id'] === $member['id'] ? Members::present($member) : null]);
    }

    public function resend(Request $request): JsonResponse
    {
        Members::sendVerification($this->member($request)['id']);

        return $this->json(['ok' => true]);
    }

    public function forgot(Request $request): JsonResponse
    {
        Members::forgot((string) ($this->input($request)['email'] ?? ''));

        return $this->json(['ok' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $body = $this->input($request);
        $member = Members::reset((string) ($body['token'] ?? ''), (string) ($body['password'] ?? ''));

        return $this->json(['member' => Members::present($member)], Members::startSession($member['id'], $request));
    }

    public function update(Request $request): JsonResponse
    {
        return $this->json(['member' => Members::present(Members::updateProfile($this->member($request), $this->input($request)))]);
    }

    public function password(Request $request): JsonResponse
    {
        $body = $this->input($request);
        Members::changePassword($this->member($request), (string) ($body['current'] ?? ''), (string) ($body['password'] ?? ''));

        return $this->json(['ok' => true]);
    }

    public function delete(Request $request): JsonResponse
    {
        Members::delete($this->member($request), $this->input($request));

        return $this->json(['ok' => true], Members::cookie('', $request, true));
    }

    /** Siparişlerim: yalnızca doğrulanmış e-postanın siparişleri (Mağaza açıksa). */
    public function orders(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (! $member['verified'] || ! DB::getSchemaBuilder()->hasTable('commerce_orders')) {
            return $this->json(['items' => []]);
        }
        $items = DB::table('commerce_orders')->where('customer_email', $member['email'])->orderByDesc('id')->limit(50)->get()
            ->map(static fn ($o) => [
                'number' => (string) $o->number, 'date' => (string) Clock::local((string) $o->created_at), 'status' => (string) $o->status,
                'statusLabel' => CommerceText::get('status.'.$o->status, $member['locale'] ?: 'tr'), 'total' => Money::format((int) $o->total, (string) $o->currency), 'url' => null,
            ])->all();

        return $this->json(['items' => $items]);
    }

    public function google(Request $request): RedirectResponse
    {
        if (! GoogleLogin::enabled()) {
            return redirect(Members::pageUrl((string) $request->query('locale', ''), ['google' => 'hata']));
        }
        $state = bin2hex(random_bytes(16)).'.'.preg_replace('/[^a-z-]/', '', (string) $request->query('locale', ''));

        return redirect(GoogleLogin::authorizeUrl($state))
            ->withCookie(new Cookie(GoogleLogin::STATE_COOKIE, $state, now()->addMinutes(10), '/api/v1/members/google', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $locale = (string) (explode('.', $state)[1] ?? '');
        $forget = new Cookie(GoogleLogin::STATE_COOKIE, '', 1, '/api/v1/members/google', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
        if ($state === '' || ! hash_equals((string) $request->cookie(GoogleLogin::STATE_COOKIE, ''), $state) || ! $request->query('code')) {
            return redirect(Members::pageUrl($locale, ['google' => 'hata']))->withCookie($forget);
        }
        try {
            $google = GoogleLogin::exchange((string) $request->query('code'));
            $member = Members::fromGoogle($google['sub'], $google['email'], $google['name'], $locale);
        } catch (Throwable $e) {
            Log::warning('[Kodmob üyelik] Google: '.$e->getMessage());

            return redirect(Members::pageUrl($locale, ['google' => 'hata']))->withCookie($forget);
        }

        return redirect(Members::pageUrl($locale))->withCookie($forget)->withCookie(Members::startSession($member['id'], $request));
    }

    public function pushSubscribe(Request $request): JsonResponse
    {
        PushSubscriptions::save('member', $this->member($request)['id'], $this->input($request), (string) $request->userAgent());

        return $this->json(['subscribed' => true]);
    }

    public function pushUnsubscribe(Request $request): JsonResponse
    {
        $endpoint = trim((string) ($this->input($request)['endpoint'] ?? ''));
        PushSubscriptions::remove('member', $this->member($request)['id'], $endpoint !== '' ? $endpoint : null);

        return $this->json(['subscribed' => false]);
    }

    /** Oturumdaki üye; yoksa 401. */
    private function member(Request $request): array
    {
        return Members::current($request) ?? throw new DomainException('km_member_unauthenticated', 'Oturumunuz kapanmış. Yeniden giriş yapın.', 401);
    }

    private function json(array $data, ?Cookie $cookie = null): JsonResponse
    {
        $response = $this->noStore($data);

        return $cookie ? $response->withCookie($cookie) : $response;
    }
}
