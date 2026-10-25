<?php

namespace Tests\Feature;

use App\Core\Http\MessageCatalog;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Çok dilli panel: dışa aktar, doldur, içe aktar; kişinin dili; mesaj kodları ve katalog. */
class PanelLanguageTest extends TestCase
{
    public function test_export_fill_import_and_choose(): void
    {
        // Panelin kataloğu: test sitesinin derlemesinde (tests/fixtures/site/km-panel-strings.json).
        $admin = $this->admin();

        $export = $this->get('/v1/system/panel-languages/export?code=en&name=English', $admin)->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="frontend-lang.en.json"');
        $file = json_decode($export->getContent(), true);
        $this->assertSame(['kodmob-panel-lang/1', 'en', 'English'], [$file['format'], $file['language'], $file['name']]);
        $this->assertSame(['Kaydet' => '', 'Vazgeç' => '', 'Bildirimler' => ''], $file['strings']);
        $code = MessageCatalog::code('Bu dil kurulu değil.');
        $this->assertSame('Bu dil kurulu değil.', $file['messages'][$code]['tr'], 'sunucu mesajları kodlarıyla');
        $this->get('/v1/system/panel-languages/export?code=tr', $admin)->assertStatus(422);

        // Doldurulan dosya içe aktarılır; boşlar atlanır.
        $file['strings']['Kaydet'] = 'Save';
        $file['strings']['Bildirimler'] = 'Notifications';
        $file['messages'][$code]['text'] = 'This language is not installed.';
        $path = tempnam(sys_get_temp_dir(), 'km').'.json';
        file_put_contents($path, json_encode($file));
        $this->post('/v1/system/panel-languages/import', ['file' => new UploadedFile($path, 'frontend-lang.en.json', 'application/json', null, true)], $admin)
            ->assertOk()->assertJsonPath('strings', 2)->assertJsonPath('messages', 1)->assertJsonPath('languages.0.code', 'en');

        // Kişi dili seçer; /me ve sözlük.
        $this->assertSame([['code' => 'tr', 'name' => 'Türkçe'], ['code' => 'en', 'name' => 'English']], $this->getJson('/v1/me', $admin)->json('panel_languages'));
        $this->putJson('/v1/me/panel-language', ['code' => 'en'], $admin)->assertOk();
        $this->assertSame('en', $this->getJson('/v1/me', $admin)->json('panel_language'));
        $dict = $this->getJson('/v1/panel-lang/en', $admin)->assertOk();
        $dict->assertJsonPath('strings.Kaydet', 'Save')->assertJsonPath("messages.{$code}", 'This language is not installed.');
        $this->putJson('/v1/me/panel-language', ['code' => 'xx'], $admin)->assertStatus(422)->assertJsonPath('details.codes.code', $code);

        // Dil kaldırılınca kişi Türkçe'ye döner.
        $this->deleteJson('/v1/system/panel-languages/en', [], $admin)->assertOk()->assertJsonPath('languages', []);
        $this->assertSame('tr', $this->getJson('/v1/me', $admin)->json('panel_language'));
    }

    public function test_invalid_files_are_rejected(): void
    {
        $admin = $this->admin();
        foreach ([['format' => 'baska'], ['format' => 'kodmob-panel-lang/1', 'language' => 'tr', 'strings' => ['A' => 'B']], ['format' => 'kodmob-panel-lang/1', 'language' => 'en', 'strings' => ['Kaydet' => '']]] as $body) {
            $path = tempnam(sys_get_temp_dir(), 'km').'.json';
            file_put_contents($path, json_encode($body));
            $this->post('/v1/system/panel-languages/import', ['file' => new UploadedFile($path, 'x.json', 'application/json', null, true)], $admin)->assertStatus(422);
        }
        // Yalnızca ayar yetkisi olan yönetir; sözlüğü herkes okur.
        $this->getJson('/v1/system/panel-languages', $this->as('editor'))->assertForbidden();
    }

    public function test_errors_carry_message_codes_and_catalog_is_current(): void
    {
        $response = $this->putJson('/v1/me/panel-language', ['code' => 'xx'], $this->admin())->assertStatus(422);
        $this->assertSame(MessageCatalog::code('Bu dil kurulu değil.'), $response->json('message_code'));

        $file = resource_path('messages.json');
        $expected = json_encode(MessageCatalog::scan(app_path()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $this->assertSame($expected, str_replace("\r\n", "\n", (string) file_get_contents($file)), 'Mesaj kataloğu güncel değil: php artisan kodmob:messages');
        $this->assertArrayHasKey(MessageCatalog::code('Bu dil kurulu değil.'), json_decode($expected, true));
    }
}
