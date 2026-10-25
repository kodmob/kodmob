<?php

namespace App\Core\Security;

use App\Core\Content\Types;
use App\Core\Modules;
use App\Core\Sites\Sites;

/**
 * Bütün `km_*` yetkileri tek yerde. Uçlar (`cap:` ara katmanı), panel menüsü
 * ve ekrandaki düğmeler bu adları kullanır; metin olarak dağınık yetki adı yazılmaz.
 *
 * Yeni yetki: sabiti ve etiketini buraya, hazır rollerdeki yerini
 * `Roles::DEFAULTS` içine ekleyin. Değiştirilmemiş hazır roller yeni yetkiyi
 * hemen alır; panelden değiştirilmiş rol almaz (Yönetici her yetkiye sahiptir).
 */
final class Capability
{
    // Genel
    public const ACCESS_PANEL = 'km_access_panel';

    public const VIEW_OVERVIEW = 'km_view_overview';

    // İçerik (projenin türlerinin yetkileri kayıttan: ContentType::capability(), km_manage_<ad>)
    public const MANAGE_POSTS = 'km_manage_posts';

    /** Yayınlama ve yayından kaldırma; yoksa kayıt taslak kalır. */
    public const PUBLISH_CONTENT = 'km_publish_content';

    public const DELETE_CONTENT = 'km_delete_content';

    public const MANAGE_MEDIA = 'km_manage_media';

    // Site
    public const MANAGE_TEXTS = 'km_manage_texts';

    public const MANAGE_ESTIMATE = 'km_manage_estimate';

    public const MANAGE_SETTINGS = 'km_manage_settings';

    public const MANAGE_SEO = 'km_manage_seo';

    public const MANAGE_LANGUAGES = 'km_manage_languages';

    /** Sayfa düzenleyici: sayfa bölümlerini (bloklar) ekleme, düzenleme, yayınlama; Keşfet'te blok kitaplığı. */
    public const EDIT_LAYOUT = 'km_edit_layout';

    public const MANAGE_LEGAL = 'km_manage_legal';

    // Müşteriler
    public const VIEW_BRIEFS = 'km_view_briefs';

    public const MANAGE_BRIEFS = 'km_manage_briefs';

    /** Talebi kalıcı silme (KVKK silme talebi). */
    public const DELETE_BRIEFS = 'km_delete_briefs';

    // Jarvis
    public const USE_JARVIS = 'km_use_jarvis';

    public const USE_DOMAINS = 'km_use_domains';

    public const MANAGE_AI = 'km_manage_ai';

    // Yönetim
    /** İşlem Geçmişi › Tüm İşlemler'de herkesin bütün işlemleri (yoksa kendi bölümü). */
    public const VIEW_ALL_HISTORY = 'km_view_all_history';

    public const VIEW_USERS = 'km_view_users';

    public const MANAGE_USERS = 'km_manage_users';

    /** Kullanıcılara SMS ve e-posta gönderme (Kullanıcılar sayfası). */
    public const MESSAGE_USERS = 'km_message_users';

    public const MANAGE_ROLES = 'km_manage_roles';

    public const MANAGE_SECURITY = 'km_manage_security';

    public const MANAGE_BACKUPS = 'km_manage_backups';

    /** Tablolarda "Silinenler": silinen kayıtları görme ve geri yükleme. */
    public const RESTORE_RECORDS = 'km_restore_records';

    /** Alt siteler (çok site; Core\Sites): yalnızca ana sitede vardır. */
    public const MANAGE_SITES = 'km_manage_sites';

    /** SMS (isteğe bağlı modül `sms`): NetGSM ayarları, onaylı başlıklar, deneme. */
    public const MANAGE_SMS = 'km_manage_sms';

    /** Sistem Sağlığı: sunucu, güvenlik, veritabanı, depolama, arka plan özeti ve hata kodları. */
    public const VIEW_HEALTH = 'km_view_health';

