<?php

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Core\Setup\Installer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * kodmob:install: yeni projenin yerel kurulumu (geçici bir depo kökünde; bu depoya dokunulmaz).
 */
class InstallerTest extends TestCase
{
    private function root(): string
    {
        $root = $this->storage.'/install-'.uniqid();
        File::ensureDirectoryExists($root.'/kodmob-api/database');
        File::ensureDirectoryExists($root.'/tv/src/profiles/starter/pages');
        file_put_contents($root.'/kodmob-api/.env.example', "APP_ENV=production\nAPP_KEY=\nAPP_URL=https://ornek.com\nDB_CONNECTION=mysql\nDB_DATABASE=\nJWT_SECRET=\nMAIL_FROM_NAME=\"Örnek Site\"\n");
        file_put_contents($root.'/tv/profile.js', "export const PROFILE = process.env.KM_PROFILE || 'starter';\n");
        file_put_contents($root.'/tv/src/profiles/starter/site.config.js', "export const SITE = {};\n");
        file_put_contents($root.'/tv/src/profiles/starter/pages/home.njk', "<h1>Merhaba</h1>\n");

        return $root;
    }

    public function test_env_is_prepared_for_local_development_once(): void
    {
        $root = $this->root();
        $installer = new Installer($root);
        $done = $installer->env('http://127.0.0.1:8090/', false);
        $this->assertContains('APP_KEY üretildi', $done);

        $env = $root.'/kodmob-api/.env';
        $this->assertSame(['local', 'sqlite', 'log', 'http://127.0.0.1:8090', 'Örnek Site'], array_map(static fn ($k) => Installer::envValue($env, $k), ['APP_ENV', 'DB_CONNECTION', 'MAIL_MAILER', 'APP_URL', 'MAIL_FROM_NAME']));
        $this->assertStringEndsWith('/kodmob-api/database/database.sqlite', Installer::envValue($env, 'DB_DATABASE'));
        $this->assertFileExists($root.'/kodmob-api/database/database.sqlite');
        $this->assertMatchesRegularExpression('/^base64:.{44}$/', Installer::envValue($env, 'APP_KEY'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Installer::envValue($env, 'JWT_SECRET'));

        // İkinci kez: var olan anahtarlara ve ayarlara dokunulmaz.
        $key = Installer::envValue($env, 'APP_KEY');
        Installer::setEnv($env, 'APP_URL', 'http://site.test');
        $this->assertSame([], $installer->env('http://127.0.0.1:8090', false));
        $this->assertSame([$key, 'http://site.test'], [Installer::envValue($env, 'APP_KEY'), Installer::envValue($env, 'APP_URL')]);
    }

    public function test_profile_is_copied_from_starter_and_selected(): void
    {
        $root = $this->root();
        $installer = new Installer($root);
        $this->assertSame(['profil oluşturuldu: tv/src/profiles/benim-site (başlangıçtan)', 'etkin profil: benim-site (tv/profile.js)'], $installer->profile('benim-site'));
        $this->assertFileExists($root.'/tv/src/profiles/benim-site/pages/home.njk');
        $this->assertStringContainsString("KM_PROFILE || 'benim-site'", file_get_contents($root.'/tv/profile.js'));
        $this->assertSame([], $installer->profile('benim-site'), 'ikinci kez bir şey değişmez');

        $this->expectException(ValidationException::class);
        $installer->profile('Kötü Ad');
    }

    public function test_command_prepares_env_profile_and_lock(): void
    {
        $root = $this->root();
        file_put_contents($root.'/kodmob.json', json_encode(['name' => 'kodmob', 'version' => '9.9.9', 'framework' => ['tv/profile.js'], 'project' => [], 'exclude' => []]));
        $this->artisan('kodmob:install', ['--root' => $root, '--profile' => 'benim', '--no-database' => true])
            ->expectsOutputToContain('APP_KEY üretildi')->expectsOutputToContain('etkin profil: benim')->expectsOutputToContain('kodmob.lock.json yazıldı')
            ->assertExitCode(0);
        $this->artisan('kodmob:install', ['--root' => $root, '--profile' => 'Kötü Ad', '--no-database' => true])->assertExitCode(1);
    }

    public function test_lock_marks_the_installed_framework_once(): void
    {
        $root = $this->root();
        File::ensureDirectoryExists($root.'/kodmob-api/app/Core');
        file_put_contents($root.'/kodmob-api/app/Core/A.php', '<?php');
        file_put_contents($root.'/kodmob.json', json_encode(['name' => 'kodmob', 'version' => '9.9.9', 'framework' => ['kodmob-api/app/Core/**'], 'project' => [], 'exclude' => []]));
        $installer = new Installer($root);
        $this->assertSame('kodmob.lock.json yazıldı (çatı 9.9.9, 1 dosya)', $installer->lock());
        $this->assertNull($installer->lock());
    }
}
