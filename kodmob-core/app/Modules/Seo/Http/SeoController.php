<?php

namespace App\Modules\Seo\Http;

use App\Core\Activity\Activity;
use App\Core\Content\Locales;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Http\ListQuery;
use App\Core\Support\Options;
use App\Core\Support\Paths;
use App\Modules\Seo\Seo;
use App\Modules\Seo\Services\AiBots;
use App\Modules\Seo\Services\AiVisibility;
use App\Modules\Seo\Services\IndexNow;
use App\Modules\Seo\Services\LinkChecker;
use App\Modules\Seo\Services\NotFoundLog;
use App\Modules\Seo\Services\NotFoundRepository;
use App\Modules\Seo\Services\PageMeta;
use App\Modules\Seo\Services\RedirectRepository;
use App\Modules\Seo\Services\Redirects;
use App\Modules\Seo\Services\SeoAnalyzer;
use App\Modules\Seo\Services\SeoConfig;
use App\Modules\Seo\Services\SeoContent;
use App\Modules\Seo\Services\SeoMeta;
use App\Modules\Site\Render\Renderer;
use App\Modules\Site\Render\Router;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * SEO sayfası (`km_manage_seo`). Ayarlar (modül anahtarları, doğrulama kodları, kurum
 * bilgileri…) ortak `/settings?group=SEO` ucundan yönetilir; burada SEO'ya özgü veriler:
 *
 *   GET  /seo                         durum, modüller, göstergeler, kontrol listesi
 *   GET  /seo/content                 yayındaki kayıtların SEO analizi (dil başına)
 *   POST /seo/analyze                 düzenleyicideki form için canlı analiz
 *   GET|PUT /seo/meta/{type}/{id}     kayıt SEO'su (project | post)
 *   GET  /seo/pages · PUT /seo/pages  sabit sayfaların başlık / açıklama / noindex / görseli
 *   GET|POST /seo/redirects · PUT|DELETE /seo/redirects/{id} · POST /seo/redirects/test
 *   GET  /seo/redirects/export · POST /seo/redirects/import (CSV)
 *   GET  /seo/404 · POST /seo/404/status · POST /seo/404/delete
 *   GET|PUT /seo/robots               robots.txt ek kuralları ve önizleme
 *   GET  /seo/indexnow · POST /seo/indexnow/submit
 */
