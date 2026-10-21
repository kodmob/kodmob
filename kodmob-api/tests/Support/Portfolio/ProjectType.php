<?php

namespace Tests\Support\Portfolio;

use App\Core\Content\ContentType;
use App\Core\Support\SiteManifest;

/**
 * Test türü (çatının testleri projenin koduna bağlı kalmasın): portföy sitesi örneği, kodmob.com'un türünden.
 *
 * Projeler (vaka çalışmaları; kodmob.com'un türü). Ziyaretçi çıktısı proje sayfasının
 * (tv/src/profiles/kodmob/pages/project) beklediği biçimdir; arama motoru başlığı, yapısal veri ve
 * site içi arama metni bu türün kancalarındadır.
 */
final class ProjectType extends ContentType
{
    public const POST_TYPE = 'km_project';

    /** Kategoriler: profilin sözlüğünden (i18n → projects.categories), manifestle gelir. */
    public const VOCAB = 'projectCategories';

    public function postType(): string
    {
        return self::POST_TYPE;
    }

    public function labels(): array
    {
        // Panelde "Portföy": "Proje" panelin çalışma alanıdır (proje seçici); sitede bölüm adı Projeler.
        return ['Portföy', 'Çalışma'];
    }

    public function capabilityLabel(): string
    {
        return 'Portföyü ekleme ve düzenleme';
    }

    public function shared(): array
    {
        return [
            'cover' => ['type' => 'image', 'label' => 'Kapak görseli', 'help' => 'Kartlarda ve proje sayfasında dizüstü çerçevesinde görünür. En az 1600×1000 önerilir.', 'section' => 'Görseller'],
            'gallery' => ['type' => 'gallery', 'label' => 'Ekran görüntüleri', 'help' => 'Proje sayfasındaki galeri. Açıklamalar dil sekmelerinde.', 'captions' => 'captions', 'section' => 'Görseller'],
            'featured' => ['type' => 'bool', 'label' => 'Öne çıkan', 'help' => 'Ana sayfadaki "Öne çıkan projeler" ve hero kartlarında görünür.', 'section' => 'Genel', 'width' => 'half'],
            'icon' => ['type' => 'icon', 'label' => 'İkon', 'default' => 'layers', 'section' => 'Genel', 'width' => 'half'],
            'categories' => ['type' => 'multiselect', 'label' => 'Kategoriler', 'options' => SiteManifest::vocab(self::VOCAB), 'help' => 'Proje listesindeki filtreler ve hizmet sayfasındaki ilgili projeler.', 'section' => 'Genel'],
            'client' => ['type' => 'text', 'label' => 'Müşteri', 'section' => 'Genel', 'width' => 'third'],
            'year' => ['type' => 'text', 'label' => 'Yıl', 'max' => 20, 'section' => 'Genel', 'width' => 'third'],
            'live_url' => ['type' => 'url', 'label' => 'Canlı adres', 'help' => '"Canlıyı İncele" düğmesi.', 'section' => 'Genel', 'width' => 'third'],
        ];
    }

