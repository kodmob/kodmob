<?php

namespace Tests\Feature;

use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Modules\Content\Backup;
use App\Modules\I18n\I18nConfig;
use App\Modules\I18n\LanguageService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dil kaydı (Faz 2): tohum, yetki, doğrulama kuralları, varsayılan dil, silme, sıralama,
 * derlenmiş kopya, /me ve yedek. Test manifest'inde (tests/fixtures/site) yalnızca tr ve en
 * derlenmiş durumda.
 */
class LanguagesTest extends TestCase
{
    public function test_seeded_registry_drives_locales(): void
    {
        $this->assertSame(['tr', 'en', 'de'], Locales::all());
        $this->assertSame('tr', Locales::default());
        $this->assertSame(['tr' => 'Türkçe', 'en' => 'İngilizce', 'de' => 'Almanca'], Locales::labels());
        $this->assertSame('', Languages::find('tr')['prefix']);
        $this->assertSame('tr', Locales::normalize('xx'));

        $this->getJson('/v1/languages', $this->admin())->assertOk()
            ->assertJsonPath('default', 'tr')
            ->assertJsonPath('items.0.code', 'tr')
            ->assertJsonPath('items.1.built', true)
            ->assertJsonPath('items.2.built', false)
            ->assertJsonPath('built', ['tr', 'en']);
        $reserved = $this->getJson('/v1/languages', $this->admin())->json('reserved');
        $this->assertContains('panel', $reserved);
        $this->assertContains('projeler', $reserved, 'varsayılan dilin bölüm adı da ayrılmış');

        $this->getJson('/v1/me', $this->admin())->assertJsonPath('languages.1.code', 'en')->assertJsonPath('languages.1.name', 'İngilizce');
    }

    public function test_access_follows_capability(): void
    {
        $this->getJson('/v1/languages', $this->as('editor'))->assertOk();
        $this->getJson('/v1/languages', $this->as('author'))->assertStatus(403);
    }

