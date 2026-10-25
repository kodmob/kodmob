<?php

namespace App\Modules\Site\Legal;

use App\Core\Content\Locales;
use App\Core\Content\PageCache;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Core\Support\HtmlSanitizer;
use App\Core\Support\Text;
use App\Modules\Site\Blocks\Template;
use App\Modules\Site\Render\Router;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Yasal belgenin metni: panelde özelleştirilmişse o (`legal_documents`), yoksa şablon (km-legal.json) açık modüller ve
 * Proje Ayarları › Yasal'la çizilir. Özelleştirilmiş metin sabittir: sonradan açılan modülün bölümü ona eklenmez
 * (panel uyarır; "Şablona dön" yeniden şablona bağlar).
 */
final class LegalPages
{
    private const TABLE = 'legal_documents';

    public function __construct(private readonly ?Router $router = null) {}

    /**
     * @return array{html:string,toc:list<array{id:string,title:string}>,updated:string,customized:bool}|null
     */
    public function render(string $name, string $locale): ?array
    {
        $doc = LegalDocs::find($name);
        if ($doc === null) {
            return null;
        }
        $row = $this->override($name, $locale);
        $html = $row ? (string) $row->body : $this->fromTemplate($doc, $locale);
        [$html, $toc] = self::structure($html);

        return [
            'html' => $html,
            'toc' => $toc,
            'updated' => $row ? substr((string) $row->updated_at, 0, 10) : (string) ($doc['updated'] ?? ''),
            'customized' => $row !== null,
        ];
    }

    /** Şablonun bu projedeki hali (panelde "şablon" önizlemesi ve özelleştirmenin başlangıcı). */
    public function template(string $name, string $locale): string
    {
        return $this->fromTemplate($this->doc($name), $locale);
    }

    /** Panel: belgeyi bu dilde özelleştirir (temizlenmiş HTML). */
    public function save(string $name, string $locale, string $body, ?int $userId): array
    {
        $this->doc($name);
        $this->locale($locale);
        $clean = HtmlSanitizer::clean($body);
        if (trim(Text::stripTags($clean)) === '') {
            throw ValidationException::field('body', 'Belgenin metnini yazın; şablona dönmek için "Şablona dön".');
        }
        DB::table(self::TABLE)->updateOrInsert(['name' => $name, 'locale' => $locale], ['body' => $clean, 'updated_by' => $userId, 'updated_at' => Clock::now()]);
        PageCache::flush();

        return $this->render($name, $locale) ?? [];
    }

    /** Panel: özelleştirmeyi siler, belge yeniden şablondan çizilir. */
    public function reset(string $name, string $locale): void
    {
        $this->doc($name);
        DB::table(self::TABLE)->where('name', $name)->where('locale', $locale)->delete();
        PageCache::flush();
    }

    /**
     * Panelin listesi: açık belgeler, grupları, dil başına durum ve sitedeki adresi.
     *
     * @return list<array<string,mixed>>
     */
    public function adminList(): array
    {
        $rows = DB::table(self::TABLE)->get(['name', 'locale', 'updated_at'])->groupBy('name');
        $out = [];
        foreach (LegalDocs::active() as $name => $doc) {
            $locales = [];
            foreach (Locales::active() as $code) {
                $row = ($rows[$name] ?? collect())->firstWhere('locale', $code);
                $locales[$code] = [
                    'customized' => $row !== null,
                    'updated' => $row ? substr((string) $row->updated_at, 0, 10) : (string) ($doc['updated'] ?? ''),
                    'url' => $this->router?->url($name, $code),
                ];
            }
            $out[] = [
                'name' => $name,
                'title' => LegalDocs::title($doc, Locales::default()),
                'description' => LegalDocs::description($doc, Locales::default()),
                'group' => (string) ($doc['group'] ?? 'general'),
                'groupLabel' => LegalDocs::groupLabel((string) ($doc['group'] ?? 'general'), 'tr'),
                'module' => $doc['module'] ?? null,
                'locales' => $locales,
            ];
        }

        return $out;
    }

    /** @return array<string,array{body:string,updated_at:string}> yedek: belge|dil → özelleştirme */
    public static function export(): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->orderBy('name')->get() as $row) {
            $out[$row->name.'|'.$row->locale] = ['body' => (string) $row->body, 'updated_at' => (string) $row->updated_at];
        }

        return $out;
    }

    /** Yedekten: özelleştirmeler yedektekilerle değişir. */
    public static function import(array $items): int
    {
        DB::table(self::TABLE)->delete();
        $count = 0;
        foreach ($items as $key => $item) {
            [$name, $locale] = array_pad(explode('|', (string) $key, 2), 2, '');
            if (! preg_match('/^[a-z][a-zA-Z0-9]{1,40}$/', $name) || ! preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) || ! is_array($item)) {
                continue;
            }
            DB::table(self::TABLE)->insert(['name' => $name, 'locale' => $locale, 'body' => HtmlSanitizer::clean((string) ($item['body'] ?? '')), 'updated_at' => (string) ($item['updated_at'] ?? Clock::now())]);
            $count++;
        }

        return $count;
    }

    /** "21 Ekim 2026" (dilin ay adıyla). */
    public static function date(string $date, string $locale): string
    {
        try {
            return Carbon::parse($date)->locale($locale)->translatedFormat('j F Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * Başlıklara kimlik (içindekiler bağlantısı) verir, tabloları kaydırılabilir kutuya alır.
     *
     * @return array{0:string,1:list<array{id:string,title:string}>}
     */
    public static function structure(string $html): array
    {
        $toc = [];
        $used = [];
        $html = (string) preg_replace_callback('#<h2(\s[^>]*)?>(.*?)</h2>#is', static function (array $m) use (&$toc, &$used) {
            $title = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $base = Text::slug($title) ?: 'bolum';
            $id = $base;
            for ($i = 2; isset($used[$id]); $i++) {
                $id = $base.'-'.$i;
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'title' => $title];

            return '<h2 id="'.$id.'">'.$m[2].'</h2>';
        }, $html);
        // Tablo dar ekranda taşmasın: özelleştirilmiş metinde sınıflar temizlendiğinden kutu burada eklenir (şablonda var).
        if (! str_contains($html, 'table-wrap')) {
            $html = (string) preg_replace(['#<table\b#i', '#</table>#i'], ['<div class="table-wrap"><table', '</table></div>'], $html);
        }

        return [$html, $toc];
    }

    // ------------------------------------------------------------ iç

    private function fromTemplate(array $doc, string $locale): string
    {
        return trim(Template::render(LegalDocs::template($doc, $locale), LegalData::for($locale, $this->router)));
    }

    private function override(string $name, string $locale): ?object
    {
        return DB::table(self::TABLE)->where('name', $name)->where('locale', $locale)->first();
    }

    private function doc(string $name): array
    {
        return LegalDocs::find($name) ?? throw NotFoundException::message('Belge bulunamadı ya da modülü kapalı.');
    }

    private function locale(string $locale): void
    {
        if (! in_array($locale, Locales::all(), true)) {
            throw ValidationException::field('locale', 'Geçersiz dil.');
        }
    }
}