    public function localized(): array
    {
        $icons = ['type' => 'icon', 'label' => 'İkon', 'default' => 'check'];

        return [
            'title' => ['type' => 'text', 'label' => 'Başlık', 'help' => 'Boşsa proje bu dilde yayınlanmaz.', 'section' => 'Temel', 'width' => 'half'],
            'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title', 'help' => 'Boşsa başlıktan üretilir.', 'section' => 'Temel', 'width' => 'half'],
            'tagline' => ['type' => 'text', 'label' => 'Kısa tanım', 'help' => 'Ör. "Dijital Tarım Platformu".', 'section' => 'Temel'],
            'summary' => ['type' => 'textarea', 'label' => 'Özet', 'max' => 400, 'help' => 'Kartta ve proje sayfasının başında görünen 1–2 cümle.', 'section' => 'Temel'],
            'sector' => ['type' => 'text', 'label' => 'Sektör', 'section' => 'Bilgi çubuğu', 'width' => 'third'],
            'duration' => ['type' => 'text', 'label' => 'Süre', 'section' => 'Bilgi çubuğu', 'width' => 'third'],
            'platform' => ['type' => 'text', 'label' => 'Platform', 'section' => 'Bilgi çubuğu', 'width' => 'third'],
            'tags' => ['type' => 'list', 'label' => 'Etiketler', 'max' => 8, 'help' => 'Kartta ilk ikisi görünür.', 'section' => 'Bilgi çubuğu'],
            'about' => ['type' => 'textarea', 'label' => 'Proje hakkında', 'help' => 'Boş satır yeni paragraf.', 'section' => 'Hikâye'],
            'problem' => ['type' => 'textarea', 'label' => 'Problem', 'max' => 1000, 'section' => 'Hikâye', 'width' => 'third'],
            'solution' => ['type' => 'textarea', 'label' => 'Çözüm', 'max' => 1000, 'section' => 'Hikâye', 'width' => 'third'],
            'result' => ['type' => 'textarea', 'label' => 'Sonuç', 'max' => 1000, 'section' => 'Hikâye', 'width' => 'third'],
            'features' => ['type' => 'rows', 'label' => 'Öne çıkan özellikler', 'max' => 12, 'section' => 'Özellikler', 'columns' => [
                'icon' => $icons,
                'title' => ['type' => 'text', 'label' => 'Başlık'],
                'text' => ['type' => 'text', 'label' => 'Açıklama'],
            ]],
            'metrics' => ['type' => 'rows', 'label' => 'Sonuç metrikleri', 'max' => 8, 'section' => 'Özellikler', 'columns' => [
                'value' => ['type' => 'text', 'label' => 'Değer', 'max' => 20],
                'label' => ['type' => 'text', 'label' => 'Etiket'],
                'text' => ['type' => 'text', 'label' => 'Açıklama'],
            ]],
            'tech' => ['type' => 'rows', 'label' => 'Teknolojiler', 'max' => 16, 'section' => 'Özellikler', 'columns' => [
                'name' => ['type' => 'text', 'label' => 'Ad'],
                'text' => ['type' => 'text', 'label' => 'Açıklama'],
            ]],
            'captions' => ['type' => 'list', 'label' => 'Galeri açıklamaları', 'hidden' => true],
            'testimonial_quote' => ['type' => 'textarea', 'label' => 'Müşteri yorumu', 'max' => 800, 'section' => 'Müşteri yorumu'],
            'testimonial_name' => ['type' => 'text', 'label' => 'Yorum sahibi', 'section' => 'Müşteri yorumu', 'width' => 'half'],
            'testimonial_role' => ['type' => 'text', 'label' => 'Unvan', 'section' => 'Müşteri yorumu', 'width' => 'half'],
        ];
    }

    protected function listSubtitle(array $data): string
    {
        $subtitle = parent::listSubtitle($data);
        $client = $data['client'] ?? '';

        return trim($subtitle.($client !== '' && $client !== $this->listTitle($data) ? ' · '.$client : ''), ' ·');
    }

    protected function publicItem(array $item, string $locale): array
    {
        return [
            'id' => $item['id'],
            'order' => $item['order'],
            'featured' => (bool) $item['featured'],
            'icon' => $item['icon'],
            'categories' => $item['categories'],
            'client' => $item['client'],
            'year' => $item['year'],
            'liveUrl' => $item['live_url'],
            'cover' => $item['cover'],
            'gallery' => $item['gallery'],
            'date' => $item['date'],
            'modified' => $item['modified'],
            'slug' => $item['slug'],
            'slugs' => $item['slugs'],
            'languages' => array_keys($item['slugs']),
            'title' => $item['title'],
            'tagline' => $item['tagline'],
            'summary' => $item['summary'],
            'sector' => $item['sector'],
            'duration' => $item['duration'],
            'platform' => $item['platform'],
            'tags' => $item['tags'],
            'about' => self::paragraphs($item['about']),
            'problem' => $item['problem'],
            'solution' => $item['solution'],
            'result' => $item['result'],
            'features' => $item['features'],
            'metrics' => $item['metrics'],
            'tech' => $item['tech'],
            'captions' => $item['captions'],
            'testimonial' => $item['testimonial_quote'] !== '' ? [
                'quote' => $item['testimonial_quote'],
                'name' => $item['testimonial_name'],
                'role' => $item['testimonial_role'],
            ] : null,
        ];
    }

    /** Proje sayfası: "Başlık · Kısa tanım" ve özet. */
    public function seoDefaults(array $entry): array
    {
        return [
            'title' => trim(($entry['title'] ?? '').(! empty($entry['tagline']) ? ' · '.$entry['tagline'] : '')),
            'description' => (string) ($entry['summary'] ?? ''),
        ];
    }

    public function seoText(array $entry): string
    {
        return implode(' ', array_merge([(string) $entry['summary']], (array) $entry['about'], [(string) $entry['problem'], (string) $entry['solution'], (string) $entry['result']], array_map(static fn ($f) => ($f['title'] ?? '').' '.($f['text'] ?? ''), (array) $entry['features'])));
    }

    public function structuredData(array $entry, array $page): array
    {
        return [
            '@type' => 'CreativeWork',
            'name' => $entry['title'],
            'headline' => $entry['tagline'],
            'description' => $page['description'],
            'url' => $page['url'],
            'inLanguage' => $page['locale'],
            'image' => $page['image'] ?: null,
            'dateCreated' => $entry['date'],
            'dateModified' => $entry['modified'],
            'keywords' => implode(', ', $entry['tags']),
            'creator' => ['@id' => $page['organization']],
        ];
    }

    public function llmsText(array $entry): array
    {
        return [
            'title' => $entry['title'].($entry['tagline'] ? ' — '.$entry['tagline'] : ''),
            'paragraphs' => array_values(array_map('strval', array_filter(array_merge([(string) $entry['summary']], (array) $entry['about'], [(string) $entry['problem'], (string) $entry['solution'], (string) $entry['result']])))),
        ];
    }

    public function searchItem(array $entry): array
    {
        return [
            'slug' => $entry['slug'],
            'title' => $entry['title'],
            'text' => implode(' · ', array_filter([$entry['tagline'], $entry['summary'], implode(' ', $entry['tags'])])),
        ];
    }

    /** Boş satırla ayrılmış paragraflar. @return list<string> */
    public static function paragraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', $text) ?: [])));
    }
}