    public function test_new_language_is_added_inactive_and_validated(): void
    {
        $admin = $this->admin();
        $this->postJson('/v1/languages', ['code' => 'FR!'], $admin)->assertStatus(422)->assertJsonPath('details.fields.code', fn ($m) => str_contains($m, 'Dil kodu'));
        $this->postJson('/v1/languages', ['code' => 'en', 'name' => 'x', 'native' => 'x'], $admin)->assertStatus(422);
        $this->postJson('/v1/languages', ['code' => 'fr'], $admin)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['name', 'native']]]);

        $this->postJson('/v1/languages', ['code' => 'fr', 'name' => 'Fransızca', 'native' => 'Français'], $admin)->assertOk()
            ->assertJsonPath('active', false)->assertJsonPath('prefix', 'fr')->assertJsonPath('og', 'fr_FR')->assertJsonPath('fallback', 'tr');
        $this->assertSame(['tr', 'en', 'de', 'fr'], Locales::all());
        $this->assertNotContains('fr', Locales::active());

        // render.php'nin okuduğu kopya derlendi.
        $config = json_decode((string) file_get_contents(I18nConfig::file()), true);
        $this->assertSame('tr', $config['default']);
        $this->assertContains('fr', array_column($config['languages'], 'code'));

        $this->assertDatabaseHas('events', ['event_type' => 'languages.created']);
    }

    public function test_prefix_and_default_come_from_the_build(): void
    {
        $admin = $this->admin();
        // Önek panelden değişmez (derlemeden gelir); diğer alanlar değişir.
        $this->putJson('/v1/languages/en', ['prefix' => 'xx', 'native' => 'English (UK)'], $admin)->assertOk()
            ->assertJsonPath('prefix', 'en')->assertJsonPath('native', 'English (UK)');
        $this->assertDatabaseHas('events', ['event_type' => 'languages.updated']);
        $this->postJson('/v1/languages/en/default', [], $admin)->assertStatus(404);

        // Kayıt bozulsa da derlenmiş kopya üretilirken derlemeyle eşitlenir.
        DB::table('languages')->where('code', 'tr')->update(['prefix' => 'tr', 'is_default' => false]);
        DB::table('languages')->where('code', 'en')->update(['is_default' => true]);
        Languages::forget();
        I18nConfig::compile();
        $this->assertSame('tr', Locales::default());
        $this->assertSame('', Languages::find('tr')['prefix']);
        $this->assertSame(1, DB::table('languages')->where('is_default', true)->count());
    }

    public function test_sync_adds_built_languages_as_inactive(): void
    {
        // Derlemede Fransızca da var (yeni build yüklendi): kayda pasif eklenir, adları derlemeden.
        $root = sys_get_temp_dir().'/km-site-'.uniqid();
        mkdir($root);
        $manifest = json_decode((string) file_get_contents(base_path('tests/fixtures/site/km-manifest.json')), true);
        $manifest['locales']['fr'] = ['prefix' => 'fr', 'label' => 'Français', 'short' => 'FR', 'intl' => 'fr-FR', 'og' => 'fr_FR', 'flag' => '/assets/img/flags/fr.svg'];
        file_put_contents($root.'/km-manifest.json', json_encode($manifest));
        config(['kodmob.site_root' => $root]);

        $this->assertTrue(LanguageService::syncWithBuild());
        $fr = Languages::find('fr');
        $this->assertFalse($fr['active']);
        $this->assertSame('fr', $fr['prefix']);
        $this->assertSame('Français', $fr['native']);
        $this->assertFalse(LanguageService::syncWithBuild(), 'ikinci eşitleme değişiklik yapmaz');

        // Artık derlemede olduğu için yayına alınabilir.
        $this->putJson('/v1/languages/fr', ['active' => true], $this->admin())->assertOk()->assertJsonPath('active', true);
        @unlink($root.'/km-manifest.json');
        @rmdir($root);
    }

    public function test_activation_needs_the_language_in_the_site_build(): void
    {
        $admin = $this->admin();
        $this->postJson('/v1/languages', ['code' => 'fr', 'name' => 'Fransızca', 'native' => 'Français'], $admin)->assertOk();
        $this->putJson('/v1/languages/fr', ['active' => true], $admin)->assertStatus(422)->assertJsonPath('details.fields.active', fn ($m) => str_contains($m, 'derlemesinde yok'));
        $this->putJson('/v1/languages/tr', ['active' => false], $admin)->assertStatus(422)->assertJsonPath('details.fields.active', fn ($m) => str_contains($m, 'Varsayılan'));
        // Zaten yayında olan dil derlemede olmasa da düzenlenebilir (de).
        $this->putJson('/v1/languages/de', ['native' => 'Deutsch (DE)'], $admin)->assertOk();
    }

    public function test_fallback_chain_cannot_loop(): void
    {
        $admin = $this->admin();
        $this->putJson('/v1/languages/en', ['fallback' => 'de'], $admin)->assertOk();
        $this->putJson('/v1/languages/de', ['fallback' => 'en'], $admin)->assertStatus(422)->assertJsonPath('details.fields.fallback', fn ($m) => str_contains($m, 'döngü'));
        $this->putJson('/v1/languages/de', ['fallback' => 'de'], $admin)->assertStatus(422);
        $this->putJson('/v1/languages/de', ['fallback' => 'xx'], $admin)->assertStatus(422);
    }

    public function test_delete_only_inactive_and_unused_languages(): void
    {
        $admin = $this->admin();
        $this->deleteJson('/v1/languages/tr', [], $admin)->assertStatus(409)->assertJsonPath('code', 'km_language_default');
        $this->deleteJson('/v1/languages/en', [], $admin)->assertStatus(409)->assertJsonPath('code', 'km_language_active');

        $this->postJson('/v1/languages', ['code' => 'it', 'name' => 'İtalyanca', 'native' => 'Italiano'], $admin)->assertOk();
        DB::table('posts')->insert(['locale' => 'it', 'slug' => 'ciao', 'title' => 'Ciao', 'excerpt' => '', 'html' => '<p>x</p>', 'status' => 'draft', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson('/v1/languages/it', [], $admin)->assertStatus(409)->assertJsonPath('code', 'km_language_used')->assertJsonPath('details.count', 1);

        $this->postJson('/v1/languages', ['code' => 'fr', 'name' => 'Fransızca', 'native' => 'Français'], $admin)->assertOk();
        $this->putJson('/v1/languages/de', ['fallback' => 'fr'], $admin)->assertOk();
        $this->deleteJson('/v1/languages/fr', [], $admin)->assertOk();
        $this->assertNull(Languages::find('fr'));
        $this->assertNull(Languages::find('de')['fallback'], 'silinen dil yedek dil olarak kalmaz');
    }

    public function test_reorder_needs_every_language_once(): void
    {
        $admin = $this->admin();
        $this->postJson('/v1/languages/order', ['codes' => ['en', 'tr']], $admin)->assertStatus(422);
        $this->postJson('/v1/languages/order', ['codes' => ['en', 'tr', 'tr']], $admin)->assertStatus(422);
        $this->postJson('/v1/languages/order', ['codes' => ['de', 'tr', 'en']], $admin)->assertOk()->assertJsonPath('items.0.code', 'de');
        $this->assertSame(['de', 'tr', 'en'], Locales::all());
    }

    public function test_backup_carries_languages(): void
    {
        $admin = $this->admin();
        $this->postJson('/v1/languages', ['code' => 'fr', 'name' => 'Fransızca', 'native' => 'Français'], $admin)->assertOk();
        $backup = (new Backup)->export();
        $this->assertSame(['tr', 'en', 'de', 'fr'], array_column($backup['languages'], 'code'));

        // Yedekte olmayan dil kalkar; derlemede olmayan dil pasif gelir; varsayılan korunur.
        $backup['languages'] = array_values(array_filter($backup['languages'], static fn ($l) => $l['code'] !== 'de'));
        $backup['languages'][2]['active'] = true;
        $report = (new Backup)->import($backup);
        $this->assertSame(3, $report['counts']['languages']);
        $this->assertSame(['tr', 'en', 'fr'], Locales::all());
        $this->assertFalse(Languages::find('fr')['active']);
        $this->assertSame('tr', Locales::default());

        // Eski yedek (dil bilgisi yok): mevcut diller kalır.
        unset($backup['languages']);
        (new Backup)->import($backup);
        $this->assertSame(['tr', 'en', 'fr'], Locales::all());
    }
}
