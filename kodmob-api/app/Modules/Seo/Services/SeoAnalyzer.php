<?php

namespace App\Modules\Seo\Services;

use App\Core\Support\Text;

/**
 * İçerik SEO analizi (kodmob-seo-core'daki "SEO analizi"nin karşılığı). Düzenleyicide
 * yazarken ve SEO > İçerik listesinde aynı kurallar kullanılır.
 *
 * Girdi: title (sekmede görünecek tam başlık), description, focus, slug, html ya da text,
 * cover (bool), kind (post | kaydın türü: project…), noindex, suffix (başlık eki, ör. " · Kodmob").
 * Çıktı: { score 0–100, checks: [{ key, status: good|ok|bad, message }] }.
 */
final class SeoAnalyzer
{
    public const TITLE_MIN = 30;

    public const TITLE_MAX = 60;

    public const DESCRIPTION_MIN = 120;

    public const DESCRIPTION_MAX = 160;

    /** @return array{score:int,checks:list<array{key:string,status:string,message:string}>,words:int} */
    public static function analyze(array $in): array
    {
        // Blog yazısı ya da kendi sayfası olan bir kayıt (proje vb.; kısa metin, ara başlık beklenmez).
        $kind = ($in['kind'] ?? 'post') === 'post' ? 'post' : 'project';
        $title = trim((string) ($in['title'] ?? ''));
        $fullTitle = $title.(string) ($in['suffix'] ?? '');
        $description = trim((string) ($in['description'] ?? ''));
        $focus = trim((string) ($in['focus'] ?? ''));
        $slug = (string) ($in['slug'] ?? '');
        $html = (string) ($in['html'] ?? '');
        $text = trim(($html !== '' ? Text::stripTags($html) : '').' '.(string) ($in['text'] ?? ''));
        $words = self::words($text);
        $checks = [];
        $add = static function (string $key, string $status, string $message) use (&$checks) {
            $checks[] = compact('key', 'status', 'message');
        };

        if (! empty($in['noindex'])) {
            $add('noindex', 'bad', 'Sayfa arama motorlarına kapalı (noindex); puan yalnızca bilgi içindir.');
        }

        $len = mb_strlen($fullTitle);
        $add('title_length', $title === '' ? 'bad' : ($len >= self::TITLE_MIN && $len <= self::TITLE_MAX ? 'good' : 'ok'),
            $title === '' ? 'Başlık boş.' : sprintf('Başlık %d karakter (ekiyle; önerilen %d–%d). %s', $len, self::TITLE_MIN, self::TITLE_MAX, $len > self::TITLE_MAX ? 'Arama sonucunda kesilebilir.' : ($len < self::TITLE_MIN ? 'Biraz daha açıklayıcı olabilir.' : '')));

        $d = mb_strlen($description);
        $add('description_length', $d === 0 ? 'bad' : ($d >= self::DESCRIPTION_MIN && $d <= self::DESCRIPTION_MAX ? 'good' : 'ok'),
            $d === 0 ? 'Açıklama boş: arama motoru sayfadan rastgele bir parça seçer.' : sprintf('Açıklama %d karakter (önerilen %d–%d).', $d, self::DESCRIPTION_MIN, self::DESCRIPTION_MAX));

        if ($focus !== '') {
            $has = static fn (string $haystack) => mb_stripos(self::fold($haystack), self::fold($focus)) !== false;
            $add('focus_title', $has($title) ? 'good' : 'bad', 'Odak kelime başlıkta '.($has($title) ? 'geçiyor.' : 'geçmiyor.'));
            $add('focus_description', $has($description) ? 'good' : 'ok', 'Odak kelime açıklamada '.($has($description) ? 'geçiyor.' : 'geçmiyor.'));
            $inSlug = $slug !== '' && str_contains($slug, Text::slug($focus));
            $add('focus_slug', $inSlug ? 'good' : 'ok', 'Odak kelime adreste '.($inSlug ? 'geçiyor.' : 'geçmiyor.'));
            $intro = mb_substr($text, 0, 400);
            $add('focus_intro', $has($intro) ? 'good' : 'ok', 'Odak kelime ilk paragrafta '.($has($intro) ? 'geçiyor.' : 'geçmiyor.'));
            $count = $words > 0 ? mb_substr_count(self::fold($text), self::fold($focus)) : 0;
            $density = $words > 0 ? $count / max(1, $words) * 100 * max(1, count(preg_split('/\s+/', $focus) ?: [])) : 0;
            $add('focus_density', $count === 0 ? 'bad' : ($density > 3.5 ? 'ok' : 'good'),
                $count === 0 ? 'Odak kelime metinde hiç geçmiyor.' : sprintf('Odak kelime metinde %d kez geçiyor (%%%s).%s', $count, number_format($density, 1, ',', ''), $density > 3.5 ? ' Fazla tekrar doğal okunmayı bozar.' : ''));
        }

        [$good, $ok] = $kind === 'post' ? [300, 150] : [150, 60];
        $add('content_length', $words >= $good ? 'good' : ($words >= $ok ? 'ok' : 'bad'), sprintf('İçerik %d kelime (önerilen en az %d).', $words, $good));

        if ($kind === 'post') {
            $h1 = preg_match('/<h1[\s>]/i', $html);
            $h2 = preg_match_all('/<h2[\s>]/i', $html);
            $add('headings', $h1 ? 'bad' : ($h2 >= 2 ? 'good' : 'ok'),
                $h1 ? 'İçerikte H1 var; sayfa başlığı zaten H1, içerikte H2 kullanın.' : sprintf('%d ara başlık (H2). Uzun yazıda en az 2 önerilir.', $h2));

            preg_match_all('/<img\b[^>]*>/i', $html, $imgs);
            $noAlt = count(array_filter($imgs[0], static fn ($img) => ! preg_match('/\balt\s*=\s*["\'][^"\']+["\']/i', $img)));
            if ($imgs[0]) {
                $add('image_alt', $noAlt === 0 ? 'good' : 'bad', $noAlt === 0 ? 'Bütün görsellerin alt metni var.' : $noAlt.' görselin alt metni yok.');
            }

            preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $html, $links);
            $internal = count(array_filter($links[1], static fn ($href) => str_starts_with($href, '/') && ! str_starts_with($href, '//')));
            $external = count($links[1]) - $internal;
            $add('internal_links', $internal >= 2 ? 'good' : 'ok', sprintf('%d iç bağlantı (önerilen en az 2: ilgili yazılara, projelere, iletişime).', $internal));
            $add('external_links', $external >= 1 ? 'good' : 'ok', $external >= 1 ? sprintf('%d dış kaynak bağlantısı.', $external) : 'Dış kaynak bağlantısı yok (güvenilir bir kaynağa bağlantı güven verir).');
        }

        $add('cover', ! empty($in['cover']) ? 'good' : 'ok', ! empty($in['cover']) ? 'Kapak görseli var (paylaşımda görünür).' : 'Kapak görseli yok; paylaşımda varsayılan görsel çıkar.');

        $points = ['good' => 2, 'ok' => 1, 'bad' => 0];
        $scored = array_filter($checks, static fn ($c) => $c['key'] !== 'noindex');
        $total = array_sum(array_map(static fn ($c) => $points[$c['status']], $scored));
        $score = $scored ? (int) round($total / (count($scored) * 2) * 100) : 0;

        return ['score' => $score, 'checks' => array_values($checks), 'words' => $words];
    }

    public static function words(string $text): int
    {
        return count(preg_split('/[\s\p{P}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** Türkçe büyük/küçük ve aksan farkını yok sayan karşılaştırma için. */
    private static function fold(string $text): string
    {
        return mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
    }
}
