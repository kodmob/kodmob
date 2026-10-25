<?php

namespace App\Modules\Site\Blocks;

use App\Core\Content\MediaPayload;
use App\Modules\Site\Pages\PageType;
use App\Modules\Site\Render\Renderer;
use App\Modules\Site\Render\Router;

/**
 * Bloğun sayfadaki HTML'i (derlemedeki eşi kodmob-frontend/vite/blocks.js `renderInstance`; sarmalayıcı aynı).
 *
 * Değer sırası: dil alanında önce o dilin metni, yoksa varsayılan dilinki (çevrilmemiş blok boş görünmez), yoksa
 * bloğun o dildeki varsayılanı; ortak alanda bloğun verisi, yoksa varsayılanı. Bağlantı `route:<rota>` dilin
 * adresine, görsel kimliği { src, srcset, width, height, alt }'a çevrilir.
 *
 * Önizlemede (Sayfa düzenleyici) `data-km-field` işaretleri kalır, gizli blok soluk çizilmek üzere
 * `data-km-hidden` ile gelir; ziyaretçiye işaret gitmez, gizli blok hiç yazılmaz.
 */
final class BlockRenderer
{
    /** Şablon metni (özeti) → ayrıştırılmış ağaç; içerikle anahtarlı olduğundan siteye bağlı değil. */
    private static array $parsed = [];

    public function __construct(private readonly Router $router) {}

    public function render(array $instance, string $locale, bool $preview = false): string
    {
        $block = BlockLibrary::block((string) ($instance['type'] ?? ''));
        if ($block === null || (! empty($instance['hidden']) && ! $preview)) {
            return '';
        }
        $html = Template::render(self::tree((string) $block['template']), $this->view($block, $instance, $locale));
        if (! $preview) {
            $html = (string) preg_replace('/\sdata-km-field="[^"]*"/', '', $html);
        }
        $attrs = 'class="km-block" data-km-block="'.Template::escape((string) $instance['type']).'" data-km-id="'.Template::escape((string) ($instance['id'] ?? '')).'"';
        if ($preview && ! empty($instance['hidden'])) {
            $attrs .= ' data-km-hidden';
        }

        return '<div '.$attrs.'>'.$html.'</div>';
    }

    /** Şablonun göreceği değerler. */
    public function view(array $block, array $instance, string $locale): array
    {
        $default = $this->router->defaultLocale();
        $defaults = (array) ($block['defaults'] ?? []);
        $out = [];
        foreach ((array) ($block['fields'] ?? []) as $field) {
            $name = (string) $field['name'];
            if (! empty($field['localized'])) {
                $value = $instance['i18n'][$locale][$name] ?? $instance['i18n'][$default][$name]
                    ?? $defaults['i18n'][$locale][$name] ?? $defaults['i18n'][$default][$name] ?? BlockData::blank($field);
            } else {
                $value = $instance['data'][$name] ?? $defaults['data'][$name] ?? BlockData::blank($field);
            }
            $out[$name] = $this->value($field, $value, $locale);
        }

        return $out;
    }

    private function value(array $field, mixed $value, string $locale): mixed
    {
        switch ($field['type']) {
            case 'link':
                return $this->href((string) (is_scalar($value) ? $value : ''), $locale);
            case 'image':
                $id = (int) (is_array($value) ? ($value['id'] ?? 0) : $value);
                $image = $id > 0 ? MediaPayload::image($id, 'large', $locale) : null;

                return is_array($image) && ! empty($image['src']) ? $image : null;
            case 'rows':
                $rows = [];
                foreach (is_array($value) ? $value : [] as $row) {
                    if (is_array($row)) {
                        $rows[] = array_combine(
                            array_map(static fn ($c) => (string) $c['name'], (array) $field['columns']),
                            array_map(fn ($c) => $this->value($c, $row[$c['name']] ?? BlockData::blank($c), $locale), (array) $field['columns'])
                        );
                    }
                }

                return $rows;
        }

        return $value;
    }

    /**
     * Bağlantı alanı → adres (`route:contact` → `/iletisim/`; rota bu dilde yoksa boş). Blok sayfası (`page:12`)
     * o dildeki adresine; sayfa bu dilde yayında değilse boş.
     */
    public function href(string $value, string $locale): string
    {
        if (str_starts_with($value, 'route:')) {
            // Projede kapalı aracın sayfası (rotanın `module`'ü; ör. Teklif hesaplayıcı): bağlantı yok.
            $route = $this->router->route(substr($value, 6), $locale);

            return $route && ! Renderer::moduleOff($route) ? (string) $this->router->url($route['name'], $locale) : '';
        }
        if (str_starts_with($value, 'page:')) {
            $slug = PageType::publishedSlug((int) substr($value, 5), $locale);

            return $slug !== '' ? (string) ($this->router->url(PageType::ROUTE, $locale, $slug) ?? '') : '';
        }

        return BlockData::link($value);
    }

    /** @return list<string|array<string,mixed>> */
    private static function tree(string $template): array
    {
        $key = md5($template);

        return self::$parsed[$key] ??= Template::parse($template);
    }
}
