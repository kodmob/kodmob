<?php

namespace App\Core;

use App\Core\Content\ContentType;
use App\Core\Extensions\Extensions;
use App\Core\Support\SiteManifest;
use App\Modules\Blog\BlogModule;
use App\Modules\Briefs\BriefsModule;
use App\Modules\Commerce\Booking\BookingModule;
use App\Modules\Commerce\CommerceModule;
use App\Modules\Commerce\Hotels\HotelsModule;
use App\Modules\Commerce\Paytr\PaytrModule;
use App\Modules\Commerce\Stripe\StripeModule;
use App\Modules\Domains\DomainsModule;
use App\Modules\I18n\I18nModule;
use App\Modules\Jarvis\JarvisModule;
use App\Modules\Live\LiveModule;
use App\Modules\Media\MediaModule;
use App\Modules\Members\MembersModule;
use App\Modules\Newsletter\NewsletterModule;
use App\Modules\Notifications\NotificationsModule;
use App\Modules\Security\SecurityModule;
use App\Modules\Seo\SeoModule;
use App\Modules\Site\SiteModule;
use App\Modules\System\SystemModule;

/**
 * Modül kaydı: hangi modüller her zaman açık (çekirdek), hangileri projeye göre açılıp kapanır.
 *
 * - Çekirdek: sistem, site, medya, bildirimler, canlı nabız, güvenlik, SEO, çoklu dil; ayrıca modül
 *   sınıfı olmayan kullanıcılar, roller, işlem geçmişi, Silinenler, yedek.
 * - İsteğe bağlı (sitenin profili `kodmob-frontend/src/profiles/<ad>/site.config.js` → `profile.modules`; ortamda KODMOB_MODULES=blog,briefs
 *   üstündedir; ikisi de yoksa 2.5 öncesinin hepsi, Modules::LEGACY): listede olmayan
 *   modülün sınıfı yüklenmez (ayarları, olayları, zamanlanmış görevleri, arama sağlayıcısı yok),
 *   yetkileri yetki listesinden düşer (panel menüsü, Diğer İşlemler, arama, rol düzenleyici, yönetici
 *   dahil kimse göremez) ve rotaları 404 döner (`module:` ara katmanı).
 * - Projenin modülleri (`config/project.php` → `modules`, kodu app/Project'te) her zaman açıktır;
 *   rotaları routes/project.php'de.
 * - İçerik türleri (projenin: kodmob.com'da projeler, hizmetler, referanslar; kodmob:module ile
 *   eklenenler) `config/project.php` → `content_types`'tadır; blog bir içerik türü gibi davranır ama
 *   `blog` modülüne bağlıdır.
 * - Araç paketleri (`kodmob-core/extensions/<anahtar>/`, Core\Extensions\Extensions): klasörde olan ve yüklenebilen
 *   paket kuruludur (profil ya da KODMOB_MODULES listesi gerekmez); çatının araçları gibi proje başına kapanır.
 */
final class Modules
{
    /** @var list<class-string<Module>> */
    public const CORE = [
        SystemModule::class,
        SiteModule::class,
        MediaModule::class,
        NotificationsModule::class,
        LiveModule::class,
        SecurityModule::class,
        SeoModule::class,
        I18nModule::class,
    ];

