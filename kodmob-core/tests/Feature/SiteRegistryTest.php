<?php

namespace Tests\Feature;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Mail\MailSettings;
use App\Core\Modules;
use App\Core\Security\Capability;
use App\Core\Settings\SettingsService;
use App\Core\Sites\SiteRegistry;
use App\Core\Sites\Sites;
use App\Core\Support\Options;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\Handoff;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Alt site oluşturma ve yönetimi (Core\Sites\SiteRegistry): panel uçları, komut satırı, kurulum adresi.
 * Kayıt testin geçici depolamasında (TestCase), alt sitelerin veritabanı SQLite dosyası.
 */
class SiteRegistryTest extends TestCase
{
    private function payload(array $over = []): array
    {
        return array_replace([
            'key' => 'demo',
            'name' => 'Demo Site',
            'domains' => ['Demo.Kodmob.test', 'www.demo2.test'],
            'modules' => ['blog'],
            'admin' => ['username' => 'Demo-Yonetici', 'email' => 'Demo@Kodmob.test', 'password' => 'gizli-sifre-123'],
        ], $over);
    }

    private function childUsers(string $key): array
    {
        $pdo = new \PDO('sqlite:'.$this->storage.'/sites/'.$key.'/database.sqlite');
        $users = $pdo->query('select username, role from users')->fetchAll(\PDO::FETCH_NUM);
        $pdo = null;

        return $users;
    }

    public function test_admin_creates_updates_migrates_and_removes_a_site(): void
    {
        $h = $this->admin();
        $mainConnection = config('database.default');
        $this->getJson('/v1/system/sites', $h)->assertOk()->assertJsonPath('sites', [])->assertJsonCount(count(Modules::OPTIONAL), 'modules');

        $site = $this->postJson('/v1/system/sites', $this->payload(), $h)->assertOk()->json();
        $this->assertSame(['demo', 'Demo Site', ['demo.kodmob.test', 'demo2.test'], ['blog'], 'https://demo.kodmob.test'], [$site['key'], $site['name'], $site['domains'], $site['modules'], $site['url']]);
        $this->assertSame(['driver' => 'sqlite', 'database' => 'database.sqlite'], $site['db']);
        $this->assertSame(['ok' => true, 'pending' => 0, 'admins' => 1, 'error' => ''], $site['status']);

        // Alt sitenin kendi depolaması ve veritabanı; ilk yönetici orada, ana sitede değil.
        $storage = $this->storage.'/sites/demo';
        $this->assertFileExists($storage.'/framework/i18n/config.json');
        $this->assertFileExists($storage.'/framework/seo/config.json');
        $this->assertSame([['demo-yonetici', 'admin']], $this->childUsers('demo'));
        $this->assertFalse(User::query()->where('username', 'demo-yonetici')->exists());
        // İş bitince ana siteye dönülmüş olmalı.
        $this->assertSame(['main', $mainConnection, $this->storage], [Sites::key(), config('database.default'), app()->storagePath()]);
        $this->assertTrue(DB::table('events')->where('event_type', 'sites.created')->exists());

        $registry = json_decode((string) file_get_contents(SiteRegistry::file()), true);
        $this->assertArrayHasKey('created_at', $registry['sites']['demo']);
        $this->assertStringNotContainsString('gizli-sifre', (string) file_get_contents(SiteRegistry::file()));

        // Düzenleme: gönderilmeyen alan kalır; null modül listesi varsayılana döner.
        $this->putJson('/v1/system/sites/demo', ['name' => 'Demo 2', 'url' => 'https://www.demo.kodmob.test', 'modules' => null], $h)->assertOk()
            ->assertJsonPath('name', 'Demo 2')->assertJsonPath('modules', null)->assertJsonPath('url', 'https://www.demo.kodmob.test')
            ->assertJsonPath('domains', ['demo.kodmob.test', 'demo2.test']);

        $this->postJson('/v1/system/sites/all/migrate', [], $h)->assertOk()->assertJsonPath('results.0.key', 'demo')->assertJsonPath('results.0.ok', true);

        // Kayıttan çıkarmak verileri silmez; aynı anahtarla yeniden oluşturulunca site geri gelir.
        $this->deleteJson('/v1/system/sites/demo', [], $h)->assertOk()->assertJsonPath('removed', 'demo');
        $this->getJson('/v1/system/sites', $h)->assertJsonPath('sites', []);
        $this->assertFileExists($storage.'/database.sqlite');
        $this->postJson('/v1/system/sites', $this->payload(), $h)->assertOk()->assertJsonPath('status.admins', 1);
        $this->assertSame([['demo-yonetici', 'admin']], $this->childUsers('demo'));
    }

