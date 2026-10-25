<?php

namespace Tests\Support\Portfolio;

use App\Core\Content\ContentType;
use App\Core\Support\SiteManifest;

/**
 * Test türü (çatının testleri projenin koduna bağlı kalmasın): portföy sitesi örneği, kodmob.com'un türünden.
 *
 * Hizmetler: Hizmetler sayfasındaki bölümler, ana sayfa kartları, footer
 * listesi ve site içi arama. `key` dilden bağımsız çapadır (/hizmetler/#web);
 * `categories` bölümde gösterilecek projelerin kategorileri.
 */
final class ServiceType extends ContentType
{
    public const POST_TYPE = 'km_service';

    public function postType(): string
    {
        return self::POST_TYPE;
    }

    public function labels(): array
    {
        return ['Hizmetler', 'Hizmet'];
    }

    public function capabilityLabel(): string
    {
        return 'Hizmetleri ekleme ve düzenleme';
    }

    public function shared(): array
    {
        return [
            'key' => ['type' => 'slug', 'label' => 'Anahtar', 'required' => true, 'help' => 'Sayfadaki çapa ve footer bağlantısı: /hizmetler/#web. Değiştirirseniz eski bağlantılar bölüme inmez.', 'section' => 'Genel', 'width' => 'half'],
            'icon' => ['type' => 'icon', 'label' => 'İkon', 'default' => 'layers', 'section' => 'Genel', 'width' => 'half'],
            'categories' => ['type' => 'multiselect', 'label' => 'İlgili proje kategorileri', 'options' => SiteManifest::vocab(ProjectType::VOCAB), 'help' => 'Bu kategorilerdeki projeler hizmet bölümünde örnek olarak listelenir.', 'section' => 'Genel'],
        ];
    }

    public function localized(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Başlık', 'help' => 'Boşsa hizmet bu dilde gösterilmez.', 'section' => 'İçerik'],
            'text' => ['type' => 'textarea', 'label' => 'Kısa açıklama', 'max' => 300, 'help' => 'Ana sayfa kartı ve bölüm başlığının altı.', 'section' => 'İçerik'],
            'points' => ['type' => 'list', 'label' => 'Maddeler', 'max' => 8, 'help' => 'Bölümde tik işaretiyle listelenir.', 'section' => 'İçerik'],
        ];
    }

    protected function listSubtitle(array $data): string
    {
        return '#'.($data['key'] ?? '');
    }

    protected function listImage(array $data): int
    {
        return 0;
    }

    protected function validate(array $data, int $id): array
    {
        foreach ($this->records(false) as $other) {
            if ($other->id !== $id && ($other->data['key'] ?? '') === $data['key']) {
                return ['key' => 'Bu anahtar başka bir hizmette kullanılıyor.'];
            }
        }

        return [];
    }

    protected function publicItem(array $item, string $locale): array
    {
        return [
            'id' => $item['id'],
            'key' => $item['key'],
            'icon' => $item['icon'],
            'categories' => $item['categories'],
            'title' => $item['title'],
            'text' => $item['text'],
            'points' => $item['points'],
        ];
    }
}
