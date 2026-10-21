<?php

namespace App\Modules\System;

use App\Core\Activity\Activity;
use App\Core\Content\PageCache;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SettingsService;
use App\Modules\Live\LiveModule;
use App\Modules\Site\SiteModule;
use App\Modules\Site\SiteProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sistem uçları:
 *
 * - `GET /settings?group=`: kullanıcının değiştirebildiği ayar tanımları ve değerleri
 *   (grup yetkisi: SettingsAccess).
 * - `PUT /settings` `{ values: { key: değer } }`: geçerli olanlar kaydedilir,
 *   hatalılar `errors` ile döner (kısmi kayıt; formda alan alan gösterilir).
 * - `POST /settings/defaults` `{ keys?: [], group? }`: seçilen (yoksa yetkili olunan
 *   bütün) ayarlar varsayılana döner.
 * - `GET /site`: herkese açık site bilgileri (SiteModule::payload).
 * - `GET /live`: canlı nabız dosyasının adresi (LiveModule).
 */
final class SystemController extends Controller
{
    public function site(): array
    {
        return SiteModule::payload();
    }

    /** Panelde verilen site renkleri (Site\SiteProfile::css); kabuk sürümlü adresle bağlar, uzun önbellek. */
    public function theme(): Response
    {
        $css = SiteProfile::css();

        return response($css, $css === '' ? 404 : 200)
            ->header('Content-Type', 'text/css; charset=UTF-8')
            ->header('Cache-Control', $css === '' ? 'no-store' : 'public, max-age=31536000, immutable');
    }

    public function live(): array
    {
        return LiveModule::info();
    }

    public function settings(Request $request): array
    {
        return ['items' => $this->describe($request)];
    }

    public function save(Request $request): array
    {
        $values = $this->input($request)['values'] ?? null;
        if (! is_array($values) || ! $values) {
            throw ValidationException::field('values', 'Kaydedilecek ayar yok.');
        }
        $service = SettingsService::instance();
        $errors = [];
        $changed = [];
        foreach ($values as $key => $raw) {
            $definition = SettingsRegistry::get((string) $key);
            if (! $definition) {
                $errors[$key] = 'Tanımsız ayar.';

                continue;
            }
            if (! SettingsAccess::allows($request->user(), $definition)) {
                $errors[$key] = 'Bu ayarı değiştirme yetkiniz yok.';

                continue;
            }
            $before = $service->get((string) $key);
            try {
                $after = $service->set((string) $key, $raw);
            } catch (ValidationException $e) {
                $errors[$key] = $e->getMessage();

                continue;
            }
            if ($after !== $before) {
                // Gizli değer işlem geçmişine yazılmaz.
                $mask = static fn ($v) => $v === '' ? '' : '••••';
                $changed[$key] = $definition->format === 'secret'
                    ? ['label' => $definition->label, 'from' => $mask($before), 'to' => $mask($after)]
                    : ['label' => $definition->label, 'from' => $before, 'to' => $after];
            }
        }
        Activity::type('settings.updated');
        Activity::subject('settings', 0, implode(', ', array_unique(array_map(static fn ($k) => SettingsRegistry::get((string) $k)->group, array_keys($changed)))));
        Activity::with(['changes' => $changed, 'fields' => []]);
        if ($changed) {
            PageCache::flush();
        } else {
            Activity::skip();
        }

        return [
            'items' => $this->describe($request),
            'changed' => array_keys($changed),
            'errors' => (object) $errors,
        ];
    }

    public function defaults(Request $request): array
    {
        $service = SettingsService::instance();
        $input = $this->input($request);
        $keys = $input['keys'] ?? null;
        $user = $request->user();
        if (! is_array($keys) || ! $keys) {
            $group = (string) ($input['group'] ?? '');
            $keys = array_keys(array_filter(
                SettingsRegistry::all(),
                static fn ($d) => SettingsAccess::allows($user, $d) && ($group === '' || $d->group === $group)
            ));
            $all = true;
        }
        foreach ($keys as $key) {
            $definition = SettingsRegistry::get((string) $key);
            if (! $definition) {
                throw ValidationException::field('keys', 'Tanımsız ayar: '.$key);
            }
            if (! SettingsAccess::allows($user, $definition)) {
                throw new DomainException('km_forbidden', 'Bu ayarı değiştirme yetkiniz yok: '.$definition->label, 403);
            }
        }
        foreach ($keys as $key) {
            $service->reset((string) $key);
        }
        Activity::type('settings.defaults');
        Activity::subject('settings', 0);
        Activity::with(['keys' => isset($all) && count($keys) === count(SettingsRegistry::all()) ? 'all' : array_values($keys), 'fields' => []]);
        PageCache::flush();

        return ['items' => $this->describe($request)];
    }

    private function describe(Request $request): array
    {
        $group = (string) $request->query('group', '');
        $user = $request->user();

        return array_values(array_filter(
            SettingsService::instance()->describe(),
            static fn (array $item) => ($group === '' || $item['group'] === $group)
                && SettingsAccess::allows($user, SettingsRegistry::get($item['key']))
        ));
    }
}
