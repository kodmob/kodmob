<?php

namespace Tests\Feature;

use App\Core\Content\Types;
use App\Core\Storage\EncryptedFile;
use App\Core\Storage\S3;
use App\Core\Support\Options;
use App\Modules\Content\AutoBackup;
use App\Modules\Content\Backup;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/** Otomatik yedek: zip (içerik + görseller), saklama, S3 uyumlu depo, zip'ten geri yükleme, zamanlama. */
class AutoBackupTest extends TestCase
{
    private function png(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'km').'.png';
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
        imagepng($image, $path);

        return $path;
    }

    public function test_sigv4_matches_the_aws_example(): void
    {
        $s3 = new S3(['endpoint' => 'https://s3.amazonaws.com', 'region' => 'us-east-1', 'bucket' => 'examplebucket', 'key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'path_style' => false]);
        $signed = $s3->sign('GET', 'test.txt', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', ['range' => 'bytes=0-9'], '20130524T000000Z');
        $this->assertStringEndsWith('Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $signed['headers']['authorization']);
        $this->assertSame('https://examplebucket.s3.amazonaws.com/test.txt', $signed['url']);
    }

    public function test_zip_has_content_and_images_keeps_last_n_and_restores(): void
    {
        $h = $this->admin();
        $media = $this->post('/v1/admin/media', ['file' => new UploadedFile($this->png(), 'logo.png', 'image/png', null, true), 'alts' => ['tr' => 'Logo']], $h)->assertCreated()->json();
        $project = $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'cover' => $media['id'], 'i18n' => ['tr' => ['title' => 'Yedekli proje']]], $h)->assertCreated()->json();
        $this->putJson('/v1/admin/backups/auto', ['keep' => 2, 'media' => true], $h)->assertOk();

        $run = AutoBackup::run('deneme');
        $zip = new ZipArchive;
        $zip->open(AutoBackup::path($run['file']));
        $this->assertSame('kodmob-backup', json_decode((string) $zip->getFromName('backup.json'), true)['format']);
        $this->assertNotFalse($zip->locateName('media/'.basename(DB::table('media')->value('file'))));
        $zip->close();
        Carbon::setTestNow(now()->addSecond());
        AutoBackup::run('deneme');
        Carbon::setTestNow(now()->addSeconds(2));
        AutoBackup::run('deneme');
        Carbon::setTestNow();
        $this->assertCount(2, AutoBackup::list(), 'son 2 yedek tutulur');
        $this->getJson('/v1/admin/backups/auto', $h)->assertJsonPath('last_status', 'ok')->assertJsonCount(2, 'items');

        // Görsel dosyası ve proje kaybolsa da zip'ten geri gelir.
        $name = AutoBackup::list()[0]['name'];
        $this->deleteJson('/v1/admin/content/projects/'.$project['id'], [], $h)->assertNoContent();
        $this->postJson('/v1/admin/backups/auto/'.$name.'/restore', [], $h)->assertOk()->assertJsonPath('counts.projects', 1);
        $restored = Types::get('projects')->records(false)[0];
        $this->assertSame('Yedekli proje', $restored->data['i18n']['tr']['title']);
        $this->assertGreaterThan(0, (int) $restored->data['cover']);

        $this->get('/v1/admin/backups/auto/'.$name, $h)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->getJson('/v1/admin/backups/auto', $this->as('editor'))->assertForbidden();
    }

    public function test_remote_upload_to_s3_compatible_storage(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/admin/backups/auto', ['remote' => true, 'endpoint' => 'https://depo.example', 'bucket' => 'kova'], $h)->assertStatus(422);
        $remote = ['remote' => true, 'endpoint' => 'https://depo.example', 'region' => 'auto', 'bucket' => 'kova', 'prefix' => 'site', 'key' => 'ERISIM', 'secret' => 'cok-gizli', 'media' => false];
        // Uzak depoya giden yedek şifrelenir: şifresiz kaydedilmez, kısa şifre olmaz.
        $this->putJson('/v1/admin/backups/auto', $remote, $h)->assertStatus(422)->assertJsonPath('details.fields.passphrase', 'Uzak depoya giden yedek şifrelenir: yedek şifresi girin.');
        $this->putJson('/v1/admin/backups/auto', $remote + ['passphrase' => 'kisa'], $h)->assertStatus(422);
        $view = $this->putJson('/v1/admin/backups/auto', $remote + ['passphrase' => 'uzun bir yedek şifresi'], $h)->assertOk()->json();
        $this->assertTrue($view['secret_set']);
        $this->assertTrue($view['passphrase_set']);
        $this->assertArrayNotHasKey('secret', $view);
        $this->assertArrayNotHasKey('passphrase', $view);
        $this->assertStringStartsWith('enc:', Options::get('auto_backup')['secret']);
        $this->assertStringStartsWith('enc:', Options::get('auto_backup')['passphrase']);

        // Önce başarılı yükleme (giden dosya şifreli kopya), sonra erişim reddi.
        $sent = null;
        $responses = [Http::response('', 200), Http::response('<Error><Message>Access Denied</Message></Error>', 403)];
        Http::fake(['depo.example/*' => function (HttpRequest $r) use (&$sent, &$responses) {
            $sent = $r->body();

            return array_shift($responses);
        }]);
        $run = AutoBackup::run('uzak');
        $this->assertSame('uploaded', $run['remote']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === 'https://depo.example/kova/site/'.$run['file'].'.kmbak'
            && str_starts_with($r->header('authorization')[0], 'AWS4-HMAC-SHA256 Credential=ERISIM/') && $r->header('x-amz-content-sha256')[0] === hash('sha256', (string) $sent));
        $this->assertStringStartsWith(EncryptedFile::MAGIC, (string) $sent);
        $copy = Backup::dir().'/kopya.kmbak';
        file_put_contents($copy, $sent);
        EncryptedFile::decrypt($copy, $copy.'.zip', 'uzun bir yedek şifresi');
        $this->assertSame(hash_file('sha256', AutoBackup::path($run['file'])), hash_file('sha256', $copy.'.zip'), 'açılınca sunucudaki zip');
        @unlink($copy);
        @unlink($copy.'.zip');
        $this->assertSame([$run['file']], array_column(AutoBackup::list(), 'name'), 'şifreli geçici kopya sunucuda kalmaz');

        try {
            AutoBackup::run('uzak');
            $this->fail('Uzak depo hatası fırlatılmalıydı');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Access Denied', $e->getMessage());
        }
        $this->getJson('/v1/admin/backups/auto', $h)->assertJsonPath('last_status', 'failed')->assertJsonPath('last_remote', 'failed');
    }

    /** Sunucu yandı: uzak depodaki şifreli kopya yeni kuruluma konur (klasöre ya da panelden yüklenir), şifreyle açılır. */
    public function test_encrypted_copy_restores_with_the_passphrase(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Uzaktaki proje']]], $h)->assertCreated();
        $run = AutoBackup::run('uzak');
        $zip = AutoBackup::path($run['file']);
        $sealed = AutoBackup::dir().'/'.$run['file'].'.kmbak';
        EncryptedFile::encrypt($zip, $sealed, 'uzun bir yedek şifresi', 1000);
        $upload = sys_get_temp_dir().'/km-yuklenen-'.bin2hex(random_bytes(3)).'.kmbak';
        copy($sealed, $upload);
        unlink($zip);
        DB::table('contents')->delete();

        $this->getJson('/v1/admin/backups/auto', $h)->assertJsonPath('items.0.name', $run['file'].'.kmbak')->assertJsonPath('items.0.encrypted', true);
        $url = '/v1/admin/backups/auto/'.$run['file'].'.kmbak/restore';
        $this->postJson($url, [], $h)->assertStatus(422)->assertJsonPath('details.fields.passphrase', 'Bu yedek şifreli: yedek şifresini girin.');
        $this->postJson($url, ['passphrase' => 'yanlış şifre'], $h)->assertStatus(422)->assertJsonPath('details.fields.passphrase', 'Yedek şifresi yanlış ya da dosya bozuk.');
        $this->assertSame(0, DB::table('contents')->whereNull('deleted_at')->count(), 'yanlış şifrede hiçbir şey değişmez');
        $this->postJson($url, ['passphrase' => 'uzun bir yedek şifresi'], $h)->assertOk()->assertJsonPath('counts.projects', 1);
        $this->assertSame('Uzaktaki proje', Types::get('projects')->records(false)[0]->data['i18n']['tr']['title']);

        // Panelden yükleme (multipart): aynı dosya, aynı şifre.
        DB::table('contents')->delete();
        $this->post('/v1/admin/backup', ['file' => new UploadedFile($upload, 'yedek.zip.kmbak', 'application/octet-stream', null, true), 'passphrase' => 'uzun bir yedek şifresi'], $h)
            ->assertOk()->assertJsonPath('counts.projects', 1);
        $this->assertCount(1, Types::get('projects')->records(false));
        $this->assertStringNotContainsString('acilan-', implode(',', scandir(Backup::dir()) ?: []), 'açılan geçici zip silinir');
    }

    public function test_schedule_runs_only_when_due(): void
    {
        Carbon::setTestNow('2026-10-16 03:00:00');
        AutoBackup::save(['enabled' => true, 'frequency' => 'daily', 'media' => false]);
        AutoBackup::runIfDue();
        $this->assertCount(1, AutoBackup::list());
        Carbon::setTestNow('2026-10-16 15:00:00');
        AutoBackup::runIfDue();
        $this->assertCount(1, AutoBackup::list(), '12 saat sonra yeniden alınmaz');
        Carbon::setTestNow('2026-10-17 03:05:00');
        AutoBackup::runIfDue();
        $this->assertCount(2, AutoBackup::list());
        Carbon::setTestNow();
    }
}
