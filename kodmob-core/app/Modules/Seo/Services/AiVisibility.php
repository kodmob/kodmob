<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\Locales;
use App\Core\Support\Clock;
use App\Core\Support\Options;
use App\Core\Support\SiteManifest;
use App\Modules\Seo\Seo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Yapay zekâ görünürlüğü (SEO > Yapay zekâ): sitenin ChatGPT, Claude, Perplexity, Gemini gibi yapay zekâ
 * cevaplarında yer alıp alamayacağı.
 *
 * - Ziyaretler: render.php bot eşleşince storage/framework/seo/ai.log'a yazar (AiBots kataloğu); import()
 *   gün, bot ve adres başına `seo_ai_hits`'e toplar (saatlik iş ve panel açılınca). 180 günden eskisi silinir.
 * - Site denetimi: dizine açıklık, robots.txt'de yapay zekâ botlarının durumu, llms.txt, yapısal veri, site
 *   açıklaması, dil sayısı, sunucuda HTML.
 * - İçerik hazırlığı: yayındaki her kayıt için kural tabanlı puan (yapay zekâ çağrısı yok, ücretsiz): kısa özet,
 *   yeterli içerik, ara başlıklar, güncellik, görsel açıklamaları, dizine açıklık. SEO > İçerik denetiminin
 *   (SeoContent::audit) sonuçlarını kullanır.
 */
final class AiVisibility
{
    public const LOG = 'ai.log';

    public const RETENTION_DAYS = 180;

    /** Yapay zekâ cevapları için en çok önem taşıyan botlar (robots durumu bunlardan özetlenir). */
    private const KEY_BOTS = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot'];

    /** ai.log → seo_ai_hits. Döner: işlenen satır sayısı. */
    public static function import(): int
    {
        $lines = SeoConfig::drain(self::LOG);
        $rows = [];
        $canonical = array_change_key_case(array_combine(array_keys(AiBots::BOTS), array_keys(AiBots::BOTS)));
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            $bot = $canonical[strtolower((string) ($data['b'] ?? ''))] ?? null;
            if (! is_array($data) || $bot === null) {
                continue;
            }
            $at = Clock::carbon((string) ($data['t'] ?? 'now'));
            $day = $at->toDateString();
            $path = mb_substr((string) ($data['p'] ?? '/'), 0, 255) ?: '/';
            $key = sha1($day.'|'.$bot.'|'.$path);
            $rows[$key] ??= ['day' => $day, 'bot' => $bot, 'path' => $path, 'key_hash' => $key, 'hits' => 0, 'status' => 200, 'last_at' => $at];
            $rows[$key]['hits']++;
            if ($at >= $rows[$key]['last_at']) {
                $rows[$key]['last_at'] = $at;
                $rows[$key]['status'] = (int) ($data['c'] ?? 200);
            }
        }
        foreach (array_chunk($rows, 200, true) as $chunk) {
            DB::transaction(static function () use ($chunk) {
                $existing = DB::table('seo_ai_hits')->whereIn('key_hash', array_keys($chunk))->get()->keyBy('key_hash');
                foreach ($chunk as $key => $row) {
                    $last = $row['last_at']->format('Y-m-d H:i:s');
                    if ($old = $existing[$key] ?? null) {
                        $newer = $last >= (string) $old->last_at;
                        DB::table('seo_ai_hits')->where('id', $old->id)->update([
                            'hits' => (int) $old->hits + $row['hits'],
                            'status' => $newer ? $row['status'] : (int) $old->status,
                            'last_at' => $newer ? $last : $old->last_at,
                        ]);
                    } else {
                        DB::table('seo_ai_hits')->insert(['last_at' => $last] + $row);
                    }
                }
            });
        }

