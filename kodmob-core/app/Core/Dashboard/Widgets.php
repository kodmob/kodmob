<?php

namespace App\Core\Dashboard;

use App\Core\Modules;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Genel bakış kartları (widget): modüller verir (`Module::widgets()`), kişi düzenler. Düzen kişinin tercihidir
 * (`[['key', 'size', 'hidden']]`, sırasıyla); kaydetmediyse varsayılan: yetkisi olan bütün kartlar katalog sırasıyla.
 * Katalogdan kalkan ya da yetkisi olmayan kart düzende olsa da gösterilmez; yeni eklenen kart düzenin sonuna gelir.
 * Veri yalnızca görünen kartlar için hesaplanır; bir kartın hatası diğerlerini durdurmaz.
 */
final class Widgets
{
    public const SIZES = ['sm', 'md', 'lg'];

    /** @return array<string,array> anahtar → tanım (yetkiye göre, sırasıyla) */
    public static function catalog(callable $can): array
    {
        $all = [];
        foreach (Modules::classes() as $class) {
            foreach ((new $class)->widgets() as $widget) {
                if (! empty($widget['can']) && ! $can($widget['can'])) {
                    continue;
                }
                $all[$widget['key']] = $widget + ['icon' => 'grid', 'size' => 'md', 'order' => 50, 'link' => null, 'description' => ''];
            }
        }
        uasort($all, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return $all;
    }

    /**
     * Kişinin düzeni katalogla birleşir: geçersizler atılır, eksikler sona eklenir.
     *
     * @param  list<array<string,mixed>>|null  $saved
     * @return list<array{key:string,size:string,hidden:bool}>
     */
    public static function layout(array $catalog, ?array $saved): array
    {
        $out = [];
        foreach ((array) $saved as $item) {
            $key = (string) ($item['key'] ?? '');
            if (! isset($catalog[$key]) || isset($out[$key])) {
                continue;
            }
            $size = in_array($item['size'] ?? '', self::SIZES, true) ? $item['size'] : $catalog[$key]['size'];
            $out[$key] = ['key' => $key, 'size' => $size, 'hidden' => (bool) ($item['hidden'] ?? false)];
        }
        foreach ($catalog as $key => $widget) {
            $out[$key] ??= ['key' => $key, 'size' => $widget['size'], 'hidden' => false];
        }

        return array_values($out);
    }

    /**
     * Panelin istediği: katalog (gizliler dahil; ekleme menüsü için), düzen ve görünen kartların verisi.
     *
     * @return array{widgets:list<array>,layout:list<array>,custom:bool}
     */
    public static function build(callable $can, int $userId, ?array $saved): array
    {
        $catalog = self::catalog($can);
        $layout = self::layout($catalog, $saved);
        $hidden = array_column(array_filter($layout, static fn ($l) => $l['hidden']), 'hidden', 'key');
        $widgets = [];
        foreach ($catalog as $key => $widget) {
            $data = null;
            if (! isset($hidden[$key]) && isset($widget['data']) && is_callable($widget['data'])) {
                try {
                    $data = ($widget['data'])(['user' => $userId, 'can' => $can]);
                } catch (Throwable $e) {
                    Log::warning("[Kodmob genel bakış] {$key}: ".$e->getMessage());
                    $data = ['error' => 'Bu kartın verisi alınamadı.'];
                }
            }
            $widgets[] = [
                'key' => $key, 'label' => $widget['label'], 'icon' => $widget['icon'], 'type' => $widget['type'],
                'size' => $widget['size'], 'link' => $widget['link'], 'description' => $widget['description'], 'data' => $data,
            ];
        }

        return ['widgets' => $widgets, 'layout' => $layout, 'custom' => $saved !== null];
    }

    /**
     * Kaydedilecek düzeni denetler (biçim; katalogda olmayan anahtar sessizce atılır, panel eskimiş olabilir).
     *
     * @return list<array{key:string,size:string,hidden:bool}>|null hatalıysa null
     */
    public static function clean(mixed $items): ?array
    {
        if (! is_array($items) || count($items) > 60) {
            return null;
        }
        $out = [];
        foreach ($items as $item) {
            $key = is_array($item) ? (string) ($item['key'] ?? '') : '';
            if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', $key) || ! in_array($item['size'] ?? 'md', self::SIZES, true)) {
                return null;
            }
            $out[$key] = ['key' => $key, 'size' => (string) ($item['size'] ?? 'md'), 'hidden' => (bool) ($item['hidden'] ?? false)];
        }

        return array_values($out);
    }
}