    /**
     * `kind`: `base` temel yapı (panelde Temel grubunda; projede açık tutulur), `tool` araç (Araç Pazarı'nda
     * açılıp kapanır). `requires`: takıldığı modüller ("modülün modülü"; ör. Rezervasyon → Mağaza): onlar
     * açık değilse bu modül de kapalıdır.
     *
     * @var array<string,array{class:?class-string<Module>,label:string,description:string,kind:string,requires?:list<string>}>
     */
    public const OPTIONAL = [
        'blog' => ['class' => BlogModule::class, 'label' => 'Blog', 'description' => 'Yazılar, kategoriler, RSS; çevirileri ve SEO\'su.', 'kind' => 'base'],
        'briefs' => ['class' => BriefsModule::class, 'label' => 'Talepler', 'description' => 'Siteden gelen talep ve iletişim formları, bildirim ve yanıt e-postaları.', 'kind' => 'base'],
        'estimate' => ['class' => null, 'label' => 'Teklif hesaplayıcı', 'description' => 'Sitede fiyat hesaplayıcı, paneldeki kalem ve fiyatları.', 'kind' => 'tool'],
        'jarvis' => ['class' => JarvisModule::class, 'label' => 'Jarvis', 'description' => 'Yapay zekâ asistanı: sohbet ve açık modüllerin araçları.', 'kind' => 'tool'],
        'domains' => ['class' => DomainsModule::class, 'label' => 'Domain arama', 'description' => 'Alan adı uygunluğu: anlık kontrol ve arka planda taramalar (RDAP).', 'kind' => 'tool'],
        'sms' => ['class' => null, 'label' => 'SMS', 'description' => 'NetGSM ile SMS: ayarlar, deneme, kullanıcılara SMS.', 'kind' => 'tool'],
        'sites' => ['class' => null, 'label' => 'Çok proje', 'description' => 'Aynı kurulumdan başka projeler (alt siteler) ve Site Sihirbazı; yalnızca ana projede yönetilir.', 'kind' => 'tool'],
        'commerce' => ['class' => CommerceModule::class, 'label' => 'Mağaza', 'description' => 'Ürünler, sepet, ödeme adımı, siparişler ve kuponlar; havale ve kapıda ödeme. Online ödeme ve rezervasyon ona takılan araçlar.', 'kind' => 'tool'],
        'stripe' => ['class' => StripeModule::class, 'label' => 'Stripe', 'description' => 'Kartla online ödeme: Stripe\'ın ödeme sayfası ve imzalı bildirim. Stripe sahibinin ticari markasıdır; Kodmob onunla bağlantılı değildir.', 'kind' => 'tool', 'requires' => ['commerce']],
        'paytr' => ['class' => PaytrModule::class, 'label' => 'PayTR', 'description' => 'Kartla online ödeme (taksit isteğe bağlı): PayTR\'nin güvenli ödeme sayfası ve hash denetimli bildirim. PayTR sahibinin ticari markasıdır; Kodmob onunla bağlantılı değildir.', 'kind' => 'tool', 'requires' => ['commerce']],
        'booking' => ['class' => BookingModule::class, 'label' => 'Rezervasyon', 'description' => 'Tur ve etkinlik rezervasyonu: tarih, yetişkin / çocuk / bebek, günlük kapasite, grup / sezon / erken rezervasyon fiyatı; Mağaza\'nın sepeti ve ödemesiyle.', 'kind' => 'tool', 'requires' => ['commerce']],
        'members' => ['class' => MembersModule::class, 'label' => 'Site üyeliği', 'description' => 'Ziyaretçi üye olur, e-postasını doğrular, girer; siparişlerini görür, şifresini yeniler, hesabını siler (KVKK). Google ile giriş isteğe bağlı. Panelde Gelenler › Üyeler.', 'kind' => 'tool'],
        'newsletter' => ['class' => NewsletterModule::class, 'label' => 'Bülten', 'description' => 'E-posta bülteni: sitede abonelik bloğu (çift onaylı), aboneler, bülten yazma ve saatlik sınırla gönderim; her e-postada abonelikten çıkma bağlantısı.', 'kind' => 'tool'],
        'hotels' => ['class' => HotelsModule::class, 'label' => 'Oteller', 'description' => 'Otel, pansiyon ve villa rezervasyonu: giriş – çıkış, oda sayısı, oda tipi × pansiyon fiyatları, oda tipi başına adet; Rezervasyon\'un sepeti ve ödemesiyle.', 'kind' => 'tool', 'requires' => ['booking']],
    ];

    /** Yeni projenin profili için önerilen sade başlangıç (profilin site.config.js'i, `profile.modules`). */
    public const STARTER = ['blog', 'briefs'];

    /**
     * Profili olmayan eski derlemede açık modüller (2.5 öncesi bütün modüller). Sonradan gelen modül (Mağaza)
     * eski kurulumda kendiliğinden açılmaz; profile yazılarak eklenir.
     */
    private const LEGACY = ['blog', 'briefs', 'estimate', 'jarvis', 'domains', 'sms', 'sites'];

    /**
     * Bütün isteğe bağlı modüller: çatınınkiler ve kurulu araç paketleri (`source: extension`).
     *
     * @return array<string,array{class:?class-string<Module>,label:string,description:string,kind:string,requires?:list<string>,source?:string,version?:string,author?:string,license?:string,homepage?:string}>
     */
    public static function optional(): array
    {
        return self::OPTIONAL + Extensions::modules();
    }

    /** @return list<string> modülün takıldığı modüller */
    public static function requires(string $key): array
    {
        return self::optional()[$key]['requires'] ?? [];
    }

    /**
     * Bu modüle takılan modüller (doğrudan).
     *
     * @return list<string>
     */
    public static function dependents(string $key): array
    {
        return array_keys(array_filter(self::optional(), static fn ($m) => in_array($key, (array) ($m['requires'] ?? []), true)));
    }

