<?php

namespace App\Modules\System;

use App\Core\Activity\Activity;
use App\Core\Ai\Ai;
use App\Core\Content\Languages;
use App\Core\Http\Controller;
use App\Core\Modules;
use App\Core\Sites\SiteRegistry;
use App\Core\Sites\Sites;
use App\Modules\Auth\Services\Handoff;
use App\Modules\Site\SiteWizard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Siteler (çok site; App\Core\Sites). Yetki: km_manage_sites; yalnızca ana sitede (alt sitede yetki yok).
 *
 *   GET    /system/sites                liste + durum (tablolar güncel mi, yönetici var mı) + modüller
 *   POST   /system/sites/plan           Site Sihirbazı önerisi { name, sector, city, about, facts, tone, color, languages[] }
 *   POST   /system/sites                { key, name, domains[], url?, modules?[], db?{…}, admin{username,email,password,display_name?} }
 *   PUT    /system/sites/{key}          { name?, domains?[], url?, modules?[] } (veritabanı değişmez)
 *   POST   /system/sites/{key}/migrate  tabloları güncelle (sürüm yüklemesinden sonra); key=all: hepsi
 *   DELETE /system/sites/{key}          kayıttan çıkar (veriler silinmez)
 */
final class SitesController extends Controller
{
    public function index(): array
    {
        return [
            'sites' => SiteRegistry::list(),
            'modules' => array_map(static fn ($key, $m) => ['key' => $key, 'label' => $m['label'], 'description' => $m['description']], array_keys(Modules::OPTIONAL), Modules::OPTIONAL),
            'drivers' => Sites::DRIVERS,
            // Site Sihirbazı: ana sitenin dilleri ve yapay zekâ anahtarı var mı.
            'languages' => array_values(array_map(static fn ($l) => ['code' => $l['code'], 'label' => $l['native'], 'default' => (bool) $l['default']], array_filter(Languages::all(), static fn ($l) => $l['active']))),
            'ai' => Ai::available(),
            'registry' => SiteRegistry::file(),
        ];
    }

    /** Site Sihirbazı: işletme bilgisinden öneri (kaydetmez). */
    public function plan(Request $request): array
    {
        return SiteWizard::plan($this->input($request));
    }

    public function store(Request $request): array
    {
        $input = $this->input($request);
        $plan = is_array($input['plan'] ?? null) ? $input['plan'] : null;
        unset($input['plan']);
        // SQLite dosya yolu yalnızca komut satırından (panelden sunucudaki başka bir dosya gösterilemesin).
        if (($input['db']['driver'] ?? 'sqlite') === 'sqlite') {
            unset($input['db']['database']);
        }
        $site = SiteRegistry::create($input);
        // Sihirbaz önerisi kurulan sitenin içine; uygulanamazsa site yine kurulmuş olur, hata bildirilir.
        if ($plan !== null) {
            try {
                $site['wizard'] = Sites::run($site['key'], static fn () => SiteWizard::apply($plan, $site['name']));
            } catch (Throwable $e) {
                Log::warning('[Kodmob site sihirbazı] '.$site['key'].': '.$e->getMessage());
                $site['wizard'] = ['error' => 'Site kuruldu ama öneri uygulanamadı: '.$e->getMessage()];
            }
        }
        Activity::subject('site', 0, $site['name']);
        Activity::with(['key' => $site['key'], 'name' => $site['name'], 'domains' => $site['domains']]);

        return $site;
    }

    public function update(Request $request, string $key): array
    {
        $site = SiteRegistry::update($key, $this->input($request));
        Activity::subject('site', 0, $site['name']);
        Activity::with(['key' => $site['key'], 'name' => $site['name']]);

        return $site;
    }

    public function migrate(string $key): array
    {
        $results = SiteRegistry::migrate($key === 'all' ? null : $key);
        Activity::with(['keys' => array_column($results, 'key')]);

        return ['results' => $results, 'sites' => SiteRegistry::list()];
    }

    /**
     * Proje seçiciden tek oturumla geçiş (Auth\Services\Handoff): hedef projede aynı e-postalı yönetici için
     * tek kullanımlık anahtar; tarayıcı hedefin paneline `?gecis=` ile gider.
     */
    public function handoff(Request $request, string $key): array
    {
        $site = SiteRegistry::get($key, false);
        $token = Handoff::issue($request->user(), $key);
        Activity::subject('site', 0, (string) $site['name']);
        Activity::with(['key' => $key]);

        return ['url' => SiteRegistry::panelUrl($site, $request->getScheme(), (int) $request->getPort()).'?gecis='.$token, 'expires_in' => Handoff::TTL];
    }

    public function destroy(string $key): array
    {
        $site = SiteRegistry::remove($key);
        Activity::subject('site', 0, $site['name']);
        Activity::with(['key' => $site['key'], 'name' => $site['name']]);

        return ['removed' => $site['key']];
    }
}