final class SeoController extends Controller
{
    public function overview(): array
    {
        Redirects::importHits();
        NotFoundLog::import();
        $state = Seo::state();
        $content = $state['enabled'] ? SeoContent::audit() : [];
        $indexable = SeoConfig::indexable();
        $site = SeoConfig::siteUrl();

        $checks = [];
        $add = static function (string $label, string $status, string $detail, string $tab = '') use (&$checks) {
            $checks[] = compact('label', 'status', 'detail', 'tab');
        };
        $add('Arama motorlarına açık', $indexable ? 'ok' : 'warning', $indexable ? 'Site dizine eklenebilir (robots ve X-Robots-Tag açık).' : 'Yayın öncesi: site arama motorlarına kapalı. Canlıya çıkarken .env.production SITE_INDEXABLE=true yapılıp yeniden build alınır.');
        $missing = count(array_filter($content, static fn ($r) => $r['description_length'] === 0));
        $add('Açıklaması olmayan sayfa', $missing ? 'warning' : 'ok', $missing ? "{$missing} kayıt açıklamasız." : 'Bütün kayıtların açıklaması var.', 'content');
        $long = count(array_filter($content, static fn ($r) => $r['title_length'] > SeoAnalyzer::TITLE_MAX));
        $add('Uzun başlık', $long ? 'warning' : 'ok', $long ? "{$long} kaydın başlığı arama sonucunda kesilebilir." : 'Başlık uzunlukları uygun.', 'content');
        $dupes = count(array_filter($content, static fn ($r) => $r['duplicate']));
        $add('Aynı başlık', $dupes ? 'warning' : 'ok', $dupes ? "{$dupes} kayıt aynı dilde başka bir kayıtla aynı başlığa sahip." : 'Başlıklar benzersiz.', 'content');
        $new404 = (int) DB::table('seo_404')->where('status', 'new')->where('bot', false)->count();
        $add('Bulunamayan adresler', $new404 > 0 ? 'warning' : 'ok', $new404 ? "{$new404} adres 404 veriyor; yönlendirme ekleyin ya da yoksayın." : 'Yeni 404 yok.', '404');
        $verified = array_filter(['google', 'bing'], static fn ($k) => trim((string) Seo::setting('seo.verify_'.$k)) !== '');
        $add('Arama konsolu doğrulaması', count($verified) === 2 ? 'ok' : 'warning', count($verified) === 2 ? 'Google ve Bing doğrulandı.' : 'Google Search Console ve Bing Webmaster doğrulama kodlarını girin.', 'settings');
        if ($state['modules']['ai'] ?? false) {
            $blocked = array_keys(array_filter(array_intersect_key(AiVisibility::blockedBots(), array_flip(['GPTBot', 'ClaudeBot', 'PerplexityBot']))));
            $add('Yapay zekâ botları', $blocked ? 'warning' : 'ok', $blocked ? 'Engelli: '.implode(', ', $blocked).'; bu yapay zekâlar sitenizi kaynak gösteremez.' : 'ChatGPT, Claude ve Perplexity siteyi okuyabilir; ziyaretler Yapay zekâ sekmesinde.', 'ai');
        }
        $add('Varsayılan paylaşım görseli', (int) Seo::setting('seo.default_image') > 0 ? 'ok' : 'warning', (int) Seo::setting('seo.default_image') > 0 ? 'Var.' : 'Sosyal medyada görselsiz sayfalar boş kart gösterir.', 'settings');

        return [
            'state' => $state,
            'modules' => array_map(static fn ($key, $m) => ['key' => $key, 'label' => $m['label'], 'description' => $m['description'], 'icon' => $m['icon'], 'on' => $state['modules'][$key]], array_keys(Seo::modules()), Seo::modules()),
            'indexable' => $indexable,
            'urls' => ['sitemap' => $site.'/sitemap.xml', 'robots' => $site.'/robots.txt', 'llms' => $site.'/llms.txt', 'llms_full' => $site.'/llms-full.txt'],
            'stats' => [
                'records' => count($content),
                'score' => $content ? (int) round(array_sum(array_column($content, 'score')) / count($content)) : null,
                'issues' => count(array_filter($content, static fn ($r) => $r['issues'] > 0)),
                'noindex' => count(array_filter($content, static fn ($r) => $r['noindex'])),
                'redirects' => (int) DB::table('seo_redirects')->where('active', true)->count(),
                'redirect_hits' => (int) DB::table('seo_redirects')->sum('hits'),
                'not_found' => $new404,
            ],
            'checks' => $checks,
        ];
    }

    public function content(): array
    {
        return ['items' => SeoContent::audit()];
    }

    public function analyze(Request $request): array
    {
        $in = $this->input($request);
        $locale = Locales::normalize((string) ($in['locale'] ?? ''));

        return SeoAnalyzer::analyze($in + ['suffix' => SeoContent::titleSuffix($locale)]);
    }

    public function meta(string $type, int $id): array
    {
        return SeoMeta::admin($type, $id);
    }

    public function saveMeta(Request $request, string $type, int $id): array
    {
        $changes = SeoMeta::save($type, $id, $this->input($request));
        Activity::type('seo.meta_updated');
        Activity::subject($type, $id, (string) ($type === 'post' ? DB::table('posts')->where('id', $id)->value('title') : DB::table('contents')->where('id', $id)->value('title')));
        Activity::with(['changes' => $changes, 'fields' => []]);
        if (! $changes) {
            Activity::skip();
        }

        return SeoMeta::admin($type, $id);
    }

