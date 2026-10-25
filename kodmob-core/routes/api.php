<?php

use App\Core\Content\Types;
use App\Core\Extensions\Extensions;
use App\Core\Setup\SetupController;
use App\Modules\Auth\Http\AuthController;
use App\Modules\Auth\Http\PreferencesController;
use App\Modules\Auth\Http\ProfileController;
use App\Modules\Briefs\BriefsController;
use App\Modules\Commerce\Booking\BookingsController;
use App\Modules\Commerce\Http\OrdersController;
use App\Modules\Commerce\Http\ShopController;
use App\Modules\Commerce\Paytr\PaytrCallback;
use App\Modules\Commerce\Stripe\StripeWebhook;
use App\Modules\Content\Http\AdminContentController;
use App\Modules\Content\Http\BackupController;
use App\Modules\Content\Http\PagesController;
use App\Modules\Domains\Http\DomainsController;
use App\Modules\History\HistoryController;
use App\Modules\I18n\Http\LanguagesController;
use App\Modules\Jarvis\Http\AssistantController;
use App\Modules\Jarvis\Http\JarvisController;
use App\Modules\Live\Http\ChangesController;
use App\Modules\Media\MediaController;
use App\Modules\Newsletter\Http\NewsletterAdminController;
use App\Modules\Newsletter\Http\NewsletterController;
use App\Modules\Notifications\NotificationController;
use App\Modules\Records\RecordsController;
use App\Modules\Security\SecurityController;
use App\Modules\Seo\Http\SeoController;
use App\Modules\Seo\Services\SeoMeta;
use App\Modules\Site\Http\LayoutsController;
use App\Modules\Site\Http\LegalController;
use App\Modules\System\AiController;
use App\Modules\System\BackgroundController;
use App\Modules\System\ContractsController;
use App\Modules\System\HealthController;
use App\Modules\System\SearchController;
use App\Modules\System\SitesController;
use App\Modules\System\SmsController;
use App\Modules\System\SystemController;
use App\Modules\System\ToolsController;
use App\Modules\Users\MessageController;
use App\Modules\Users\RolesController;
use App\Modules\Users\UsersController;
use Illuminate\Support\Facades\Route;

/*
 * Bütün uçlar `/v1` altında; ön yüz `/api/v1/...` adresini kullanır
 * (kodmob-frontend/.env*: VITE_BASE_URL=/api, VITE_API_BASE_PATH=/v1, VITE_AUTH_BASE_PATH=/v1/auth).
 * Uç adları ve yanıt biçimleri WordPress sürümüyle (kodmob/v1, jwt-kodmob/v1) aynıdır.
 *
 * Panel uçları:
 * - `auth.jwt`: oturum (TokenService); pasif kullanıcı ve kapatılan oturum hemen düşer.
 * - `activity`: her başarılı yazma isteği İşlem Geçmişi'ne yazılır (LogActivity). Rota adı
 *   olay türüdür (`media.uploaded`); `->defaults('activity', false)` kaydı kapatır.
 * - `cap:km_x|km_y`: yetki (Core\Security\Capability); biri yeterlidir. Türe göre değişen
 *   yetkiler (içerik türü, Jarvis becerisi, ayar grubu) controller'da ayrıca denetlenir.
 * - `module:briefs`: isteğe bağlı modül (Core\Modules; config/kodmob.php 'modules'); kapalıysa 404.
 *   Kapalı modülün yetkileri de yoktur, yani cap: ile korunan uçlar ayrıca kapanır.
 */
// İçerik uçları: türlerden birinin yetkisi yeterli (tür ayrıca controller'da denetlenir; Types).
$content = 'cap:'.Types::capabilities();

