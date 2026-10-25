<?php

namespace App\Core;

use App\Core\Content\ContentType;
use App\Core\Database\BackupPart;
use App\Core\Search\SearchProvider;
use App\Core\Settings\SettingDefinition;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Bir alanın (talepler, Jarvis, site…) uygulamaya katkısı. Rotalar
 * routes/api.php'de açıkça yazılır; modül yalnızca şunları verir:
 *
 * - `settings()`: panel > Ayarlar'da görünen tanımlar.
 * - `events()`: olay türlerinin okunur adları (EventCatalog).
 * - `boot()`: dinleyiciler ve kayıtlar (ör. Jarvis becerileri).
 * - `schedule()`: zamanlanmış görevler (cron yoksa panel istekleri tetikler).
 * - `health()`: Sistem Sağlığı'na kart (Core\Health\Health::section).
 * - `capabilities()`: modülün yetkileri (rol düzenleyici, `cap:` ara katmanı, panel sayfasının `can`'ı).
 * - `contentTypes()`: modülün içerik türleri (ör. Mağaza'nın ürünleri); modül kapalıyken yoktur.
 * - `legal()`: yasal belgelerin şablonuna veri (kargo ücreti, saklama süresi…); belgeler kodmob-frontend/src/legal'da.
 * - `backup()`: yedeğe giren tabloları (siparişler, talepler…); kişisel veri ayrıca seçilir.
 * - `steps()`: panelde Genel bakış'taki "Sıradaki adımlar"a adım (Core\Setup\Onboarding).
 *
 * Modül listesi: App\Core\Modules (çekirdek + açık isteğe bağlılar + config/project.php 'modules').
 */
abstract class Module
{
    /**
     * Modülün yetkileri: anahtar `km_` ile başlar. Yönetici hepsine sahiptir; diğer rollere Roller ve
     * İzinler'den verilir. Modül kapalıysa (isteğe bağlı) yetkileri de yoktur.
     *
     * @return array<string,array{label:string,group?:string}> ör. ['km_view_reports' => ['label' => 'Raporları görme', 'group' => 'Site']]
     */
    public function capabilities(): array
    {
        return [];
    }

    /**
     * Modülün içerik türleri (App\Core\Content\ContentType sınıfları). Projenin türleri gibi panelde liste,
     * form, çeviri, yedek, arama ve yetki alır; profilin `contentTypes` listesine bağlı değildir, modülle
     * açılıp kapanır.
     *
     * @return list<class-string<ContentType>>
     */
    public function contentTypes(): array
    {
        return [];
    }

    /** @return list<SettingDefinition> */
    public function settings(): array
    {
        return [];
    }

    /** @return array<string,array{0:string,1?:string,2?:callable}> tür => [başlık, ton, metin] */
    public function events(): array
    {
        return [];
    }

    /** @return list<SearchProvider> panel aramasına katkı (üst çubuk; Core\Search) */
    public function search(): array
    {
        return [];
    }

    /** @return list<array<string,mixed>> Sistem Sağlığı kartları: Core\Health\Health::section(...) */
    public function health(): array
    {
        return [];
    }

    /**
     * Yasal belgelerin (kodmob-frontend/src/legal; Modules\Site\Legal) şablonuna veri: metinde geçen ayar değerleri, ör. Mağaza'nın
     * kargo ücreti, Talepler'in saklama süresi. Anahtarlar şablonda yol olur (`{{commerce.shippingFee}}`); modüllerin
     * verileri birleştirilir.
     *
     * @return array<string,mixed>
     */
    public function legal(string $locale): array
    {
        return [];
    }

    /**
     * Yedeğe giren tabloları (Modules\Content\Backup; içerik türleri ve ayarlar kendiliğinden girer). Kişisel veri
     * taşıyan parça (`personal: true`) indirilen yedeğe yalnızca istenince girer, geri yüklemede ayrıca seçilir.
     *
     * @return list<BackupPart>
     */
    public function backup(): array
    {
        return [];
    }

    /**
     * Genel bakış'taki "Sıradaki adımlar"a katkı (Core\Setup\Onboarding): siteyi yayına hazırlarken yapılacak iş.
     * `done` gerçek veriden hesaplanır (ayar dolu, sayfa yayında…); `route` / `params` / `tab` panelde açılacak ekran
     * (kodmob-frontend/src/panel/routes.js), `anchor` o ekranda gösterilecek ayar (`#ayar=`; sekmesi açılır, alan vurgulanır); `can`
     * adımı görecek yetki; `order` sıra (küçük önce).
     *
     * @return list<array{key:string,label:string,hint?:string,done:bool,route:string,params?:array<string,mixed>,tab?:?string,anchor?:?string,can?:?string,order?:int}>
     */
    public function steps(): array
    {
        return [];
    }

    public function boot(): void {}

    public function schedule(Schedule $schedule): void {}
}