    public function test_validation_and_conflicts(): void
    {
        $h = $this->admin();
        $fields = $this->postJson('/v1/system/sites', ['key' => 'main', 'domains' => ['bozuk alan'], 'admin' => []], $h)->assertStatus(422)->json('details.fields');
        $this->assertEqualsCanonicalizing(['key', 'name', 'domains', 'admin.username', 'admin.email', 'admin.password'], array_keys($fields));

        // Ana sitenin alan adı (app.url) alt siteye verilemez: ana site kapanırdı.
        $this->postJson('/v1/system/sites', $this->payload(['domains' => ['www.kodmob.test']]), $h)->assertStatus(422)
            ->assertJsonPath('details.fields.domains', 'kodmob.test (main) zaten kullanılıyor.');
        $this->postJson('/v1/system/sites', $this->payload(), $h)->assertOk();
        $this->postJson('/v1/system/sites', $this->payload(['key' => 'ikinci', 'domains' => ['demo2.test']]), $h)->assertStatus(422)
            ->assertJsonPath('details.fields.domains', 'demo2.test (demo) zaten kullanılıyor.');
        $this->postJson('/v1/system/sites', $this->payload(['domains' => ['x.test']]), $h)->assertStatus(422)->assertJsonPath('details.fields.key', 'Bu anahtarla bir site zaten var.');
        $this->postJson('/v1/system/sites', $this->payload(['key' => 'ucuncu', 'domains' => ['u.test'], 'modules' => ['yok'], 'db' => ['driver' => 'oracle']]), $h)->assertStatus(422)
            ->assertJsonStructure(['details' => ['fields' => ['modules', 'db.driver']]]);
        $this->putJson('/v1/system/sites/demo', ['domains' => ['kodmob.test']], $h)->assertStatus(422);
        // IP adresinin alt adı açılmaz: yerel deneme adresi önerilir.
        $this->putJson('/v1/system/sites/demo', ['domains' => ['deneme.127.0.0.1']], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.domains', 'deneme.127.0.0.1 tarayıcıda açılmaz (IP adresinin alt adı olmaz). Yerelde deneme.localhost yazın.');
        $this->putJson('/v1/system/sites/hayalet', ['name' => 'x'], $h)->assertNotFound();

        // Yetki: yalnızca km_manage_sites (Yönetici).
        $this->getJson('/v1/system/sites', $this->as('editor'))->assertForbidden();
    }

    public function test_non_empty_database_and_failed_connection_are_refused(): void
    {
        $file = $this->storage.'/baska.sqlite';
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec('create table wp_posts (id integer)');
        $pdo = null;
        try {
            SiteRegistry::create($this->payload(['db' => ['driver' => 'sqlite', 'database' => $file]]));
            $this->fail('dolu veritabanına kurmamalı');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('boş değil', $e->details()['fields']['db.database']);
        }

        try {
            SiteRegistry::create($this->payload(['key' => 'kotu', 'domains' => ['kotu.test'], 'db' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'yok', 'username' => 'yok', 'password' => 'x']]));
            $this->fail('bağlanamayan veritabanıyla kurmamalı');
        } catch (DomainException $e) {
            $this->assertSame('km_site_db_failed', $e->errorCode());
        }
        $this->assertSame([], SiteRegistry::list(false), 'başarısız kurulum kayda girmez');
    }

