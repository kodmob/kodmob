<?php

use App\Core\Security\Roles;
use App\Core\Support\PanelUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Panel adreslerinden `#` kalktı (/panel/#/briefs/12 → /panel/talepler/12):
 *
 * - Bildirimlerin bağlantıları yeni adrese çevrilir. Rota tablosu site kökündeki
 *   km-manifest.json'dan okunur; manifest yoksa (site dosyaları henüz yüklenmemiş)
 *   bağlantılara dokunulmaz, okunurken çevrilir (NotificationService).
 * - Rol menüleri adres yerine sayfa anahtarı saklar (`#/projects` → `projects`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (PanelUrl::available()) {
            foreach (DB::table('notifications')->where('url', 'like', '#%')->get(['id', 'url']) as $row) {
                DB::table('notifications')->where('id', $row->id)->update(['url' => PanelUrl::fromLegacyHash((string) $row->url)]);
            }
        }

        foreach (DB::table('roles')->whereNotNull('menu')->get(['slug', 'menu']) as $row) {
            $menu = json_decode((string) $row->menu, true);
            if (! is_array($menu)) {
                continue;
            }
            $menu = array_map(static fn ($group) => ['items' => array_values(array_unique(array_filter(array_map(
                static fn ($item) => Roles::pageKey((string) $item),
                (array) ($group['items'] ?? [])
            ))))] + (array) $group, $menu);
            DB::table('roles')->where('slug', $row->slug)->update(['menu' => json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        // Geri dönüş yok: eski `#/…` adresleri ön yüz okurken de çevirir.
    }
};
