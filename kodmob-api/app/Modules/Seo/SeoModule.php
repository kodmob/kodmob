<?php

namespace App\Modules\Seo;

use App\Core\Events\EventRecorded;
use App\Core\Module;
use App\Core\Settings\SettingDefinition;
use App\Modules\Seo\Services\AiVisibility;
use App\Modules\Seo\Services\IndexNow;
use App\Modules\Seo\Services\LinkChecker;
use App\Modules\Seo\Services\NotFoundLog;
use App\Modules\Seo\Services\RedirectSearch;
use App\Modules\Seo\Services\SeoConfig;
use App\Modules\Seo\Services\SeoMeta;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * SEO (kodmob-seo-core WordPress eklentisinin Laravel karşılığı). Panelde SEO sayfası
 * (`km_manage_seo`); ayarlar `SEO` grubundadır ve yalnızca o sayfadan yönetilir.
 * Tamamen kapatılabilir: bkz. Seo::ENABLED.
 *
 * Ziyaretçi tarafında iki katmanda çalışır:
 * - render.php (Laravel açılmadan): yönlendirmeler, 404 kaydı, robots.txt, IndexNow
 *   anahtar dosyası. Bunlar `storage/framework/seo/config.json`'dan okunur (SeoConfig).
 * - Renderer (sayfa üretimi): başlık/açıklama, noindex, canonical, paylaşım görseli,
 *   şema, doğrulama etiketleri, içindekiler, dış bağlantılar; llms.txt.
 */
final class SeoModule extends Module
{
    public const GROUP = 'SEO';

    public function settings(): array
    {
        $g = self::GROUP;
        $defs = [new SettingDefinition(Seo::ENABLED, 'bool', true, 'SEO modülü', $g, 'Kapalıyken SEO özellikleri çalışmaz; site temel etiketlerle yayına devam eder. Veriler silinmez.')];
        foreach (Seo::modules() as $key => $module) {
            $defs[] = new SettingDefinition('seo.module.'.$key, 'bool', $module['default'], $module['label'], $g, $module['description']);
        }

        return array_merge($defs, [
            new SettingDefinition('seo.default_image', 'int', 0, 'Varsayılan paylaşım görseli', $g, 'Kendi görseli olmayan sayfalar paylaşılınca bu görsel çıkar (1200×630 önerilir).', 0),
            new SettingDefinition('seo.twitter', 'string', '', 'X (Twitter) hesabı', $g, 'Kullanıcı adı, ör. @kodmob. twitter:site etiketine yazılır.'),
            new SettingDefinition('seo.org_type', 'string', 'ProfessionalService', 'Kurum türü', $g, 'Arama motorlarına kurumun ne olduğunu söyler.', null, null, '', [
                'Organization' => 'Kurum (Organization)',
                'ProfessionalService' => 'Profesyonel hizmet (ProfessionalService)',
                'LocalBusiness' => 'Yerel işletme (LocalBusiness)',
            ]),
            new SettingDefinition('seo.org_name', 'string', '', 'Kurum adı', $g, 'Boşsa site adı.'),
            new SettingDefinition('seo.org_logo', 'int', 0, 'Logo', $g, 'Kare ya da yatay logo (en az 112×112). Boşsa site simgesi.', 0),
            new SettingDefinition('seo.org_city', 'string', 'Kozan', 'Şehir / ilçe', $g),
            new SettingDefinition('seo.org_region', 'string', 'Adana', 'İl / bölge', $g),
            new SettingDefinition('seo.org_postal', 'string', '', 'Posta kodu', $g),
            new SettingDefinition('seo.org_country', 'string', 'TR', 'Ülke kodu', $g, 'İki harf, ör. TR.'),
            new SettingDefinition('seo.org_lat', 'string', '', 'Enlem', $g, 'Ör. 37.4553 (yerel işletme için).'),
            new SettingDefinition('seo.org_lng', 'string', '', 'Boylam', $g, 'Ör. 35.8159.'),
            new SettingDefinition('seo.org_founded', 'string', '', 'Kuruluş yılı', $g),
            new SettingDefinition('seo.org_price', 'string', '', 'Fiyat aralığı', $g, 'Yerel işletme için, ör. ₺₺.'),
            new SettingDefinition('seo.org_area', 'string', 'TR, DE, Worldwide', 'Hizmet bölgesi', $g, 'Virgülle ayrılmış, ör. TR, DE, Worldwide.'),
            new SettingDefinition('seo.verify_google', 'string', '', 'Google Search Console', $g, 'Yalnızca content değeri (google-site-verification).'),
            new SettingDefinition('seo.verify_bing', 'string', '', 'Bing Webmaster', $g, 'msvalidate.01 değeri.'),
            new SettingDefinition('seo.verify_yandex', 'string', '', 'Yandex', $g, 'yandex-verification değeri.'),
            new SettingDefinition('seo.verify_baidu', 'string', '', 'Baidu', $g, 'baidu-site-verification değeri.'),
            new SettingDefinition('seo.ai_bots', 'string', 'allow', 'Yapay zekâ tarayıcıları', $g, 'GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot gibi botlar.', null, null, '', [
                'allow' => 'İzin ver (yapay zekâ cevaplarında görünür)',
                'block' => 'Engelle (eğitim ve cevaplarda kullanılmasın)',
            ]),
            new SettingDefinition('seo.llms_intro', 'string', '', 'llms.txt tanıtım cümlesi', $g, 'Boşsa site açıklaması kullanılır.'),
            new SettingDefinition('seo.sitemap_images', 'bool', true, 'Site haritasında görseller', $g, 'Kapak ve galeri görselleri haritaya eklenir (Google Görseller).'),
            new SettingDefinition('seo.toc_min', 'int', 3, 'İçindekiler için en az başlık', $g, 'Yazıda bu kadar ara başlık (H2/H3) yoksa içindekiler gösterilmez.', 2, 10, 'başlık'),
            new SettingDefinition('seo.links_new_tab', 'bool', true, 'Dış bağlantılar yeni sekmede', $g, 'rel="noopener" ile.'),
            new SettingDefinition('seo.links_nofollow', 'bool', false, 'Dış bağlantılara nofollow', $g, 'Arama motorlarına dış sitelere güç aktarmamalarını söyler.'),
            new SettingDefinition('seo.retention_404', 'int', 90, '404 kaydı saklama', $g, 'Bu süredir görülmeyen adresler silinir.', 7, 730, 'gün'),
            new SettingDefinition('seo.indexnow_key', 'string', '', 'IndexNow anahtarı', $g, 'IndexNow açılınca kendiliğinden üretilir.'),
        ]);
    }

