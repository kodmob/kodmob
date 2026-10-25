<?php

namespace App\Modules\Site\Pages;

use App\Core\Content\ContentRepository;
use App\Core\Content\ContentType;
use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Content\Record;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\I18n\LanguageService;
use App\Modules\Site\Render\Router;

/**
 * Blok sayfaları (içerik türü `pages`, kayıt `page`; SiteModule::contentTypes): panelden açılan, içeriği yalnızca
 * Sayfa düzenleyicinin bloklarından oluşan sayfalar (kampanya, hakkımızda, etkinlik…; docs/done-onyuz-raporu.md Ö4).
 *
 * Adres kökte (`/kampanya/`, `/en/campaign/`): çatının en düşük öncelikli rotası `page` (sitenin kendi adresleri ve
 * eski adres yönlendirmeleri önce eşlenir). Sitenin başka bir sayfasının adresi verilemez. Blokları sayfaya özel
 * bölgede (`page.main@<kimlik>`; Site\Blocks\Layouts). Yeni sayfa taslak başlar; düzenleyicide önizlenir, yayına
 * alınınca ziyaretçi görür.
 */
final class PageType extends ContentType
{
    public const POST_TYPE = 'km_page';

    /** Sayfanın blok bölgesi (şablonda `zone('page.main')`); kayıt başına `page.main@<kimlik>`. */
    public const ZONE = 'page.main';

    /** Sitedeki rotası (kodmob-frontend/vite/framework-routes.js). */
    public const ROUTE = 'page';

    public function postType(): string
    {
        return self::POST_TYPE;
    }

    public function labels(): array
    {
        return ['Blok sayfaları', 'Blok sayfası'];
    }

    public function capabilityLabel(): string
    {
        return 'Blok sayfaları ekleme ve düzenleme (kampanya, hakkımızda…)';
    }

    public function shared(): array
    {
        return [
            'cover' => ['type' => 'image', 'label' => 'Paylaşım görseli', 'help' => 'Sosyal medyada paylaşılınca görünür.', 'section' => 'Genel'],
        ];
    }

    public function localized(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Başlık', 'max' => 120, 'help' => 'Sekmede ve arama sonuçlarında. Boşsa sayfa bu dilde yok.', 'section' => 'İçerik', 'width' => 'half'],
            'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title', 'section' => 'İçerik', 'width' => 'half'],
            'description' => ['type' => 'textarea', 'label' => 'Açıklama', 'max' => 300, 'help' => 'Arama motorlarında ve paylaşımda görünen kısa açıklama.', 'section' => 'İçerik'],
        ];
    }

    /** Blokların kayıt başına bölgesi. */
    public static function zone(int $id): string
    {
        return self::ZONE.'@'.$id;
    }

    /**
     * Sitenin başka bir sayfasının ya da klasörünün (panel, api, media…) adresi verilemez: o önce eşlenir, blok
     * sayfası hiç açılmaz.
     */
    protected function validate(array $data, int $id): array
    {
        $router = Router::load(Paths::siteRoot());
        $errors = [];
        foreach (Locales::all() as $locale) {
            // Boş adres kayıtta başlıktan türetilir (ContentType::fillSlugs): onun da denetlenmesi için.
            $slug = (string) ($data['i18n'][$locale]['slug'] ?? '') ?: Text::slug((string) ($data['i18n'][$locale]['title'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $prefix = $router?->prefixOf($locale) ?? '';
            $match = $router?->match(($prefix !== '' ? '/'.$prefix : '').'/'.$slug.'/', false);
            $taken = $match && in_array($match['kind'], ['page', 'redirect'], true) && ($match['route']['name'] ?? '') !== self::ROUTE;
            // Kökte: sitenin klasörleri ve dil önekleri (sonradan eklenen dil sayfayı gölgelemesin).
            $root = $prefix === '' && (in_array($slug, LanguageService::RESERVED, true) || in_array($slug, array_merge(array_column(Languages::all(), 'code'), array_column(Languages::all(), 'prefix')), true));
            if ($taken || $root) {
                $errors["i18n.{$locale}.slug"] = 'Bu adres sitenin başka bir sayfasında kullanılıyor; başka bir adres yazın.';
            }
        }

        return $errors;
    }

    /**
     * Önizleme (Sayfa düzenleyici): taslak sayfa ve taslaktaki dili de adresiyle açılır.
     *
     * @return array|null sayfanın verisi (localize biçiminde)
     */
    public function previewBySlug(string $slug, string $locale): ?array
    {
        foreach ($this->records(false) as $record) {
            if ($slug !== '' && (string) ($record->data['i18n'][$locale]['slug'] ?? '') === $slug) {
                $data = $record->data;
                if (isset($data['locales'][$locale]) && is_array($data['locales'][$locale])) {
                    $data['locales'][$locale]['status'] = 'publish';
                }

                return $this->localize(new Record($record->id, $record->type, $record->status, $record->position, $data, $record->createdAt, $record->updatedAt), $locale);
            }
        }

        return null;
    }

    /**
     * Taslak sayfayı yayına alır (Sayfa düzenleyicinin "Yayınla"sı; dillerin durumu değişmez).
     *
     * @return bool durum değişti mi
     */
    public function publish(int $id): bool
    {
        $record = $this->get($id);
        if ($record->status === 'publish') {
            return false;
        }
        ContentRepository::update($record->id, self::POST_TYPE, ['status' => 'publish']);
        $this->changed();

        return true;
    }

    /** Yayındaki sayfanın bu dildeki adresi (blok bağlantısı `page:12`); yoksa boş. */
    public static function publishedSlug(int $id, string $locale): string
    {
        foreach ((new self)->publicList($locale) as $item) {
            if ((int) $item['id'] === $id) {
                return (string) $item['slug'];
            }
        }

        return '';
    }

    protected function publicItem(array $item, string $locale): array
    {
        return [
            'id' => $item['id'],
            'order' => $item['order'],
            'slug' => $item['slug'],
            'slugs' => $item['slugs'],
            'title' => $item['title'],
            'description' => $item['description'],
            'cover' => $item['cover'],
            'date' => $item['date'],
            'modified' => $item['modified'],
        ];
    }

    public function seoDefaults(array $entry): array
    {
        return ['title' => (string) ($entry['title'] ?? ''), 'description' => (string) ($entry['description'] ?? '')];
    }

    public function seoText(array $entry): string
    {
        return (string) ($entry['description'] ?? '');
    }

    public function structuredData(array $entry, array $page): array
    {
        return ['@type' => 'WebPage'] + array_diff_key(parent::structuredData($entry, $page), ['@type' => 1]);
    }

    public function llmsText(array $entry): array
    {
        $description = (string) ($entry['description'] ?? '');

        return ['title' => (string) ($entry['title'] ?? ''), 'paragraphs' => $description !== '' ? [$description] : []];
    }

    public function searchItem(array $entry): array
    {
        return ['slug' => (string) ($entry['slug'] ?? ''), 'title' => (string) ($entry['title'] ?? ''), 'text' => (string) ($entry['description'] ?? '')];
    }
}