    /** @var array<string,array{label:string,group:string}> modüllerin yetkileri (Module::capabilities; açılışta) */
    private static array $defined = [];

    /** Modüllerin yetkileri (AppServiceProvider her açılışta açık modüllerinkini verir). */
    public static function define(array $caps): void
    {
        foreach ($caps as $key => $cap) {
            if (! preg_match('/^km_[a-z0-9_]{2,60}$/', (string) $key)) {
                throw new \InvalidArgumentException("Yetki anahtarı km_ ile başlamalı: {$key}");
            }
            self::$defined[$key] = ['label' => (string) $cap['label'], 'group' => (string) ($cap['group'] ?? 'Proje')];
        }
    }

    public static function reset(): void
    {
        self::$defined = [];
    }

    /** İsteğe bağlı modüllerin yetkileri (Core\Modules::OPTIONAL). Listede olmayan yetki çekirdektir. */
    private const MODULE_OF = [
        self::VIEW_BRIEFS => 'briefs', self::MANAGE_BRIEFS => 'briefs', self::DELETE_BRIEFS => 'briefs',
        self::MANAGE_ESTIMATE => 'estimate',
        self::USE_JARVIS => 'jarvis', self::USE_DOMAINS => 'domains',
        self::MANAGE_SITES => 'sites',
        self::MANAGE_SMS => 'sms',
    ];

    /** @return array<string,array{label:string,group:string}> */
    public static function all(): array
    {
        $caps = self::core();
        // İçerik türlerinin düzenleme yetkileri kayıttan (Types): kodmob:module ile eklenen tür kendiliğinden
        // görünür, listeden çıkarılan türün yetkisi düşer; etiketi türden (EditableContent::capabilityLabel).
        $content = [];
        foreach (Types::editable() as $type) {
            $content[$type->capability()] = ['label' => $type->capabilityLabel(), 'group' => 'İçerik'];
        }
        $caps = array_slice($caps, 0, 2, true) + $content + array_slice($caps, 2, null, true) + self::$defined;

        // Alt siteler yalnızca ana siteden yönetilir.
        if (! Sites::isMain()) {
            unset($caps[self::MANAGE_SITES]);
        }

        // Kapalı modülün yetkileri yok (Core\Modules): panelde, rol düzenleyicide ve rotalarda görünmez.
        return array_filter($caps, static fn ($key) => Modules::enabled(self::MODULE_OF[$key] ?? ''), ARRAY_FILTER_USE_KEY);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $cap): bool
    {
        return isset(self::all()[$cap]);
    }

    /** Değiştirilemeyen yetkiler: panele giriş (her rolde) ve rol düzenleme (yalnız Yönetici). */
    public const LOCKED = [self::ACCESS_PANEL, self::MANAGE_ROLES];