    public function events(): array
    {
        return [
            'seo.indexnow_sent' => ['IndexNow bildirimi gönderildi', 'neutral', static fn ($p) => (int) ($p['count'] ?? 0).' adres · '.($p['status'] ?? '')],
        ];
    }

    public function search(): array
    {
        return [new RedirectSearch];
    }

    public function boot(): void
    {
        // Ayar, yönlendirme ya da içerik değişince render.php'nin okuduğu yapılandırma
        // yeniden yazılır; yayınlanan kayıtlar IndexNow kuyruğuna girer.
        Event::listen(EventRecorded::class, static function (EventRecorded $recorded) {
            $type = $recorded->event->type;
            if (str_starts_with($type, 'settings.') || str_starts_with($type, 'seo.') || $type === 'backup.restored') {
                SeoConfig::compile();
            }
            if (preg_match('/^('.implode('|', SeoMeta::types()).')\.(created|updated|deleted)$/', $type) || $type === 'records.restored') {
                IndexNow::queueSubject($recorded->event->subjectType, $recorded->event->subjectId);
            }
        });
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static function () {
            NotFoundLog::import();
            NotFoundLog::prune();
        })->name('seo:404')->dailyAt('03:40');
        // İçerikteki iç bağlantılar; yeni kırık bağlantıda bildirim.
        $schedule->call(static fn () => LinkChecker::run())->name('seo:links')->dailyAt('03:50');
        // Yapay zekâ botlarının ziyaret kaydı (render.php → ai.log → seo_ai_hits); eskiler silinir.
        $schedule->call(static function () {
            AiVisibility::import();
            AiVisibility::prune();
        })->name('seo:ai')->hourly();
    }
}