        return count($lines);
    }

    public static function prune(): int
    {
        return DB::table('seo_ai_hits')->where('day', '<', Carbon::today(config('app.timezone'))->subDays(self::RETENTION_DAYS)->toDateString())->delete();
    }

    /** Panel: denetim, botlar, sayfalar, günlük dağılım, içerik hazırlığı. */
    public static function report(int $days = 30): array
    {
        self::import();
        $since = Carbon::today(config('app.timezone'))->subDays($days - 1)->toDateString();
        $hits = DB::table('seo_ai_hits')->where('day', '>=', $since)->get();
        $blocked = self::blockedBots();

        $bots = [];
        foreach ($hits->groupBy('bot') as $bot => $rows) {
            $bots[] = ['name' => $bot] + AiBots::describe((string) $bot) + [
                'hits' => (int) $rows->sum('hits'),
                'pages' => $rows->pluck('path')->unique()->count(),
                'last_at' => Clock::atom((string) $rows->max('last_at')),
                'blocked' => $blocked[$bot] ?? false,
            ];
        }
        usort($bots, static fn ($a, $b) => $b['hits'] <=> $a['hits']);

        // Yapay zekânın (arama motorları hariç) en çok okuduğu sayfalar.
        $ai = $hits->filter(static fn ($r) => (AiBots::BOTS[$r->bot][2] ?? '') !== 'engine');
        $pages = $ai->groupBy('path')->map(static fn ($rows, $path) => [
            'path' => (string) $path,
            'hits' => (int) $rows->sum('hits'),
            'bots' => $rows->pluck('bot')->unique()->values()->all(),
            'last_at' => Clock::atom((string) $rows->max('last_at')),
        ])->sortByDesc('hits')->take(15)->values()->all();

        $daily = [];
        for ($d = Carbon::parse($since); $d->toDateString() <= Carbon::today(config('app.timezone'))->toDateString(); $d->addDay()) {
            $daily[$d->toDateString()] = array_fill_keys(array_keys(AiBots::PURPOSES), 0);
        }
        foreach ($hits as $row) {
            $purpose = AiBots::BOTS[$row->bot][2] ?? 'training';
            $daily[(string) $row->day][$purpose] = ($daily[(string) $row->day][$purpose] ?? 0) + (int) $row->hits;
        }

        $totals = array_fill_keys(array_keys(AiBots::PURPOSES), 0);
        foreach ($bots as $bot) {
            $totals[$bot['purpose']] += $bot['hits'];
        }
        $content = self::content();

        return [
            'days' => $days,
            'since' => $since,
            'checks' => self::checks($totals),
            'totals' => $totals,
            'purposes' => AiBots::PURPOSES,
            'bots' => $bots,
            'catalog' => array_map(static fn ($name) => ['name' => $name] + AiBots::describe($name) + ['blocked' => $blocked[$name] ?? false], array_keys(AiBots::BOTS)),
            'pages' => $pages,
            'daily' => array_map(static fn ($day, $counts) => ['day' => $day] + $counts, array_keys($daily), $daily),
            'content' => $content,
            'score' => $content ? (int) round(array_sum(array_column($content, 'score')) / count($content)) : null,
        ];
    }

    /**
     * robots.txt'ye göre engelli botlar: site dizine kapalıysa hepsi; "yapay zekâyı engelle" seçiliyse arama
     * motorları dışındakiler; ek kurallarda bir bota `Disallow: /` yazılmışsa o bot.
     *
     * @return array<string,bool>
     */
    public static function blockedBots(): array
    {
        $all = array_keys(AiBots::BOTS);
        if (! SeoConfig::indexable()) {
            return array_fill_keys($all, true);
        }
        $blocked = [];
        if (Seo::on('robots')) {
            if (Seo::setting('seo.ai_bots') === 'block') {
                foreach (AiBots::robotsTokens() as $token) {
                    $blocked[$token] = true;
                }
            }
            $agents = [];
            $grouping = false;
            foreach (preg_split('/\r\n|\r|\n/', (string) Options::get(SeoConfig::ROBOTS_OPTION, '')) as $line) {
                $line = trim((string) preg_replace('/#.*/', '', $line));
                if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
                    $agents = $grouping ? [trim($m[1])] : [...$agents, trim($m[1])];
                    $grouping = false;
                } elseif (preg_match('/^disallow:\s*\/\s*$/i', $line)) {
                    $grouping = true;
                    foreach ($agents as $agent) {
                        foreach ($agent === '*' ? $all : array_filter($all, static fn ($n) => strcasecmp($n, $agent) === 0) as $name) {
                            $blocked[$name] = true;
                        }
                    }
                } elseif ($line !== '') {
                    $grouping = true;
                }
            }
        }

        return array_intersect_key($blocked, array_flip($all)) + array_fill_keys($all, false);
    }

    /**
     * Site düzeyinde denetim.
     *
     * @param  array<string,int>  $totals  amaç → son dönemdeki ziyaret
     * @return list<array{key:string,label:string,status:string,detail:string,tab:string}>
     */
    public static function checks(array $totals = []): array
    {
        $checks = [];
        $add = static function (string $key, string $label, string $status, string $detail, string $tab = '') use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'detail', 'tab');
        };
        $indexable = SeoConfig::indexable();
        $add('indexable', 'Arama motorlarına ve yapay zekâya açık', $indexable ? 'ok' : 'bad', $indexable
            ? 'Site dizine eklenebilir; robots.txt botlara kapalı değil.'
            : 'Yayın öncesi modunda: robots.txt her şeyi engelliyor. Canlıya çıkarken .env.production SITE_INDEXABLE=true ile build alınır.');

        $blocked = array_keys(array_filter(array_intersect_key(self::blockedBots(), array_flip(self::KEY_BOTS))));
        if ($indexable) {
            $add('robots', 'Yapay zekâ botları robots.txt\'de', $blocked ? 'warning' : 'ok', $blocked
                ? 'Engelli: '.implode(', ', $blocked).'. Bu botların yapay zekâsı sitenizi cevaplarında kaynak gösteremez. Bilinçli bir tercih değilse Robots ve llms sekmesinden izin verin.'
                : 'ChatGPT, Claude ve Perplexity botları siteyi okuyabilir.', 'robots');
        }

        $add('ssr', 'İçerik sunucuda hazır', 'ok', 'Sayfalar sunucuda HTML olarak gelir: JavaScript çalıştırmayan yapay zekâ botları da içeriği okur.');
        $add('llms', 'llms.txt', Seo::on('llms') ? 'ok' : 'warning', Seo::on('llms')
            ? 'Yapay zekâ asistanları için site özeti ve tam metin yayında (/llms.txt, /llms-full.txt).'
            : 'Kapalı. Yapay zekâ asistanlarına sitenin kısa bir haritasını veren llms.txt\'yi Genel bakıştan açın.', 'overview');
        $add('schema', 'Yapısal veri', Seo::on('schema') ? 'ok' : 'warning', Seo::on('schema')
            ? 'Kurum bilgisi ve sayfa türleri (proje, yazı) yapısal veriyle işaretli; yapay zekâ kimin ne sunduğunu doğru anlar.'
            : 'Kapalı. Kurum adı, adresi ve iletişim bilgileri yapısal veriyle verilmezse yapay zekâ bunları tahmin eder.', 'settings');

        $description = trim(SiteManifest::string('description'));
        $add('description', 'Site açıklaması', $description !== '' ? 'ok' : 'warning', $description !== ''
            ? 'Var: "'.mb_strimwidth($description, 0, 90, '…').'"'
            : 'Site açıklaması boş; yapay zekâ sitenin ne yaptığını tek cümlede bulamaz.');

        $languages = count(Locales::active());
        $add('languages', 'Dil sayısı', $languages > 1 ? 'ok' : 'info', $languages > 1
            ? "{$languages} dilde içerik: yapay zekâ cevabı soranın diline göre doğru sayfayı gösterebilir."
            : 'Tek dil. Yabancı müşteri hedefleniyorsa Çoklu Dil sayfasından dil eklenebilir.');

        $ai = array_sum(array_diff_key($totals, ['engine' => 0]));
        $add('visits', 'Yapay zekâ ziyaretleri', $ai > 0 ? 'ok' : 'info', $ai > 0
            ? "Son dönemde {$ai} yapay zekâ ziyareti."
            : 'Henüz yapay zekâ botu ziyareti kaydı yok. Kayıt bu sürümle başladı; botlar geldikçe burada görünür.');

        return $checks;
    }

    /**
     * Yayındaki içeriğin yapay zekâ cevabına hazırlığı (kural tabanlı; kayıt ve dil başına).
     *
     * @return list<array<string,mixed>>
     */
    public static function content(): array
    {
        if (! Seo::enabled()) {
            return [];
        }
        $year = Carbon::now()->subYear();
        $out = [];
        foreach (SeoContent::audit() as $row) {
            $isPost = $row['type'] === 'post';
            $words = (int) $row['words'];
            $need = $isPost ? 600 : 150;
            $checks = [];
            $check = static function (string $key, float $value, string $message) use (&$checks) {
                $checks[] = ['key' => $key, 'value' => $value, 'message' => $message];
            };
            $d = (int) $row['description_length'];
            $check('summary', $d >= 50 && $d <= 160 ? 1 : ($d > 0 ? 0.5 : 0), $d === 0 ? 'Kısa özet (açıklama) yok: yapay zekâ cevabı genellikle bu cümleden kurulur.' : ($d < 50 ? 'Özet çok kısa (en az 50 karakter).' : ($d > 160 ? 'Özet uzun (en çok 160 karakter).' : 'Kısa özet var.')));
            $check('depth', $words >= $need ? 1 : ($words >= $need / 2 ? 0.5 : 0), $words >= $need ? "Yeterli içerik ({$words} kelime)." : "İçerik kısa ({$words} kelime; önerilen en az {$need}). Soruya cevap verecek ayrıntı az.");
            if ($isPost) {
                $headings = $row['checks']['headings'] ?? 'ok';
                $check('structure', $headings === 'good' ? 1 : ($headings === 'ok' ? 0.5 : 0), $headings === 'good' ? 'Ara başlıklarla bölümlere ayrılmış.' : 'Ara başlık az: yapay zekâ cevaba uygun bölümü zor seçer (en az 2 ara başlık).');
            }
            $fresh = $row['modified'] && Clock::carbon((string) $row['modified'])->greaterThan($year);
            $check('fresh', $fresh ? 1 : 0, $fresh ? 'Son bir yılda güncellenmiş.' : 'Bir yıldan uzun süredir güncellenmemiş: yapay zekâ güncel kaynakları öne alır.');
            if (isset($row['checks']['image_alt'])) {
                $check('alt', $row['checks']['image_alt'] === 'good' ? 1 : 0, $row['checks']['image_alt'] === 'good' ? 'Görsellerin açıklaması var.' : 'Bazı görsellerin açıklaması (alt metin) yok.');
            }
            $score = $row['noindex'] ? 0 : (int) round(array_sum(array_column($checks, 'value')) / count($checks) * 100);
            $missing = $row['noindex'] ? ['Sayfa arama motorlarına kapalı (noindex): yapay zekâ cevaplarında yer almaz.'] : array_column(array_filter($checks, static fn ($c) => $c['value'] < 1), 'message');
            $out[] = [
                'key' => $row['key'],
                'type' => $row['type'],
                'type_label' => $row['type_label'],
                'id' => $row['id'],
                'locale' => $row['locale'],
                'title' => $row['title'],
                'url' => $row['url'],
                'edit' => $row['edit'],
                'score' => $score,
                'grade' => $score >= 80 ? 'good' : ($score >= 50 ? 'ok' : 'bad'),
                'missing' => $missing,
            ];
        }
        usort($out, static fn ($a, $b) => $a['score'] <=> $b['score']);

        return $out;
    }
}