    /**
     * Sözleşme (Core\Contracts): çatının yetkileri süzülmeden (modülü kapalı ya da alt sitede olsa da) ve ait
     * oldukları isteğe bağlı modül. İçerik türlerinin ve modül sınıflarının yetkileri ayrıca toplanır.
     *
     * @return array<string,array{label:string,group:string,module:?string}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::core() as $key => $cap) {
            $out[$key] = $cap + ['module' => self::MODULE_OF[$key] ?? null];
        }

        return $out;
    }

    /** @return array<string,array{label:string,group:string}> çatının sabit yetkileri (etiket ve grup) */
    private static function core(): array
    {
        return [
            self::ACCESS_PANEL => ['label' => 'Panele giriş', 'group' => 'Genel'],
            self::VIEW_OVERVIEW => ['label' => 'Genel bakışı görme', 'group' => 'Genel'],

            self::PUBLISH_CONTENT => ['label' => 'Yayınlama ve yayından kaldırma (yoksa kayıt taslak kalır)', 'group' => 'İçerik'],
            self::DELETE_CONTENT => ['label' => 'İçerik silme', 'group' => 'İçerik'],
            self::MANAGE_MEDIA => ['label' => 'Görsel yükleme ve alt metin düzenleme', 'group' => 'İçerik'],

            self::MANAGE_TEXTS => ['label' => 'Sayfa metinlerini düzenleme', 'group' => 'Site'],
            self::MANAGE_ESTIMATE => ['label' => 'Teklif hesaplayıcı fiyatlarını düzenleme', 'group' => 'Site'],
            self::MANAGE_SETTINGS => ['label' => 'Site ayarlarını değiştirme', 'group' => 'Site'],
            self::MANAGE_SMS => ['label' => 'SMS ayarları (NetGSM), onaylı başlıklar ve deneme SMS\'i', 'group' => 'Site'],
            self::MANAGE_SEO => ['label' => 'SEO: başlık ve açıklamalar, yönlendirmeler, 404, site haritası ve robots', 'group' => 'Site'],
            self::MANAGE_LANGUAGES => ['label' => 'Çoklu dil: sitenin dilleri, adres önekleri ve dil ayarları', 'group' => 'Site'],
            self::EDIT_LAYOUT => ['label' => 'Sayfa düzenleyici: sayfa bölümlerini (bloklar) düzenleme ve yayınlama', 'group' => 'Site'],
            self::MANAGE_LEGAL => ['label' => 'Yasal belgeler: aydınlatma metinlerini ve sözleşmeleri özelleştirme', 'group' => 'Site'],

            self::VIEW_BRIEFS => ['label' => 'Talepleri görme', 'group' => 'Müşteriler'],
            self::MANAGE_BRIEFS => ['label' => 'Talep durumunu değiştirme (okundu, arşiv)', 'group' => 'Müşteriler'],
            self::DELETE_BRIEFS => ['label' => 'Talebi kalıcı silme (KVKK silme talebi)', 'group' => 'Müşteriler'],

            self::USE_JARVIS => ['label' => 'Jarvis asistanıyla sohbet', 'group' => 'Jarvis'],
            self::USE_DOMAINS => ['label' => 'Domain arama ve tarama', 'group' => 'Jarvis'],
            self::MANAGE_AI => ['label' => 'Yapay zekâ sağlayıcıları ve API anahtarları (Claude, OpenAI, Gemini)', 'group' => 'Site'],

            self::VIEW_ALL_HISTORY => ['label' => 'Herkesin bütün işlemlerini görme (İşlem Geçmişi)', 'group' => 'Yönetim'],
            self::VIEW_USERS => ['label' => 'Kullanıcıları görme', 'group' => 'Yönetim'],
            self::MANAGE_USERS => ['label' => 'Kullanıcı ekleme, düzenleme, pasife alma, oturum kapatma', 'group' => 'Yönetim'],
            self::MESSAGE_USERS => ['label' => 'Kullanıcılara SMS ve e-posta gönderme', 'group' => 'Yönetim'],
            self::MANAGE_ROLES => ['label' => 'Rolleri ve izinleri düzenleme', 'group' => 'Yönetim'],
            self::MANAGE_SECURITY => ['label' => 'Güvenlik: oturumlar, IP engelleri, güvenlik kayıtları ve ayarları', 'group' => 'Yönetim'],
            self::MANAGE_BACKUPS => ['label' => 'Yedek alma ve yedekten geri yükleme', 'group' => 'Yönetim'],
            self::MANAGE_SITES => ['label' => 'Alt siteler: oluşturma, alan adları, güncelleme', 'group' => 'Yönetim'],
            self::VIEW_HEALTH => ['label' => 'Sistem sağlığını görme, hata kodlarını okuma, teknik servise bildirme', 'group' => 'Yönetim'],
            self::RESTORE_RECORDS => ['label' => 'Silinen kayıtları görme ve geri yükleme', 'group' => 'Yönetim'],
        ];
    }
}
