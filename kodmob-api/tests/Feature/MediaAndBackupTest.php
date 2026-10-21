<?php

namespace Tests\Feature;

use App\Modules\Content\Backup;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MediaAndBackupTest extends TestCase
{
    private function png(int $w = 1600, int $h = 1000): string
    {
        $path = tempnam(sys_get_temp_dir(), 'km').'.png';
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
        imagepng($image, $path);

        return $path;
    }

    public function test_upload_generates_sizes_and_requires_turkish_alt(): void
    {
        $h = $this->admin();
        $media = $this->post('/v1/admin/media', ['file' => new UploadedFile($this->png(), 'Ekran Görüntüsü.png', 'image/png', null, true)], $h)->assertCreated();

        $media->assertJsonPath('filename', 'ekran-goruntusu.png');
        $this->assertStringStartsWith('/media/'.now()->format('Y/m').'/', $media->json('src'));
        $this->assertGreaterThanOrEqual(3, substr_count($media->json('srcset'), 'w'));
        $this->assertFileExists($this->mediaRoot.'/'.now()->format('Y/m').'/ekran-goruntusu-150x150.'.(function_exists('imagewebp') ? 'webp' : 'png'));
        $id = $media->json('id');

        $this->postJson('/v1/admin/content/projects', ['cover' => $id, 'i18n' => ['tr' => ['title' => 'Kapaklı']]], $h)
            ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['cover']]]);
        $this->putJson("/v1/admin/media/$id", ['alts' => ['tr' => '', 'en' => 'x']], $h)->assertStatus(422);
        $this->putJson("/v1/admin/media/$id", ['alts' => ['tr' => 'Mavi görsel']], $h)->assertOk()->assertJsonPath('alts.tr', 'Mavi görsel');
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'cover' => ['id' => $id], 'i18n' => ['tr' => ['title' => 'Kapaklı']]], $h)->assertCreated();

        $this->getJson('/v1/page-data?route=home&locale=en&needs=projects')->assertJsonPath('projects', []);
        $this->getJson('/v1/page-data?route=home&locale=tr&needs=projects')->assertJsonPath('projects.0.cover.alt', 'Mavi görsel');
    }

    public function test_private_network_urls_are_rejected(): void
    {
        $this->postJson('/v1/admin/media/url', ['url' => 'http://127.0.0.1/a.png'], $this->admin())->assertStatus(422);
    }

    public function test_backup_round_trip_restores_content_and_media_from_folder(): void
    {
        $h = $this->admin();
        $media = $this->post('/v1/admin/media', ['file' => new UploadedFile($this->png(800, 600), 'logo.png', 'image/png', null, true)], $h)->json();
        $this->putJson('/v1/admin/media/'.$media['id'], ['alts' => ['tr' => 'Logo']], $h);
        $project = $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Proje A']]], $h)->json();
        $this->postJson('/v1/admin/content/references', ['status' => 'publish', 'name' => 'Müşteri', 'logo' => $media['id'], 'project' => $project['id']], $h)->assertCreated();
        $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Yazı', 'locale' => 'tr', 'html' => '<p>Gövde</p>'], $h)->assertCreated();
        $this->putJson('/v1/admin/texts', ['values' => ['tr' => ['home.hero.title' => 'Özel başlık']]], $h);

        $backup = $this->getJson('/v1/admin/backup', $h)->assertOk()->json();
        // Yalnızca bu testin türleri (kodmob:module ile eklenen tür de sayılara 0 ile girer).
        $this->assertSame(['projects' => 1, 'services' => 0, 'references' => 1, 'posts' => 1], array_intersect_key($backup['counts'], array_flip(['projects', 'services', 'references', 'posts'])));

        // Başka bir siteye taşıma: görsel kitaplıkta yok, asıl dosyası backups/media/ klasöründe.
        @mkdir(Backup::mediaDir(), 0755, true);
        copy($this->mediaRoot.'/'.now()->format('Y/m').'/logo.png', Backup::mediaDir().'/logo.png');
        $backup['media'] = [(string) 999 => ['url' => 'http://localhost/api/wp-content/uploads/logo.png', 'file' => 'logo.png', 'alts' => ['tr' => 'Logo', 'en' => '', 'de' => '']]];
        foreach ($backup['content']['references'] as &$reference) {
            $reference['data']['logo'] = 999;
        }
        unset($reference);
        \DB::table('media')->delete();

        $result = $this->postJson('/v1/admin/backup', $backup, $h)->assertOk()->json();
        $this->assertSame(1, $result['counts']['projects']);
        $this->assertSame(['reused' => 0, 'downloaded' => 1, 'failed' => []], $result['media']);

        $references = $this->getJson('/v1/page-data?route=home&locale=tr&needs=references,projects')->json();
        $this->assertSame('Logo', $references['references'][0]['logo']['alt']);
        $this->assertSame($references['projects'][0]['id'], $references['references'][0]['project']);
        $this->assertSame(['tr' => ['home.hero.title' => 'Özel başlık']], (array) $this->getJson('/v1/admin/texts', $h)->json('values'));
        @unlink(Backup::mediaDir().'/logo.png');
    }
}
