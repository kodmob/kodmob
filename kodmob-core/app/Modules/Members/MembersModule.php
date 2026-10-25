<?php

namespace App\Modules\Members;

use App\Core\Database\BackupPart;
use App\Core\Events\EventRecorded;
use App\Core\Module;
use App\Core\Push\SendPush;
use App\Core\Settings\SettingDefinition;
use App\Core\Support\Clock;
use App\Modules\Commerce\CommerceText;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Site üyeliği (isteğe bağlı araç `members`): ziyaretçi üye olur, girer, siparişlerini görür, hesabını siler; Google
 * ile giriş isteğe bağlı. Site sayfası `/hesap/` (kodmob-frontend pages/account), uçlar MembersController, panelde
 * Gelenler › Üyeler (AdminMembersController). Sipariş durumu değişince üyenin anlık bildirim açık cihazlarına gider.
 */
final class MembersModule extends Module
{
    public const VIEW = 'km_view_members';

    public const MANAGE = 'km_manage_members';

    public function capabilities(): array
    {
        return [
            self::VIEW => ['label' => 'Üyeleri görme', 'group' => 'Üyelik'],
            self::MANAGE => ['label' => 'Üyeleri yönetme (kapatma, silme)', 'group' => 'Üyelik'],
        ];
    }

    public function settings(): array
    {
        return [
            new SettingDefinition(GoogleLogin::CLIENT_ID, 'string', '', 'Google istemci kimliği', 'Üyelik',
                'Google ile giriş için (Google Cloud Console › Kimlik bilgileri › OAuth istemcisi, Web uygulaması). Yetkili yönlendirme adresi: <site>/api/v1/members/google/callback. Boşsa yalnızca e-posta ile üyelik.'),
            new SettingDefinition(GoogleLogin::CLIENT_SECRET, 'string', '', 'Google istemci sırrı', 'Üyelik',
                'OAuth istemcisinin sırrı; mühürlü saklanır, bir daha gösterilmez.', null, null, '', [], 'secret'),
        ];
    }

    /** Üyeler kişisel veri: indirilen yedeğe ve geri yüklemeye ayrıca seçilerek girer. Oturumlar ve anahtarlar girmez. */
    public function backup(): array
    {
        return [new BackupPart('members', 'Site üyeleri', ['members'], personal: true)];
    }

    public function widgets(): array
    {
        return [
            ['key' => 'members', 'label' => 'Üyeler', 'icon' => 'users', 'type' => 'stats', 'size' => 'sm', 'order' => 45, 'can' => self::VIEW,
                'link' => ['route' => 'members', 'label' => 'Tümü'],
                'data' => static fn () => ['items' => [
                    ['label' => 'Toplam üye', 'value' => DB::table('members')->count(), 'route' => 'members'],
                    ['label' => 'Son 7 günde', 'value' => DB::table('members')->where('created_at', '>=', now()->subDays(7)->format('Y-m-d H:i:s'))->count(), 'sub' => 'yeni üye'],
                ]]],
        ];
    }

    public function boot(): void
    {
        // Sipariş durumu değişti: siparişin e-postası doğrulanmış bir üyeninse cihazlarına anlık bildirim.
        Event::listen(EventRecorded::class, static function (EventRecorded $recorded) {
            $event = $recorded->event;
            if ($event->type !== 'commerce.order_status' || ! DB::getSchemaBuilder()->hasTable('commerce_orders')) {
                return;
            }
            $order = DB::table('commerce_orders')->where('id', $event->subjectId)->first();
            $member = $order ? DB::table('members')->where('email', $order->customer_email)->whereNotNull('email_verified_at')->first() : null;
            if ($member) {
                $locale = (string) ($member->locale ?: 'tr');
                SendPush::to('member', (int) $member->id, [
                    'title' => CommerceText::get('status.'.$order->status, $locale).' · '.$order->number,
                    'body' => mb_substr((string) $order->customer_name, 0, 80),
                    'url' => parse_url(Members::pageUrl($locale), PHP_URL_PATH) ?: '/',
                    'tag' => 'order-'.$order->id,
                ]);
            }
        });
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static function () {
            DB::table('member_sessions')->where('expires_at', '<', Clock::now())->delete();
            DB::table('member_tokens')->where('expires_at', '<', Clock::now())->delete();
            // Doğrulanmamış, hiç giriş yapılmamış ve 30 günü geçen kayıtlar (yanlış ya da başkasının adresi).
            DB::table('members')->whereNull('email_verified_at')->whereNull('google_id')->whereNull('last_login_at')
                ->where('created_at', '<', now()->subDays(30)->format('Y-m-d H:i:s'))->delete();
        })->name('members:prune')->dailyAt('03:40');
    }
}