    public function pages(): array
    {
        return ['items' => PageMeta::list()];
    }

    public function savePage(Request $request): array
    {
        $in = $this->input($request);
        $row = PageMeta::save((string) ($in['route'] ?? ''), (string) ($in['locale'] ?? ''), $in);
        SeoConfig::compile();
        Activity::type('seo.page_updated');
        Activity::subject('seo_page', 0, ($in['route'] ?? '').' · '.strtoupper((string) ($in['locale'] ?? '')));
        Activity::with(['fields' => array_keys(array_filter($row))]);

        return ['items' => PageMeta::list()];
    }

    // ------------------------------------------------------------ yönlendirmeler

    public function redirects(Request $request): array
    {
        Redirects::importHits();

        return (new RedirectRepository)->paginate(ListQuery::fromRequest($request, 50))->toArray();
    }

    public function storeRedirect(Request $request): JsonResponse
    {
        $row = Redirects::save(null, $this->input($request));
        $this->afterRedirect($row, 'seo.redirect_created');

        return $this->created($row);
    }

    public function updateRedirect(Request $request, int $id): array
    {
        $row = Redirects::save($id, $this->input($request));
        $this->afterRedirect($row, 'seo.redirect_updated');

        return $row;
    }

    public function deleteRedirect(int $id): Response
    {
        $row = Redirects::delete($id);
        $this->afterRedirect($row, 'seo.redirect_deleted');

        return $this->noContent();
    }

    public function testRedirect(Request $request): array
    {
        return Redirects::test((string) ($this->input($request)['url'] ?? ''));
    }