Route::prefix('v1')->group(function () use ($content) {
    Route::get('/', fn () => ['name' => 'kodmob', 'version' => 'v1']);

    // ---------------------------------------------------------------- kurulum
    // Sınırlayıcı yok: kurulumdan önce önbellek/veritabanı hazır olmayabilir. Uç
    // SETUP_TOKEN ile korunur ve yönetici varsa yalnızca tabloları günceller.
    Route::match(['get', 'post'], 'setup', SetupController::class);

    // ---------------------------------------------------------------- oturum
    Route::prefix('auth')->group(function () {
        Route::post('token', [AuthController::class, 'token']);
        Route::post('token/refresh', [AuthController::class, 'refresh'])->middleware('throttle:60,1');
        Route::post('token/revoke', [AuthController::class, 'revoke'])->middleware('auth.jwt');
        // Projeler arası geçiş: ana projenin verdiği tek kullanımlık anahtar (Auth\Services\Handoff).
        Route::post('handoff', [AuthController::class, 'handoff'])->middleware('throttle:10,1');
    });

    // ---------------------------------------------------------------- ziyaretçi
    Route::get('site', [SystemController::class, 'site']);
    Route::get('theme.css', [SystemController::class, 'theme'])->middleware('throttle:120,1');
    Route::get('page-data', [PagesController::class, 'pageData'])->middleware('throttle:120,1');
    Route::get('search-index', [PagesController::class, 'searchIndex'])->middleware('throttle:60,1');
    Route::get('suggest', [PagesController::class, 'suggest'])->middleware('throttle:30,1');
    Route::post('briefs', [BriefsController::class, 'store'])->middleware(['module:briefs', 'throttle:20,1']);
    // Bülten (isteğe bağlı araç; Modules\Newsletter): çift onaylı abonelik, onay ve abonelikten çıkma bağlantısı.
    Route::prefix('newsletter')->middleware('module:newsletter')->group(function () {
        Route::post('/', [NewsletterController::class, 'subscribe'])->middleware('throttle:10,1');
        Route::post('confirm', [NewsletterController::class, 'confirm'])->middleware('throttle:30,1');
        Route::post('unsubscribe', [NewsletterController::class, 'unsubscribe'])->middleware('throttle:30,1');
    });

    // Mağaza (isteğe bağlı modül; Modules\Commerce): sepet, ödeme adımı, müşterinin siparişi. Sepet anahtarı
    // HttpOnly çerezde; fiyat, stok ve kupon her adımda sunucuda hesaplanır.
    Route::prefix('shop')->middleware('module:commerce')->group(function () {
        Route::get('cart', [ShopController::class, 'cart'])->middleware('throttle:120,1');
        Route::post('cart/items', [ShopController::class, 'add'])->middleware('throttle:60,1');
        $line = '[a-z]+:[0-9]+(:[0-9a-f]{12})?';
        Route::patch('cart/items/{line}', [ShopController::class, 'update'])->where('line', $line)->middleware('throttle:60,1');
        Route::delete('cart/items/{line}', [ShopController::class, 'remove'])->where('line', $line)->middleware('throttle:60,1');
        Route::post('cart/coupon', [ShopController::class, 'coupon'])->middleware('throttle:20,1');
        Route::post('checkout', [ShopController::class, 'checkout'])->middleware('throttle:10,1');
        Route::get('orders/{number}', [ShopController::class, 'order'])->where('number', '[A-Z0-9-]{3,30}')->middleware('throttle:30,1');
        Route::post('orders/{number}/pay', [ShopController::class, 'pay'])->where('number', '[A-Z0-9-]{3,30}')->middleware('throttle:10,1');
        // Rezervasyon (Mağaza'ya takılı modül): tarih seçici ve fiyat dökümü; sepete ekleme yukarıdaki uçla (type: booking).
        Route::get('bookings/{tour}/availability', [BookingsController::class, 'availability'])->whereNumber('tour')->middleware(['module:booking', 'throttle:60,1']);
        Route::post('bookings/quote', [BookingsController::class, 'quote'])->middleware(['module:booking', 'throttle:60,1']);
        // Online ödeme sağlayıcılarının bildirimleri (Mağaza'ya takılı modüller; imza / hash denetimli).
        Route::post('payments/stripe', StripeWebhook::class)->middleware(['module:stripe', 'throttle:120,1']);
        Route::post('payments/paytr', PaytrCallback::class)->middleware(['module:paytr', 'throttle:120,1']);
    });

    // ---------------------------------------------------------------- panel
    Route::middleware(['auth.jwt', 'activity', 'cap:km_access_panel'])->group(function () use ($content) {
        // Herkes: profil, oturumlarım, bildirimler, kendi işlemleri
        Route::get('me', [ProfileController::class, 'me']);
        Route::put('me', [ProfileController::class, 'update'])->name('profile.updated');
        Route::put('me/password', [ProfileController::class, 'password'])->name('profile.password_changed');
        Route::get('me/sessions', [ProfileController::class, 'sessions']);
        Route::delete('me/sessions/{id}', [ProfileController::class, 'endSession'])->whereNumber('id')->name('sessions.ended');
        Route::post('me/sessions/end-others', [ProfileController::class, 'endOthers'])->name('sessions.ended_others');
        // Kişiye özel tercihler (Diğer İşlemler > Hızlı İşlemler); işlem geçmişine yazılmaz.
        Route::get('me/quick-actions', [PreferencesController::class, 'quickActions']);
        Route::put('me/quick-actions', [PreferencesController::class, 'saveQuickActions'])->middleware('throttle:60,1')->defaults('activity', false);
        Route::delete('me/quick-actions', [PreferencesController::class, 'resetQuickActions'])->defaults('activity', false);
        // İki adımlı doğrulama (şifre onaylı; denemeler sınırlı)
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('me/two-factor/setup', [ProfileController::class, 'twoFactorSetup']);
            Route::post('me/two-factor/confirm', [ProfileController::class, 'twoFactorConfirm']);
            Route::post('me/two-factor/disable', [ProfileController::class, 'twoFactorDisable']);
            Route::post('me/two-factor/recovery', [ProfileController::class, 'twoFactorRecovery']);
        });

        Route::get('live', [SystemController::class, 'live']);
        // Değişiklik akışı (tabloların canlı güncelleme şeridi; Modules\Live\Changes)
        Route::get('changes/cursor', [ChangesController::class, 'cursor']);
        Route::get('changes', [ChangesController::class, 'index']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'read'])->defaults('activity', false);
        Route::post('notifications/unread', [NotificationController::class, 'unread'])->defaults('activity', false);
        Route::post('notifications/delete', [NotificationController::class, 'delete'])->defaults('activity', false);

        // Panel araması (üst çubuk): kayıtlar; sayfalar ve ayarlar panelde aranır.
        Route::get('search', [SearchController::class, 'index'])->middleware('throttle:120,1');

        Route::get('history', [HistoryController::class, 'index']);
        Route::get('history/stats', [HistoryController::class, 'stats']);
        Route::get('history/options', [HistoryController::class, 'options']);
        Route::get('history/{id}', [HistoryController::class, 'show'])->whereNumber('id');

        // Ayarlar (grup yetkisi: SettingsAccess)
        Route::middleware('cap:km_manage_settings|km_manage_security|km_manage_ai|km_manage_seo|km_manage_languages')->group(function () {
            Route::get('settings', [SystemController::class, 'settings']);
            Route::put('settings', [SystemController::class, 'save'])->name('settings.updated');
            Route::post('settings/defaults', [SystemController::class, 'defaults'])->name('settings.defaults');
        });

        // Yapay zekâ sağlayıcıları (Ayarlar > Yapay zekâ; App\Core\Ai): Jarvis kapalı olsa da (çeviri kullanır)
        Route::middleware('cap:km_manage_ai')->prefix('system')->group(function () {
            Route::get('ai', [AiController::class, 'show']);
            Route::put('ai', [AiController::class, 'save'])->name('ai.updated');
            Route::post('ai/test', [AiController::class, 'test'])->middleware('throttle:10,1')->defaults('activity', false);
            Route::post('ai/models', [AiController::class, 'models'])->middleware('throttle:20,1')->defaults('activity', false);
        });

        // E-posta ve arka plan işleri (Ayarlar > E-posta, Ayarlar > Arka plan)
        Route::middleware('cap:km_manage_settings')->prefix('system')->group(function () {
            // API'nin makine okunur belgesi (OpenAPI; App\Core\Contracts): yalnızca yetkiliye.
            Route::get('openapi', [ContractsController::class, 'openapi']);
            Route::get('mail', [BackgroundController::class, 'mail']);
            Route::put('mail', [BackgroundController::class, 'saveMail'])->name('mail.updated');
            Route::post('mail/test', [BackgroundController::class, 'testMail'])->middleware('throttle:10,1')->name('mail.tested');
            Route::get('jobs', [BackgroundController::class, 'jobs']);
            Route::post('jobs/{uuid}/retry', [BackgroundController::class, 'retry'])->where('uuid', '[\w-]+')->name('jobs.retried');
            Route::delete('jobs/{uuid}', [BackgroundController::class, 'forget'])->where('uuid', '[\w-]+')->name('jobs.forgotten');
        });

        // SMS (isteğe bağlı modül; Ayarlar > SMS)
        Route::middleware(['module:sms', 'cap:km_manage_sms'])->prefix('system')->group(function () {
            Route::get('sms', [SmsController::class, 'show']);
            Route::put('sms', [SmsController::class, 'save'])->name('sms.updated');
            Route::post('sms/senders', [SmsController::class, 'senders'])->middleware('throttle:10,1')->defaults('activity', false);
            Route::post('sms/test', [SmsController::class, 'test'])->middleware('throttle:10,1')->name('sms.tested');
        });

        // Mağaza (isteğe bağlı modül): siparişler ve kuponlar. Ürünler içerik türüdür (admin/content/products).
        Route::middleware('module:commerce')->prefix('admin/commerce')->group(function () {
            Route::get('orders', [OrdersController::class, 'index'])->middleware('cap:km_view_orders');
            Route::get('orders/{id}', [OrdersController::class, 'show'])->whereNumber('id')->middleware('cap:km_view_orders');
            Route::post('orders/{id}/status', [OrdersController::class, 'status'])->whereNumber('id')->middleware('cap:km_manage_orders')->name('commerce.order_status');
            Route::get('coupons', [OrdersController::class, 'coupons'])->middleware('cap:km_manage_coupons');
            Route::post('coupons', [OrdersController::class, 'saveCoupon'])->middleware('cap:km_manage_coupons')->name('commerce.coupon_created');
            Route::put('coupons/{id}', [OrdersController::class, 'saveCoupon'])->whereNumber('id')->middleware('cap:km_manage_coupons')->name('commerce.coupon_saved');
            Route::delete('coupons/{id}', [OrdersController::class, 'deleteCoupon'])->whereNumber('id')->middleware('cap:km_manage_coupons')->name('commerce.coupon_deleted');
            Route::get('bookings', [BookingsController::class, 'index'])->middleware(['module:booking', 'cap:km_view_bookings']);
        });

        // Bülten (isteğe bağlı araç): aboneler ve bültenler.
        Route::middleware(['module:newsletter', 'cap:km_manage_newsletter'])->prefix('admin/newsletter')->group(function () {
            Route::get('subscribers', [NewsletterAdminController::class, 'subscribers']);
            Route::post('subscribers', [NewsletterAdminController::class, 'addSubscriber'])->middleware('throttle:30,1')->name('newsletter.subscriber_added');
            Route::delete('subscribers/{id}', [NewsletterAdminController::class, 'deleteSubscriber'])->whereNumber('id')->name('newsletter.subscriber_deleted');
            Route::get('campaigns', [NewsletterAdminController::class, 'campaigns']);
            Route::post('campaigns', [NewsletterAdminController::class, 'saveCampaign'])->name('newsletter.campaign_created');
            Route::put('campaigns/{id}', [NewsletterAdminController::class, 'saveCampaign'])->whereNumber('id')->name('newsletter.campaign_saved');
            Route::delete('campaigns/{id}', [NewsletterAdminController::class, 'deleteCampaign'])->whereNumber('id')->name('newsletter.campaign_deleted');
            Route::post('campaigns/{id}/test', [NewsletterAdminController::class, 'test'])->whereNumber('id')->middleware('throttle:10,1')->name('newsletter.campaign_tested');
            Route::post('campaigns/{id}/send', [NewsletterAdminController::class, 'send'])->whereNumber('id')->name('newsletter.campaign_started');
            Route::post('campaigns/{id}/pause', [NewsletterAdminController::class, 'pause'])->whereNumber('id')->name('newsletter.campaign_paused');
            Route::post('campaigns/{id}/continue', [NewsletterAdminController::class, 'continue'])->whereNumber('id')->middleware('throttle:10,1')->defaults('activity', false);
        });

        // Sistem Sağlığı (App\Core\Health; modüller kart ekler)
        Route::middleware('cap:km_view_health')->prefix('system')->group(function () {
            Route::get('health', [HealthController::class, 'show']);
            Route::get('health/errors', [HealthController::class, 'errors']);
            Route::delete('health/errors', [HealthController::class, 'clearErrors'])->name('health.errors_cleared');
            Route::post('health/report', [HealthController::class, 'report'])->middleware('throttle:5,1')->name('health.reported');
        });

        // Siteler (çok site; yalnızca ana sitede, App\Core\Sites)
        // Araç Pazarı: projede kurulu araçlar, aç / kapat (Core\Modules, Core\ModuleState).
        Route::middleware('cap:km_manage_settings')->prefix('system')->group(function () {
            Route::get('tools', [ToolsController::class, 'index']);
            Route::put('tools/{key}', [ToolsController::class, 'toggle'])->where('key', '[a-z][a-z0-9-]*')->name('tools.toggled');
            Route::put('tools/{key}/install', [ToolsController::class, 'install'])->where('key', '[a-z][a-z0-9-]*')->name('tools.installed');
        });

        Route::middleware(['module:sites', 'cap:km_manage_sites'])->prefix('system')->group(function () {
            $site = '[a-z0-9-]{1,32}';
            Route::get('sites', [SitesController::class, 'index']);
            Route::post('sites/plan', [SitesController::class, 'plan'])->middleware('throttle:6,1')->defaults('activity', false);
            Route::post('sites', [SitesController::class, 'store'])->middleware('throttle:10,1')->name('sites.created');
            Route::put('sites/{key}', [SitesController::class, 'update'])->where('key', $site)->name('sites.updated');
            Route::post('sites/{key}/migrate', [SitesController::class, 'migrate'])->where('key', $site)->middleware('throttle:10,1')->name('sites.migrated');
            Route::post('sites/{key}/handoff', [SitesController::class, 'handoff'])->where('key', $site)->middleware('throttle:20,1')->name('sites.handoff');
            Route::delete('sites/{key}', [SitesController::class, 'destroy'])->where('key', $site)->name('sites.removed');
        });

        // SEO (ayarları /settings?group=SEO; kayıt SEO'su içerik düzenleme yetkisiyle de)
        Route::post('seo/analyze', [SeoController::class, 'analyze'])->middleware($content.'|km_manage_seo')->defaults('activity', false);
        Route::get('seo/meta/{type}/{id}', [SeoController::class, 'meta'])->where('type', implode('|', SeoMeta::types()))->whereNumber('id')->middleware($content.'|km_manage_seo');
        Route::put('seo/meta/{type}/{id}', [SeoController::class, 'saveMeta'])->where('type', implode('|', SeoMeta::types()))->whereNumber('id')->middleware($content.'|km_manage_seo');
        Route::prefix('seo')->middleware('cap:km_manage_seo')->group(function () {
            Route::get('/', [SeoController::class, 'overview']);
            Route::get('content', [SeoController::class, 'content']);
            Route::get('pages', [SeoController::class, 'pages']);
            Route::put('pages', [SeoController::class, 'savePage']);
            Route::get('redirects', [SeoController::class, 'redirects']);
            Route::post('redirects', [SeoController::class, 'storeRedirect']);
            Route::post('redirects/test', [SeoController::class, 'testRedirect'])->defaults('activity', false);
            Route::get('redirects/export', [SeoController::class, 'exportRedirects']);
            Route::post('redirects/import', [SeoController::class, 'importRedirects']);
            Route::put('redirects/{id}', [SeoController::class, 'updateRedirect'])->whereNumber('id');
            Route::delete('redirects/{id}', [SeoController::class, 'deleteRedirect'])->whereNumber('id');
            Route::get('ai', [SeoController::class, 'ai']);
            Route::get('404', [SeoController::class, 'notFound']);
            Route::post('404/status', [SeoController::class, 'notFoundStatus']);
            Route::post('404/delete', [SeoController::class, 'notFoundDelete']);
            Route::get('links', [SeoController::class, 'links']);
            Route::post('links/check', [SeoController::class, 'checkLinks'])->defaults('activity', false);
            Route::post('links/fix', [SeoController::class, 'fixLinks']);
            Route::get('robots', [SeoController::class, 'robots']);
            Route::put('robots', [SeoController::class, 'saveRobots']);
            Route::get('indexnow', [SeoController::class, 'indexNow']);
            Route::post('indexnow/submit', [SeoController::class, 'submitIndexNow']);
        });

        // Çoklu dil: dil kaydı (done-coklu-dil-raporu.md)
        Route::prefix('languages')->middleware('cap:km_manage_languages')->group(function () {
            $code = '[a-z]{2,3}(-[a-z0-9]{2,8})?';
            Route::get('/', [LanguagesController::class, 'index']);
            Route::post('/', [LanguagesController::class, 'store'])->name('languages.created');
            Route::post('order', [LanguagesController::class, 'order'])->name('languages.reordered');
            Route::get('overview', [LanguagesController::class, 'overview']);
            Route::get('health', [LanguagesController::class, 'health']);
            Route::get('urls', [LanguagesController::class, 'urls']);
            Route::get('visitor', [LanguagesController::class, 'visitor']);
            Route::put('visitor', [LanguagesController::class, 'saveVisitor'])->name('languages.visitor_updated');
            Route::put('{code}', [LanguagesController::class, 'update'])->where('code', $code)->name('languages.updated');
            Route::delete('{code}', [LanguagesController::class, 'destroy'])->where('code', $code)->name('languages.deleted');
        });

        // Talepler
        Route::get('briefs', [BriefsController::class, 'index'])->middleware(['module:briefs', 'cap:km_view_briefs']);
        Route::post('briefs/{id}/status', [BriefsController::class, 'status'])->whereNumber('id')->middleware(['module:briefs', 'cap:km_manage_briefs'])->name('briefs.status_changed');

        // Genel Sil ve Silinenler
        Route::get('records/plan', [RecordsController::class, 'plan'])->middleware('cap:km_delete_content|km_delete_briefs');
        Route::post('records/hide', [RecordsController::class, 'hide'])->middleware('cap:km_delete_content|km_delete_briefs')->name('records.hidden');
        Route::post('records/restore', [RecordsController::class, 'restore'])->middleware('cap:km_restore_records')->name('records.restored');
        Route::get('records/hidden', [RecordsController::class, 'hidden'])->middleware('cap:km_restore_records');

        // Kullanıcılar
        Route::get('users', [UsersController::class, 'index'])->middleware('cap:km_view_users');
        Route::get('users/options', [UsersController::class, 'options'])->middleware('cap:km_view_users');
        Route::get('users/{id}', [UsersController::class, 'show'])->whereNumber('id')->middleware('cap:km_view_users');
        Route::middleware('cap:km_manage_users')->group(function () {
            Route::post('users', [UsersController::class, 'store'])->name('users.created');
            Route::put('users/{id}', [UsersController::class, 'update'])->whereNumber('id')->name('users.updated');
            Route::post('users/{id}/deactivate', [UsersController::class, 'deactivate'])->whereNumber('id')->name('users.deactivated');
            Route::post('users/{id}/activate', [UsersController::class, 'activate'])->whereNumber('id')->name('users.activated');
            Route::post('users/{id}/sessions/end', [UsersController::class, 'endSessions'])->whereNumber('id')->name('users.sessions_ended');
            Route::post('users/{id}/two-factor/reset', [UsersController::class, 'resetTwoFactor'])->whereNumber('id')->name('users.two_factor_reset');
        });
        Route::middleware(['cap:km_message_users', 'throttle:20,1'])->group(function () {
            Route::post('users/sms', [MessageController::class, 'sms'])->middleware('module:sms')->name('users.sms_sent');
            Route::post('users/mail', [MessageController::class, 'mail'])->name('users.mail_sent');
        });

        // Roller ve izinler (yalnızca Yönetici)
        Route::middleware('cap:km_manage_roles')->group(function () {
            $slug = '[a-z0-9\-]{1,40}';
            Route::get('roles', [RolesController::class, 'index']);
            Route::post('roles', [RolesController::class, 'store'])->name('roles.created');
            Route::put('roles/{slug}', [RolesController::class, 'update'])->where('slug', $slug)->name('roles.renamed');
            Route::put('roles/{slug}/caps', [RolesController::class, 'caps'])->where('slug', $slug)->name('roles.updated');
            Route::put('roles/{slug}/menu', [RolesController::class, 'menu'])->where('slug', $slug)->name('roles.menu_updated');
            Route::delete('roles/{slug}', [RolesController::class, 'destroy'])->where('slug', $slug)->name('roles.deleted');
        });

        // Güvenlik
        Route::prefix('security')->middleware('cap:km_manage_security')->group(function () {
            Route::get('/', [SecurityController::class, 'overview']);
            Route::get('events', [SecurityController::class, 'events']);
            Route::get('sessions', [SecurityController::class, 'sessions']);
            Route::delete('sessions/{id}', [SecurityController::class, 'endSession'])->whereNumber('id')->name('security.session_ended');
            Route::post('sessions/end-all', [SecurityController::class, 'endAll'])->name('sessions.ended_all');
            Route::post('key/rotate', [SecurityController::class, 'rotateKey'])->name('security.key_rotated');
            Route::get('blocks', [SecurityController::class, 'blocks']);
            Route::post('blocks', [SecurityController::class, 'block'])->name('security.ip_blocked');
            Route::delete('blocks/{id}', [SecurityController::class, 'unblock'])->whereNumber('id')->name('security.ip_unblocked');
            Route::get('lockouts', [SecurityController::class, 'lockouts']);
            Route::post('lockouts/unlock', [SecurityController::class, 'unlock'])->name('security.login_unlocked');
        });

        Route::prefix('admin')->group(function () use ($content) {
            // İçerik türleri kayıttan (Types; config/kodmob.php content_types + blog).
            $types = Types::pattern();
            Route::get('overview', [AdminContentController::class, 'overview'])->middleware('cap:km_view_overview');
            Route::get('settings', [SystemController::class, 'settings'])->middleware('cap:km_manage_settings|km_manage_security|km_manage_ai');

            Route::middleware($content)->group(function () use ($types) {
                Route::get('schema/{type}', [AdminContentController::class, 'schema'])->where('type', $types);
                Route::get('content/{type}', [AdminContentController::class, 'index'])->where('type', $types);
                Route::get('content/{type}/new', [AdminContentController::class, 'blank'])->where('type', $types);
                Route::post('content/{type}/order', [AdminContentController::class, 'order'])->where('type', $types)->name('content.reordered');
                Route::get('content/{type}/{id}', [AdminContentController::class, 'show'])->where('type', $types)->whereNumber('id');
                Route::post('content/{type}', [AdminContentController::class, 'create'])->where('type', $types);
                Route::put('content/{type}/{id}', [AdminContentController::class, 'update'])->where('type', $types)->whereNumber('id');
                Route::delete('content/{type}/{id}', [AdminContentController::class, 'delete'])->where('type', $types)->whereNumber('id');
                Route::get('options/{type}', [AdminContentController::class, 'options'])->where('type', $types);
                // Çeviriler (done-coklu-dil-raporu.md Faz 4)
                Route::post('translate/{type}', [AdminContentController::class, 'translate'])->where('type', $types)->defaults('activity', false);
                Route::post('content/posts/{id}/translations', [AdminContentController::class, 'createPostTranslation'])->whereNumber('id')->middleware('module:blog');
                Route::post('content/posts/{id}/link', [AdminContentController::class, 'linkPost'])->whereNumber('id')->middleware('module:blog');
                Route::post('content/posts/{id}/unlink', [AdminContentController::class, 'unlinkPost'])->whereNumber('id')->middleware('module:blog');
            });

            Route::get('briefs', [AdminContentController::class, 'briefs'])->middleware(['module:briefs', 'cap:km_view_briefs']);
            Route::get('briefs/{id}', [AdminContentController::class, 'brief'])->whereNumber('id')->middleware(['module:briefs', 'cap:km_view_briefs']);
            Route::post('briefs/{id}/status', [AdminContentController::class, 'briefStatus'])->whereNumber('id')->middleware(['module:briefs', 'cap:km_manage_briefs']);
            Route::delete('briefs/{id}', [AdminContentController::class, 'briefDelete'])->whereNumber('id')->middleware(['module:briefs', 'cap:km_delete_briefs']);

            // Görseller: içerik formları görsel seçer (liste), yükleme ayrı yetki
            Route::get('media', [MediaController::class, 'index'])->middleware('cap:km_manage_media|'.Types::capabilities());
            Route::middleware('cap:km_manage_media')->group(function () {
                Route::post('media', [MediaController::class, 'upload'])->name('media.uploaded');
                Route::post('media/url', [MediaController::class, 'fromUrl'])->name('media.imported');
                Route::put('media/{id}', [MediaController::class, 'update'])->whereNumber('id')->name('media.alts_updated');
            });

            Route::get('texts', [PagesController::class, 'texts'])->middleware('cap:km_manage_texts');
            Route::put('texts', [PagesController::class, 'saveTexts'])->middleware('cap:km_manage_texts')->name('texts.updated');
            Route::post('texts/translate', [PagesController::class, 'translateTexts'])->middleware('cap:km_manage_texts')->defaults('activity', false);

            // Sayfa düzenleyici (Site\Blocks): bölgelerdeki bloklar; taslak her değişiklikte kaydedilir (geçmişe yazılmaz).
            // Blok sayfasının bölgesi kaydın kimliğini taşır (`page.main@12`).
            $zone = '[a-zA-Z][a-zA-Z0-9-]*(\.[a-zA-Z0-9-]+)+(@[0-9]+)?';
            Route::middleware('cap:km_edit_layout')->group(function () use ($zone) {
                Route::get('layouts', [LayoutsController::class, 'index']);
                Route::post('layouts/publish', [LayoutsController::class, 'publish'])->name('layouts.published');
                Route::post('layouts/render', [LayoutsController::class, 'render'])->defaults('activity', false);
                Route::post('layouts/preview', [LayoutsController::class, 'preview'])->defaults('activity', false);
                // Jarvis (yapay zekâ sağlayıcısı): yalnızca blok metinleri gider; kaydetmez.
                Route::middleware('throttle:30,1')->group(function () {
                    Route::post('layouts/assist', [LayoutsController::class, 'assist'])->defaults('activity', false);
                    Route::post('layouts/translate', [LayoutsController::class, 'translate'])->defaults('activity', false);
                    Route::post('layouts/suggest', [LayoutsController::class, 'suggest'])->defaults('activity', false);
                });
                Route::put('layouts/{zone}', [LayoutsController::class, 'save'])->where('zone', $zone)->defaults('activity', false);
                Route::delete('layouts/{zone}/draft', [LayoutsController::class, 'discard'])->where('zone', $zone)->name('layouts.discarded');
                Route::get('layouts/{zone}/revisions', [LayoutsController::class, 'revisions'])->where('zone', $zone);
                Route::post('layouts/{zone}/revisions/{id}', [LayoutsController::class, 'restore'])->where('zone', $zone)->whereNumber('id')->name('layouts.restored');
            });
            // Keşfet › Bloklar (Araç Pazarı): blok kitaplığına ekleme.
            Route::get('blocks', [LayoutsController::class, 'catalog'])->middleware('cap:km_edit_layout|km_manage_settings');
            Route::put('blocks/{type}', [LayoutsController::class, 'toggle'])->where('type', '[a-z][a-z0-9-]*')->middleware('cap:km_edit_layout|km_manage_settings')->name('blocks.library');
            // Yasal belgeler (Site\Legal): açık modüllerin belgeleri; dil başına panelden özelleştirme.
            Route::middleware('cap:km_manage_legal')->group(function () {
                $doc = ['name' => '[a-z][a-zA-Z0-9]{1,40}', 'locale' => '[a-z]{2}(-[A-Z]{2})?'];
                Route::get('legal', [LegalController::class, 'index']);
                Route::get('legal/{name}/{locale}', [LegalController::class, 'show'])->where($doc);
                Route::put('legal/{name}/{locale}', [LegalController::class, 'save'])->where($doc)->name('legal.customized');
                Route::delete('legal/{name}/{locale}', [LegalController::class, 'reset'])->where($doc)->name('legal.reset');
            });
            Route::get('estimate', [PagesController::class, 'estimate'])->middleware(['module:estimate', 'cap:km_manage_estimate']);
            Route::put('estimate', [PagesController::class, 'saveEstimate'])->middleware(['module:estimate', 'cap:km_manage_estimate'])->name('estimate.updated');

            Route::middleware('cap:km_manage_backups')->group(function () {
                Route::get('backup', [BackupController::class, 'export'])->name('backup.exported');
                Route::post('backup', [BackupController::class, 'import'])->name('backup.restored');
                // Otomatik yedek (zip: içerik + görseller; isteğe bağlı S3 uyumlu depo)
                Route::get('backups/auto', [BackupController::class, 'auto']);
                Route::put('backups/auto', [BackupController::class, 'saveAuto'])->name('backup.auto_updated');
                Route::post('backups/auto/run', [BackupController::class, 'runAuto'])->name('backup.auto_run');
                Route::post('backups/auto/test', [BackupController::class, 'testRemote'])->middleware('throttle:10,1')->defaults('activity', false);
                Route::get('backups/auto/{name}', [BackupController::class, 'downloadAuto'])->where('name', '[\w.-]+\.(zip|kmbak)');
                Route::post('backups/auto/{name}/restore', [BackupController::class, 'restoreAuto'])->where('name', '[\w.-]+\.(zip|kmbak)')->name('backup.restored');
                Route::get('backups', [BackupController::class, 'list']);
                Route::get('backups/{name}', [BackupController::class, 'show'])->where('name', '[\w.-]+');
            });
        });

        // Jarvis sohbeti (module:jarvis) ve domain arama (module:domains) ayrı modüller; adresleri /jarvis altında.
        Route::prefix('jarvis')->middleware('module:jarvis')->group(function () {
            Route::middleware('cap:km_use_jarvis')->group(function () {
                Route::get('/', [JarvisController::class, 'overview']);
                Route::post('skills/{name}', [JarvisController::class, 'skill'])->where('name', '[a-z_]+');
            });
        });
        Route::prefix('jarvis')->middleware('module:domains')->group(function () {
            Route::middleware('cap:km_use_domains')->group(function () {
                Route::post('tick', [DomainsController::class, 'tick'])->defaults('activity', false);
                Route::get('jobs', [DomainsController::class, 'jobs']);
                Route::get('jobs/{id}', [DomainsController::class, 'job'])->whereNumber('id');
                Route::post('jobs/{id}/{action}', [DomainsController::class, 'control'])->whereNumber('id')->where('action', 'pause|resume|cancel|retry|verify')->name('jarvis.job_controlled');
                Route::delete('jobs/{id}', [DomainsController::class, 'deleteJob'])->whereNumber('id')->name('jarvis.job_deleted');

                Route::get('domains/options', [DomainsController::class, 'options']);
                Route::post('domains/preview', [DomainsController::class, 'preview'])->defaults('activity', false);
                Route::post('domains/scan', [DomainsController::class, 'scan'])->name('jarvis.scan_started');
                Route::post('domains/check', [DomainsController::class, 'check'])->name('jarvis.domains_checked');
                Route::get('domains', [DomainsController::class, 'domains']);
                Route::get('domains/export', [DomainsController::class, 'export']);
            });
        });
        Route::prefix('jarvis')->middleware('module:jarvis')->group(function () {
            Route::middleware('cap:km_use_jarvis')->group(function () {
                Route::get('conversations', [AssistantController::class, 'index']);
                Route::post('conversations', [AssistantController::class, 'create'])->name('jarvis.conversation_created');
                Route::get('conversations/{id}', [AssistantController::class, 'show'])->whereNumber('id');
                Route::patch('conversations/{id}', [AssistantController::class, 'rename'])->whereNumber('id')->name('jarvis.conversation_renamed');
                Route::delete('conversations/{id}', [AssistantController::class, 'delete'])->whereNumber('id')->name('jarvis.conversation_deleted');
                Route::post('conversations/{id}/messages', [AssistantController::class, 'send'])->whereNumber('id')->name('jarvis.message_sent');
                Route::post('conversations/{id}/continue', [AssistantController::class, 'continue'])->whereNumber('id')->defaults('activity', false);
                // Operatör: Jarvis'in yazma önerisi (araç kullanıcının adına çalışır; kendi yetkisi de denetlenir).
                Route::post('actions/{id}/approve', [AssistantController::class, 'approve'])->whereNumber('id')->middleware('throttle:30,1')->name('jarvis.action_approved');
                Route::post('actions/{id}/reject', [AssistantController::class, 'reject'])->whereNumber('id')->middleware('throttle:30,1')->name('jarvis.action_rejected');
            });
        });
    });

    // ---------------------------------------------------------------- proje
    // Projenin kendi uçları (çatı güncellemesi dokunmaz). Aynı kurallar: panel ucu `auth.jwt` + `cap:`,
    // herkese açık uç ArchitectureTest'in izin listesinde; yazma rotasının adı ActivityCatalog'da.
    if (is_file($project = __DIR__.'/project.php')) {
        require $project;
    }

    // ---------------------------------------------------------------- araç paketleri
    // kodmob-core/extensions/<anahtar>/php/routes.php (Core\Extensions\Extensions): araç bu projede kapalıysa 404.
    foreach (Extensions::routes() as $key => $file) {
        Route::middleware('module:'.$key)->group($file);
    }
});
