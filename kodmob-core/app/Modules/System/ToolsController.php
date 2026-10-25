<?php

namespace App\Modules\System;

use App\Core\Activity\Activity;
use App\Core\Content\PageCache;
use App\Core\Exceptions\ValidationException;
use App\Core\Extensions\Extensions;
use App\Core\Extensions\Marketplace;
use App\Core\Extensions\ToolManifest;
use App\Core\Http\Controller;
use App\Core\Modules;
use App\Core\ModuleState;
use App\Core\Sites\Sites;
use App\Core\Support\SiteManifest;
use Illuminate\Http\Request;

/**
 * Araç Pazarı (panel/tools.js; docs/done-panel-yapisi-raporu.md §6):
 *
 *   GET /system/tools         isteğe bağlı modüller ve araç paketleri: kurulu mu, bu projede açık mı; topluluk
 *                             kataloğundan kurulu olmayanlar; yüklenemeyen paketler
 *   PUT /system/tools/{key}   kurulu aracı bu projede açar / kapatır (çalışma anında, Core\ModuleState); takıldığı
 *                             modül (`requires`) kapalıyken açılmaz; kapatılınca ona takılı olanlar da çalışmaz
 *                             (kendi durumları korunur, yeniden açılınca döner)
 *   PUT /system/tools/{key}/install   { installed } çatının aracını bu projeye ekler / çıkarır (derleme değişmez;
 *                             aracın kodu her kurulumda, site sayfaları her derlemede kapalı durur)
 *
 * Topluluk aracı panelden kurulmaz: `php artisan kodmob:add <anahtar>` (sunucuya dosya yazan, kod çalıştıran iş
 * panelden yapılmaz).
 */
final class ToolsController extends Controller
{
    public function index(): array
    {
        $name = (string) (SiteManifest::profile()['name'] ?? '');

        $optional = Modules::optional();
        $version = (string) config('kodmob.version');

        return [
            'items' => Modules::catalog(),
            // Keşfet'teki yönerge: aracın ekleneceği yer.
            'profile' => Sites::isMain() ? ($name !== '' ? "kodmob-frontend/src/profiles/{$name}/site.config.js" : 'site.config.js') : 'ana projenin Projeler sayfası',
            // Topluluk kataloğundan bu kurulumda olmayanlar (Keşfet; kurulum komutla: kodmob:add).
            'catalog' => array_values(array_map(
                static fn ($tool) => ['compatible' => ToolManifest::satisfies($tool['kodmob'], $version)] + array_diff_key($tool, array_flip(['download', 'sha256'])),
                array_filter(Marketplace::tools(), static fn ($tool) => ! isset($optional[$tool['key']]))
            )),
            // Klasörde olup yüklenemeyen paketler (tanım bozuk, sürüm uyumsuz, anahtar çakışıyor).
            'problems' => array_map(static fn ($key, $message) => ['key' => $key, 'message' => $message], array_keys(Extensions::problems()), Extensions::problems()),
        ];
    }

    public function toggle(Request $request, string $key): array
    {
        $item = collect(Modules::catalog())->firstWhere('key', $key);
        if ($item === null) {
            throw ValidationException::field('key', 'Böyle bir araç yok.');
        }
        if (! $item['installed']) {
            throw ValidationException::field('key', "{$item['label']} bu projede kurulu değil; önce Keşfet'ten ekleyin.");
        }
        if ($item['kind'] === 'base') {
            throw ValidationException::field('key', "{$item['label']} temel bir yapı; projenin profilinden açılıp kapanır.");
        }
        $enabled = (bool) ($this->input($request)['enabled'] ?? false);
        // Modülün modülü: takıldığı modül açık değilse açılamaz (önce o açılır).
        $catalog = collect(Modules::catalog())->keyBy('key');
        $missing = array_values(array_filter($item['requires'], static fn ($req) => ! ($catalog[$req]['enabled'] ?? false)));
        if ($enabled && $missing) {
            $labels = implode(', ', array_map(static fn ($req) => $catalog[$req]['label'] ?? $req, $missing));
            throw ValidationException::field('key', "{$item['label']} için önce {$labels} açılmalı.");
        }
        ModuleState::set($key, $enabled);
        // Kapalı aracın site sayfası 404 olur (Renderer::moduleOff): önbellekteki kopyası silinir.
        PageCache::flush();
        Activity::subject('tool', 0, $item['label']);
        Activity::with(['tool' => $key, 'enabled' => $enabled]);

        return collect(Modules::catalog())->firstWhere('key', $key);
    }

    /**
     * Çatının aracını ekler (açık gelir; takıldığı kurulu olmayan modüller de) ya da panelden eklenmişse çıkarır.
     * Profilde (derlemede) olan araç çıkarılamaz, kapatılır; ona takılı kurulu araç varsa önce o çıkarılır.
     */
    public function install(Request $request, string $key): array
    {
        $catalog = collect(Modules::catalog())->keyBy('key');
        $item = $catalog[$key] ?? throw ValidationException::field('key', 'Böyle bir araç yok.');
        if ($item['source'] !== 'framework') {
            throw ValidationException::field('key', "{$item['label']} bir topluluk paketi: geliştirme bilgisayarında php artisan kodmob:add {$key} ile kurulur.");
        }
        $add = filter_var($this->input($request)['installed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $with = [];
        if ($add) {
            // Takıldığı modüller önce (zincir: Oteller → Rezervasyon → Mağaza).
            $chain = [];
            $walk = static function (string $k) use (&$walk, &$chain, $catalog): void {
                foreach ((array) ($catalog[$k]['requires'] ?? []) as $req) {
                    $walk($req);
                }
                if (! ($catalog[$k]['installed'] ?? false) && ! in_array($k, $chain, true)) {
                    $chain[] = $k;
                }
            };
            $walk($key);
            foreach ($chain as $k) {
                ModuleState::add($k, true);
            }
            // Kurulu ama kapatılmış takıldığı modül de açılır (yoksa araç çalışmaz).
            foreach ((array) $item['requires'] as $req) {
                if (! in_array($req, $chain, true) && ! ($catalog[$req]['enabled'] ?? false)) {
                    ModuleState::set($req, true);
                }
            }
            if (! in_array($key, $chain, true)) {
                ModuleState::set($key, true);
            }
            $with = array_values(array_map(static fn ($k) => $catalog[$k]['label'], array_diff($chain, [$key])));
        } else {
            if (! $item['added']) {
                throw ValidationException::field('key', "{$item['label']} projenin derlemesinde (profil); panelden çıkarılamaz, kapatılabilir.");
            }
            $blocking = array_values(array_filter($item['dependents'], static fn ($d) => $catalog[$d]['installed'] ?? false));
            if ($blocking) {
                $labels = implode(', ', array_map(static fn ($d) => $catalog[$d]['label'], $blocking));
                throw ValidationException::field('key', "{$item['label']} çıkarılamaz: önce {$labels} çıkarılmalı.");
            }
            ModuleState::add($key, false);
        }
        // Aracın site sayfaları açılır ya da 404 olur: önbellekteki kopyalar silinir.
        PageCache::flush();
        Activity::subject('tool', 0, $item['label']);
        Activity::with(['tool' => $key, 'installed' => $add, 'with' => $with]);

        return ['items' => Modules::catalog(), 'with' => $with];
    }
}
