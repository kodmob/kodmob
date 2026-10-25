<?php

namespace App\Modules\Seo;

use App\Core\Settings\SettingsService;

/**
 * SEO modülünün açık/kapalı durumu ve alt modül kataloğu (kodmob-seo-core'daki
 * "aktif modüller" listesinin karşılığı).
 *
 * - `seo.enabled` kapalıysa SEO modülü tamamen devre dışıdır: yönlendirme, 404 kaydı,
 *   başlık/açıklama düzenlemeleri, ek şema, doğrulama etiketleri, llms.txt, IndexNow
 *   ve içerik analizi çalışmaz; site temel etiketlerle (sayfa başlığı, özet, canonical,
 *   hreflang) ve varsayılan robots.txt / sitemap.xml ile yayına devam eder. Veriler silinmez.
 * - Açıkken her alt modül ayrıca açılıp kapatılır (`seo.module.<anahtar>`).
 */
final class Seo
{
    public const ENABLED = 'seo.enabled';

    /**
     * Alt modüller: anahtar => [ad, açıklama, ikon, varsayılan].
     *
     * @return array<string,array{label:string,description:string,icon:string,default:bool}>
     */
    public static function modules(): array
    {
        $m = static fn (string $label, string $description, string $icon, bool $default = true) => compact('label', 'description', 'icon', 'default');

        return [
            'meta' => $m('Başlık ve açıklama', 'Sayfa ve kayıt başına SEO başlığı, açıklama, noindex ve canonical.', 'pencil'),
            'analysis' => $m('İçerik analizi', 'Düzenleyicide SEO puanı: başlık ve açıklama uzunluğu, odak kelime, başlıklar, bağlantılar, görseller.', 'activity'),
            'social' => $m('Sosyal paylaşım', 'Varsayılan paylaşım görseli, X (Twitter) hesabı ve kayda özel paylaşım görseli.', 'send'),
            'schema' => $m('Yapılandırılmış veri', 'Kurum / yerel işletme (adres, konum, çalışma saatleri, sosyal profiller) ve WebSite şeması.', 'code'),
            'sitemap' => $m('Site haritası', 'Son değişiklik tarihi ve görseller; noindex sayfalar haritaya girmez.', 'map'),
            'robots' => $m('Robots ve yapay zekâ botları', 'robots.txt ek kuralları; GPTBot, ClaudeBot gibi yapay zekâ tarayıcılarına izin ya da engel.', 'shield-check'),
            'ai' => $m('Yapay zekâ görünürlüğü', 'ChatGPT, Claude, Perplexity, Gemini gibi yapay zekâ botlarının ziyaretleri ve sayfaların yapay zekâ cevaplarına hazırlığı.', 'sparkles'),
            'llms' => $m('llms.txt', 'Yapay zekâ asistanları için site özeti (/llms.txt) ve tam metin (/llms-full.txt).', 'sparkles'),
            'redirects' => $m('Yönlendirmeler', '301, 302, 307 ve 410; düzenli ifade desteği, CSV içe/dışa aktarma, tıklanma sayısı.', 'arrow-up-right'),
            'monitor404' => $m('404 izleme', 'Bulunamayan adresleri kaydeder; tek tıkla yönlendirmeye çevirir.', 'alert-circle'),
            'webmaster' => $m('Site doğrulama', 'Google Search Console, Bing, Yandex ve Baidu doğrulama etiketleri.', 'check-circle'),
            'indexnow' => $m('Anında indeksleme (IndexNow)', 'Yayınlanan ve güncellenen sayfaları Bing, Yandex ve diğer IndexNow motorlarına hemen bildirir.', 'zap', false),
            'toc' => $m('İçindekiler', 'Blog yazılarının başına başlıklardan otomatik içindekiler listesi.', 'list'),
            'links' => $m('Dış bağlantılar', 'Blog yazılarındaki dış bağlantılara yeni sekme ve rel öznitelikleri.', 'link'),
        ];
    }

    public static function enabled(): bool
    {
        try {
            return SettingsService::instance()->bool(self::ENABLED);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Alt modül açık mı (SEO kapalıysa hiçbiri açık değildir). */
    public static function on(string $module): bool
    {
        if (! self::enabled()) {
            return false;
        }
        try {
            return SettingsService::instance()->bool('seo.module.'.$module);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function setting(string $key): mixed
    {
        try {
            return SettingsService::instance()->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{enabled:bool,modules:array<string,bool>} */
    public static function state(): array
    {
        $modules = [];
        foreach (array_keys(self::modules()) as $key) {
            $modules[$key] = (bool) self::setting('seo.module.'.$key);
        }

        return ['enabled' => self::enabled(), 'modules' => $modules];
    }
}
