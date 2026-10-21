<?php

namespace Tests\Feature;

use App\Core\Support\PanelUrl;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Panel adresleri: ön yüzle (tv/src/panel/routes.js) aynı vektörler
 * (tests/fixtures/panel-urls.json). Tablo test manifest'inin `panel` bölümündedir;
 * tv testi o bölümün gerçek tabloyla aynı olduğunu denetler.
 */
class PanelUrlTest extends TestCase
{
    private array $vectors;

    protected function setUp(): void
    {
        parent::setUp();
        PanelUrl::flush();
        $this->vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/panel-urls.json')), true);
    }

    public function test_routes_match_shared_vectors(): void
    {
        foreach ([...$this->vectors['urls'], ...$this->vectors['fallbacks']] as $v) {
            $this->assertSame($v['url'], PanelUrl::to($v['name'], $v['params'] ?? [], $v['query'] ?? []), $v['name'].' '.json_encode($v['params'] ?? []));
        }
    }

    public function test_legacy_hashes_match_shared_vectors(): void
    {
        foreach ($this->vectors['legacy'] as $v) {
            $this->assertSame($v['url'], PanelUrl::fromLegacyHash($v['hash']), $v['hash']);
            $this->assertSame($v['url'], PanelUrl::normalize($v['hash']), 'normalize '.$v['hash']);
        }
        $this->assertSame('/panel/talepler/3', PanelUrl::normalize('/panel/talepler/3'));
        $this->assertNull(PanelUrl::normalize(null));
    }

    public function test_absolute_url_uses_app_url(): void
    {
        $this->assertSame('https://kodmob.test/panel/talepler/12', PanelUrl::absolute('briefs', ['id' => 12]));
    }

    public function test_migration_converts_stored_hash_links_and_role_menus(): void
    {
        DB::table('notifications')->insert(['user_id' => 1, 'type' => 'brief.received', 'title' => 'T', 'url' => '#/briefs/5', 'created_at' => now()]);
        DB::table('roles')->updateOrInsert(['slug' => 'sales'], ['label' => 'Satış', 'menu' => json_encode([['key' => 'g1', 'label' => 'Satış', 'items' => ['#/', '#/briefs', '#/jarvis/domains', '#/olmayan/alt', 'briefs']]])]);

        (require base_path('database/migrations/2026_10_15_000300_panel_paths.php'))->up();

        $this->assertSame('/panel/talepler/5', DB::table('notifications')->value('url'));
        $this->assertSame(['overview', 'briefs', 'domains'], json_decode((string) DB::table('roles')->where('slug', 'sales')->value('menu'), true)[0]['items']);
    }

    public function test_without_manifest_every_link_is_panel_root(): void
    {
        config(['kodmob.site_root' => sys_get_temp_dir().'/km-yok-'.uniqid()]);
        $this->assertSame('/panel/', PanelUrl::to('briefs', ['id' => 12]));
    }
}