    public function test_settings_follow_the_site_inside_run(): void
    {
        $settings = SettingsService::instance();
        $settings->set('site.name', 'Ana Site');
        $this->assertSame('Ana Site', $settings->get('site.name'), 'ana sitenin ayarı önbellekte');
        SiteRegistry::create($this->payload());

        Sites::run('demo', function () {
            $this->assertSame('', SettingsService::instance()->get('site.name'), 'alt sitede ana sitenin ayarı görünmemeli');
            SettingsService::instance()->set('site.name', 'Demo Site');
        });
        $this->assertSame('Ana Site', SettingsService::instance()->get('site.name'), 'dönüşte ana sitenin ayarı');
        $this->assertSame('Demo Site', Sites::run('demo', static fn () => SettingsService::instance()->get('site.name')));
    }

    /** Paylaşılan sistem ayarları (e-posta, SMS, yapay zekâ): alt proje ana projeninkini kullanır, kendisi yazamaz. */
    public function test_child_uses_main_system_settings(): void
    {
        MailSettings::save(['mailer' => 'smtp', 'host' => 'smtp.ana.test', 'username' => 'ana', 'password' => 'posta-sifresi', 'from_address' => 'ana@kodmob.test']);
        Options::set('page_texts', ['ana' => true]);
        SiteRegistry::create($this->payload());

        Sites::run('demo', function () {
            $mail = MailSettings::get();
            $this->assertSame('smtp.ana.test', $mail['host'], 'ana projenin e-posta ayarı');
            $this->assertSame('posta-sifresi', $mail['password'], 'mühürlü şifre aynı APP_KEY ile açılır');
            $this->assertTrue(Options::inherited('mail_settings'));
            $this->assertNull(Options::get('page_texts'), 'paylaşılmayan seçenek projenin kendisinde');
            try {
                MailSettings::save(['host' => 'smtp.alt.test']);
                $this->fail('alt proje paylaşılan ayarı yazmamalı');
            } catch (DomainException $e) {
                $this->assertSame('km_shared_setting', $e->errorCode());
            }
            $this->assertSame(0, DB::table('options')->where('name', 'mail_settings')->count(), 'alt projenin tablosuna yazılmaz');
        });
        $this->assertFalse(Options::inherited('mail_settings'), 'ana projede kendi tablosu');
        $this->assertSame('smtp.ana.test', MailSettings::get()['host']);
    }

    /** Proje seçici (/me): bu proje; ana projede yöneticiye diğer projeler ve panel adresleri. */
    public function test_me_lists_projects_for_the_switcher(): void
    {
        $h = $this->admin();
        $me = $this->getJson('/v1/me', $h)->assertOk();
        $me->assertJsonPath('project.key', 'main')->assertJsonPath('project.main', true)->assertJsonPath('projects', []);

        SiteRegistry::create($this->payload());
        // Canlıdaki gibi (443): kayıttaki adres; yerelde (standart dışı bağlantı noktası) alan adı bu isteğin şemasıyla.
        $this->getJson('https://kodmob.test/v1/me', $h)->assertJsonPath('projects', [['key' => 'demo', 'name' => 'Demo Site', 'panel' => 'https://demo.kodmob.test/panel/']]);
        $this->getJson('http://127.0.0.1:8090/v1/me', $h)->assertJsonPath('projects.0.panel', 'http://demo.kodmob.test:8090/panel/');
        // Yerel deneme adresi (anahtar.localhost) varsa yerelde o açılır; canlıda kayıttaki adres.
        $this->putJson('/v1/system/sites/demo', ['domains' => ['demo.kodmob.test', 'demo.localhost']], $h)->assertOk();
        $this->getJson('http://127.0.0.1:8090/v1/me', $h)->assertJsonPath('projects.0.panel', 'http://demo.localhost:8090/panel/');
        $this->getJson('https://kodmob.test/v1/me', $h)->assertJsonPath('projects.0.panel', 'https://demo.kodmob.test/panel/');
        $this->getJson('/v1/me', $this->as('editor'))->assertJsonPath('projects', []);
    }

