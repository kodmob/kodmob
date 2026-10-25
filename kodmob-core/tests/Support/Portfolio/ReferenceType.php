<?php

namespace Tests\Support\Portfolio;

use App\Core\Content\ContentType;

/**
 * Test türü (çatının testleri projenin koduna bağlı kalmasın): portföy sitesi örneği, kodmob.com'un türünden.
 *
 * Referanslar (müşteriler): logo şeridi (ana sayfa, Referanslar) ve müşteri
 * yorumları. Ad ve logo dilden bağımsızdır; yorum her dilde ayrı yazılır,
 * boş bırakılan dilde yalnızca logo görünür. Bağlı proje varsa yorum kartı
 * projeye gider.
 */
final class ReferenceType extends ContentType
{
    public const POST_TYPE = 'km_reference';

    public function postType(): string
    {
        return self::POST_TYPE;
    }

    public function labels(): array
    {
        return ['Referanslar', 'Referans'];
    }

    public function capabilityLabel(): string
    {
        return 'Referansları ekleme ve düzenleme';
    }

    public function shared(): array
    {
        return [
            'name' => ['type' => 'text', 'label' => 'Müşteri adı', 'required' => true, 'help' => 'Logo yoksa şeritte bu ad yazı olarak görünür.', 'section' => 'Genel', 'width' => 'half'],
            'url' => ['type' => 'url', 'label' => 'Web sitesi', 'section' => 'Genel', 'width' => 'half'],
            'logo' => ['type' => 'image', 'label' => 'Logo', 'help' => 'Şeffaf zeminli PNG ya da WebP önerilir (en az 400 px genişlik).', 'section' => 'Genel'],
            'project' => ['type' => 'post', 'label' => 'Bağlı proje', 'postType' => ProjectType::POST_TYPE, 'help' => 'Yorum kartındaki "Projeyi incele" bağlantısı.', 'section' => 'Genel'],
        ];
    }

    public function localized(): array
    {
        return [
            'quote' => ['type' => 'textarea', 'label' => 'Müşteri yorumu', 'max' => 800, 'help' => 'Boşsa bu dilde yorum kartı gösterilmez.', 'section' => 'Yorum'],
            'person' => ['type' => 'text', 'label' => 'Yorum sahibi', 'section' => 'Yorum', 'width' => 'half'],
            'role' => ['type' => 'text', 'label' => 'Unvan', 'help' => 'Ör. Kurucu Ortak', 'section' => 'Yorum', 'width' => 'half'],
        ];
    }

    /** Referans her dilde görünür (ad ve logo ortaktır). */
    public function localeKey(): ?string
    {
        return null;
    }

    protected function listTitle(array $data): string
    {
        return (string) ($data['name'] ?? '');
    }

    protected function listSubtitle(array $data): string
    {
        foreach ($data['i18n'] ?? [] as $l) {
            if (! empty($l['person'])) {
                return $l['person'].(! empty($l['role']) ? ' · '.$l['role'] : '');
            }
        }

        return (string) parse_url((string) ($data['url'] ?? ''), PHP_URL_HOST);
    }

    protected function listImage(array $data): int
    {
        return (int) ($data['logo'] ?? 0);
    }

    protected function publicItem(array $item, string $locale): array
    {
        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'url' => $item['url'],
            'logo' => $item['logo'],
            'project' => $item['project'] ?: null,
            'quote' => $item['quote'],
            'person' => $item['person'],
            'role' => $item['role'],
        ];
    }
}