    public function exportRedirects(): Response
    {
        return response(Redirects::exportCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="yonlendirmeler-'.date('Y-m-d').'.csv"',
        ]);
    }

    public function importRedirects(Request $request): array
    {
        $file = $request->file('file');
        $csv = $file ? (string) file_get_contents($file->getRealPath()) : (string) ($this->input($request)['csv'] ?? '');
        if (trim($csv) === '') {
            throw ValidationException::field('file', 'CSV dosyası boş.');
        }
        $result = Redirects::importCsv($csv);
        SeoConfig::compile();
        Activity::type('seo.redirects_imported');
        Activity::subject('seo', 0, 'CSV');
        Activity::with(['added' => $result['added'], 'updated' => $result['updated'], 'errors' => count($result['errors']), 'fields' => []]);

        return $result;
    }

    private function afterRedirect(array $row, string $type): void
    {
        SeoConfig::compile();
        Activity::type($type);
        Activity::subject('redirect', $row['id'], $row['source']);
        Activity::with(['source' => $row['source'], 'target' => $row['target'], 'code' => $row['code'], 'fields' => []]);
        // 404 listesinde bu adres "yönlendirildi" olur.
        if (! $row['is_regex'] && $type !== 'seo.redirect_deleted') {
            DB::table('seo_404')->where('path_hash', sha1($row['source']))->orWhere('path_hash', sha1($row['source'].'/'))->update(['status' => 'redirected']);
        }
    }

    // ------------------------------------------------------------ 404

    /** SEO > Yapay zekâ: denetim, bot ziyaretleri (son 30 gün), en çok okunan sayfalar, içerik hazırlığı. */
    public function ai(): array
    {
        return AiVisibility::report();
    }

    public function notFound(Request $request): array
    {
        NotFoundLog::import();

        return (new NotFoundRepository)->paginate(ListQuery::fromRequest($request, 50))->toArray();
    }

    public function notFoundStatus(Request $request): array
    {
        $in = $this->input($request);
        $count = NotFoundLog::setStatus((array) ($in['ids'] ?? []), (string) ($in['status'] ?? ''));
        Activity::type('seo.404_status');
        Activity::subject('seo', 0, '404');
        Activity::with(['count' => $count, 'status' => (string) ($in['status'] ?? ''), 'fields' => []]);

        return ['updated' => $count];
    }

    public function notFoundDelete(Request $request): array
    {
        $count = NotFoundLog::delete((array) ($this->input($request)['ids'] ?? []));
        Activity::type('seo.404_deleted');
        Activity::subject('seo', 0, '404');
        Activity::with(['count' => $count, 'fields' => []]);

        return ['deleted' => $count];
    }

    // ------------------------------------------------------------ kırık bağlantılar

    /** İçerikteki kırık / yönlenen iç bağlantılar (son denetim; hiç yoksa şimdi denetler). */
    public function links(): array
    {
        $last = LinkChecker::last();

        return $last['checked_at'] === null ? LinkChecker::run(false) : $last;
    }

    public function checkLinks(): array
    {
        return LinkChecker::run(false);
    }

    /** Yönlenen iç bağlantıları içerikte yeni adresleriyle değiştirir. */
    public function fixLinks(): array
    {
        $result = LinkChecker::fixRedirects();
        Activity::type('seo.links_fixed');
        Activity::subject('seo', 0, 'Bağlantılar');
        Activity::with(['count' => $result['fixed'], 'fields' => []]);

        return $result;
    }

    // ------------------------------------------------------------ robots ve IndexNow

    public function robots(): array
    {
        return [
            'extra' => (string) Options::get(SeoConfig::ROBOTS_OPTION, ''),
            'preview' => SeoConfig::indexable() ? (Seo::on('robots') ? SeoConfig::robots() : SeoConfig::defaultRobots()) : "User-agent: *\nDisallow: /\n",
            'indexable' => SeoConfig::indexable(),
            'ai_bots' => AiBots::robotsTokens(),
        ];
    }

    public function saveRobots(Request $request): array
    {
        $extra = trim(str_replace("\r\n", "\n", (string) ($this->input($request)['extra'] ?? '')));
        foreach (preg_split('/\n/', $extra) ?: [] as $n => $line) {
            if (trim($line) !== '' && ! str_starts_with(trim($line), '#') && ! preg_match('/^(User-agent|Allow|Disallow|Crawl-delay|Sitemap|Host|Clean-param)\s*:/i', trim($line))) {
                throw ValidationException::field('extra', ($n + 1).'. satır robots.txt kuralı değil: '.mb_substr($line, 0, 60));
            }
        }
        Options::set(SeoConfig::ROBOTS_OPTION, mb_substr($extra, 0, 5000));
        SeoConfig::compile();
        Activity::type('seo.robots_updated');
        Activity::subject('seo', 0, 'robots.txt');
        Activity::with(['fields' => []]);

        return $this->robots();
    }

    public function indexNow(): array
    {
        return [
            'on' => Seo::on('indexnow'),
            'key' => Seo::on('indexnow') ? IndexNow::key() : (string) Seo::setting('seo.indexnow_key'),
            'indexable' => SeoConfig::indexable(),
            'history' => IndexNow::history(),
        ];
    }

    /** Elle gönderim: verilen adresler ya da (boşsa) site haritasındaki bütün adresler. */
    public function submitIndexNow(Request $request): array
    {
        if (! Seo::on('indexnow')) {
            throw ValidationException::field('indexnow', 'IndexNow modülü kapalı.');
        }
        $urls = array_values(array_filter(array_map('trim', (array) ($this->input($request)['urls'] ?? []))));
        if (! $urls) {
            preg_match_all('#<loc>([^<]+)</loc>#', (new Renderer(Router::load(Paths::siteRoot())))->sitemap(), $m);
            $urls = array_map(static fn ($u) => html_entity_decode($u, ENT_XML1), $m[1]);
        }
        $result = IndexNow::submit($urls);
        Activity::skip(); // seo.indexnow_sent olayını servis yazar

        return $result + ['history' => IndexNow::history()];
    }
}