    /** Tek oturumla geçiş: hedefte aynı e-postalı yönetici için tek kullanımlık, kısa ömürlü anahtar. */
    public function test_handoff_opens_the_child_panel_once(): void
    {
        $h = $this->admin(); // e-posta: yonetici@kodmob.test
        SiteRegistry::create($this->payload(['admin' => ['username' => 'demo-yonetici', 'email' => 'Yonetici@Kodmob.test', 'password' => 'gizli-sifre-123']]));
        $url = $this->postJson('https://kodmob.test/v1/system/sites/demo/handoff', [], $h)->assertOk()->assertJsonPath('expires_in', 60)->json('url');
        $this->assertMatchesRegularExpression('#^https://demo\.kodmob\.test/panel/\?gecis=[0-9a-f]{48}$#', $url);
        $token = explode('gecis=', $url)[1];

        Sites::run('demo', function () use ($token) {
            $this->assertSame('demo-yonetici', Handoff::redeem($token)['user']->username);
            $this->assertSame(1, DB::table('handoff_tokens')->whereNotNull('used_at')->count());
            try {
                Handoff::redeem($token);
                $this->fail('anahtar ikinci kez kullanılmamalı');
            } catch (DomainException $e) {
                $this->assertSame('km_handoff_invalid', $e->errorCode());
            }
            // Süresi geçen anahtar da geçmez.
            DB::table('handoff_tokens')->insert(['token_hash' => hash('sha256', str_repeat('ab', 24)), 'user_id' => 1, 'expires_at' => '2000-01-01 00:00:00']);
            $this->expectExceptionMessage('süresi dolmuş');
            Handoff::redeem(str_repeat('ab', 24));
        });
    }

    public function test_handoff_needs_a_matching_admin_and_a_valid_token(): void
    {
        $h = $this->admin();
        SiteRegistry::create($this->payload()); // yöneticisi demo@kodmob.test
        $this->postJson('/v1/system/sites/demo/handoff', [], $h)->assertStatus(409)->assertJsonPath('code', 'km_handoff_no_account');
        $this->postJson('/v1/system/sites/demo/handoff', [], $this->as('editor'))->assertForbidden();
        $this->postJson('/v1/auth/handoff', ['token' => 'yok'])->assertStatus(403)->assertJsonPath('code', 'km_handoff_invalid');
    }

    public function test_child_site_cannot_manage_sites(): void
    {
        SiteRegistry::create($this->payload());
        Sites::run('demo', function () {
            $this->assertArrayNotHasKey(Capability::MANAGE_SITES, Capability::all());
            $this->expectExceptionMessage('yalnızca ana siteden');
            SiteRegistry::create($this->payload(['key' => 'torun', 'domains' => ['torun.test']]));
        });
    }

    public function test_setup_url_and_command_line(): void
    {
        $this->artisan('kodmob:site', ['action' => 'create', 'key' => 'cli', '--name' => 'CLI', '--domain' => ['cli.kodmob.test'], '--admin' => 'cli-yonetici', '--email' => 'cli@kodmob.test', '--password' => 'gizli-sifre-123'])
            ->expectsOutputToContain('cli kuruldu: cli.kodmob.test (sqlite)')->assertExitCode(0);
        $this->artisan('kodmob:site', ['action' => 'list'])->expectsOutputToContain('hazır')->assertExitCode(0);
        $this->artisan('kodmob:site', ['action' => 'create', 'key' => 'cli2', '--domain' => ['cli.kodmob.test'], '--password' => 'kisa'])
            ->expectsOutputToContain('zaten kullanılıyor')->assertExitCode(1);

        // Ana sitenin kurulum adresi alt sitelerin tablolarını da günceller.
        config(['kodmob.setup_token' => str_repeat('a1', 16)]);
        $this->get('/v1/setup?token='.str_repeat('a1', 16))->assertOk()->assertSee('cli: güncel');

        $this->artisan('kodmob:site', ['action' => 'migrate'])->expectsOutputToContain('cli: tamam')->assertExitCode(0);
        $this->artisan('kodmob:site', ['action' => 'remove', 'key' => 'cli'])->expectsOutputToContain('kayıttan çıkarıldı')->assertExitCode(0);
    }
}