    /**
     * Açık isteğe bağlı modüller: kurulu olanlar (installed()) eksi bu projede kapatılanlar (Araç Pazarı,
     * ModuleState). Kurulu olanların sırası: `.env` KODMOB_MODULES (ya da alt sitenin kaydı) → sitenin profili
     * (profilin site.config.js'i `profile.modules`, km-manifest.json) → profil yoksa 2.5 öncesinin hepsi (LEGACY; eski derleme: canlıda
     * çekirdek derlemeden önce yüklense de hiçbir modül kaybolmaz). Takıldığı modül (`requires`) açık olmayan
     * modül de kapalıdır (zincir boyunca).
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $off = ModuleState::disabled();
        $all = self::optional();
        $on = array_values(array_filter(self::installed(), static fn ($key) => ! in_array($key, $off, true)));
        do {
            $count = count($on);
            $on = array_values(array_filter($on, static fn ($key) => ! array_diff((array) ($all[$key]['requires'] ?? []), $on)));
        } while (count($on) !== $count);

        return $on;
    }

    /**
     * Projede kurulu isteğe bağlı modüller (derleme ya da kayıt, ve Araç Pazarı'ndan eklenen çatı araçları;
     * kapatılanlar dahil) ve araç paketleri.
     *
     * @return list<string>
     */
    public static function installed(): array
    {
        $wanted = [...array_map('strval', (array) (config('kodmob.modules') ?? SiteManifest::profile()['modules'] ?? self::LEGACY)), ...ModuleState::added()];

        return [...array_values(array_filter(array_keys(self::OPTIONAL), static fn ($key) => in_array($key, $wanted, true))), ...array_keys(Extensions::modules())];
    }

    /**
     * Araç Pazarı: her isteğe bağlı modül, kurulu mu, bu projede açık mı.
     *
     * Paketlerde kaynak (`extension`), sürüm, yazar, lisans da. `requires` / `dependents`: takıldığı ve ona
     * takılan modüller; `waiting`: bu projede kapatılmamış ama takıldığı modül kapalı olduğu için çalışmıyor.
     * `added`: panelden eklendi (profilde yok; panelden çıkarılabilir). `pages`: derlemede sitede sayfası (rota adları;
     * araç kapalıyken 404).
     *
     * @return list<array<string,mixed>>
     */
    public static function catalog(): array
    {
        $installed = self::installed();
        $on = self::keys();
        $off = ModuleState::disabled();
        $added = ModuleState::added();
        $optional = self::optional();
        $pages = [];
        foreach ((array) (SiteManifest::data()['routes'] ?? []) as $route) {
            if (! empty($route['module'])) {
                $pages[(string) $route['module']][(string) $route['name']] = true;
            }
        }

        return array_map(static fn ($key, $m) => [
            'key' => $key, 'label' => $m['label'], 'description' => $m['description'], 'kind' => $m['kind'],
            'source' => $m['source'] ?? 'framework', 'installed' => in_array($key, $installed, true), 'enabled' => in_array($key, $on, true),
            'requires' => $m['requires'] ?? [], 'dependents' => self::dependents($key),
            'waiting' => in_array($key, $installed, true) && ! in_array($key, $off, true) && ! in_array($key, $on, true),
            'added' => in_array($key, $added, true) && isset(self::OPTIONAL[$key]),
            'pages' => array_keys($pages[$key] ?? []),
        ] + array_intersect_key($m, array_flip(['version', 'author', 'license', 'homepage'])), array_keys($optional), $optional);
    }

    /**
     * Açık modüllerin içerik türleri (Module::contentTypes; Content\Types birleştirir).
     *
     * @return list<class-string<ContentType>>
     */
    public static function contentTypes(): array
    {
        return array_merge(...array_map(static fn (string $class) => (new $class)->contentTypes(), self::classes()));
    }

    public static function enabled(string $key): bool
    {
        return ! isset(self::optional()[$key]) || in_array($key, self::keys(), true);
    }

    /** @return list<class-string<Module>> yüklenecek modül sınıfları: çekirdek + açık olanlar + projeninkiler */
    public static function classes(): array
    {
        $all = self::optional();
        $optional = array_filter(array_map(static fn ($key) => $all[$key]['class'], self::keys()));

        return [...self::CORE, ...array_values($optional), ...array_values((array) config('project.modules', []))];
    }

    /** Panel ve tanılama: her isteğe bağlı modül ve durumu. */
    public static function view(): array
    {
        $on = self::keys();
        $optional = self::optional();

        return array_map(static fn ($key, $m) => ['key' => $key, 'label' => $m['label'], 'description' => $m['description'], 'enabled' => in_array($key, $on, true)], array_keys($optional), $optional);
    }
}
