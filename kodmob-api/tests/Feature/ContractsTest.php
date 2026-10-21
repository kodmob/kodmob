<?php

namespace Tests\Feature;

use App\Core\Contracts\Contracts;
use App\Core\Http\Field;
use App\Core\Http\Schema;
use Tests\TestCase;

/**
 * Sözleşme kilidi (docs/done-sozlesme-raporu.md §4.1): koddan üretilen sözleşme ve OpenAPI depodakiyle (docs/contracts)
 * aynı olmalı. Uç, yetki, ayar, olay ya da istek şeması değişince bu test düşer: `php artisan kodmob:contracts` ile
 * yeniden üretip commit edin (değişiklik incelemede görünür). Panelde OpenAPI yalnızca yetkiliye.
 */
class ContractsTest extends TestCase
{
    public function test_generated_contract_matches_the_repository(): void
    {
        $dir = dirname(base_path()).'/docs/contracts';
        if (! is_dir($dir)) {
            $this->markTestSkipped('docs/contracts yok (paketlenmiş çatı).');
        }
        foreach (Contracts::files() as $name => $content) {
            $this->assertFileExists($dir.'/'.$name);
            $this->assertTrue(
                str_replace("\r\n", "\n", (string) file_get_contents($dir.'/'.$name)) === $content,
                "docs/contracts/{$name} güncel değil: `php artisan kodmob:contracts` ile üretip commit edin."
            );
        }
    }

    public function test_contract_describes_modules_routes_and_capabilities(): void
    {
        $contract = Contracts::build();
        $modules = $contract['modules'];
        // Kapalı modüller de listelenir; araç paketleri girmez.
        $this->assertSame('tool', $modules['newsletter']['kind']);
        $this->assertSame(['booking'], $modules['hotels']['requires']);
        $this->assertSame('main', $modules['sites']['scope']);
        $send = collect($modules['newsletter']['routes'])->firstWhere('path', '/v1/admin/newsletter/campaigns/{id}/send');
        $this->assertSame(['POST', true, ['km_manage_newsletter'], 'Bülten gönderimi başladı'], [$send['method'], $send['auth'], $send['caps'], $send['summary']]);
        $public = collect($modules['newsletter']['routes'])->firstWhere('path', '/v1/newsletter');
        $this->assertFalse($public['auth']);
        $this->assertSame('10/1', $public['throttle']);
        $this->assertSame('* * * * *', $modules['newsletter']['schedule']['newsletter:send']);
        $this->assertArrayHasKey('km_manage_legal', $contract['capabilities']);
        // İçerik türlerinin yetkileri projeye göre değişir: tek işaret.
        $content = collect($modules['content']['routes'])->firstWhere('path', '/v1/admin/content/{type}');
        $this->assertSame(['@content'], $content['caps']);
        // Gizli ayarın varsayılanı yazılmaz.
        $stripe = collect($modules['stripe']['settings'])->firstWhere('format', 'secret');
        $this->assertArrayNotHasKey('default', $stripe);
    }

    public function test_request_schema_validates_and_describes_the_same_rules(): void
    {
        $schema = Schema::object([
            'name' => Field::string('Ad en az 2 karakter olmalı.')->cut(10)->min(2),
            'code' => Field::enum(static fn () => [301, 410], 'Geçersiz tür.')->optional()->default(301),
            'on' => Field::boolean()->default(true),
            'user' => Field::string('Geçersiz.')->lower()->pattern('/^[a-z]{3,5}$/'),
        ]);
        [$values, $errors] = $schema->check(['name' => ' Ayşe Yılmaz Uzun ', 'user' => 'ABC']);
        $this->assertSame(['name' => 'Ayşe Yılma', 'code' => 301, 'on' => true, 'user' => 'abc'], $values);
        $this->assertSame([], $errors);
        [, $errors] = $schema->check(['name' => 'A', 'code' => '302', 'user' => 'x']);
        $this->assertSame(['name' => 'Ad en az 2 karakter olmalı.', 'code' => 'Geçersiz tür.', 'user' => 'Geçersiz.'], $errors);
        $json = $schema->json();
        $this->assertSame(['name', 'user'], $json['required']);
        $this->assertSame('^[a-z]{3,5}$', $json['properties']['user']['pattern']);
        $this->assertSame([301, 410], $json['properties']['code']['enum']);

        // Şemaya geçen uçlar OpenAPI'de istek gövdesiyle; hata biçimi aynı (details.fields).
        $h = $this->admin();
        $this->postJson('/v1/users', ['username' => 'A', 'email' => 'x', 'display_name' => 'Ayşe', 'role' => 'yok', 'password' => 'Gizli-sifre-12345'], $h)
            ->assertStatus(422)
            ->assertJsonPath('details.fields.username', 'Kullanıcı adı 3-40 karakter; küçük harf, rakam, nokta, tire ya da alt çizgi olmalı.')
            ->assertJsonPath('details.fields.role', 'Rol seçin.');
        $this->postJson('/v1/seo/redirects', ['source' => '', 'code' => 999], $h)->assertStatus(422)
            ->assertJsonPath('details.fields.source', 'Kaynak adres gerekli.')
            ->assertJsonPath('details.fields.code', 'Geçersiz yönlendirme türü.');
    }

    public function test_openapi_is_served_only_to_authorized_users(): void
    {
        $this->getJson('/v1/system/openapi')->assertUnauthorized();
        $this->getJson('/v1/system/openapi', $this->as('editor'))->assertForbidden();
        $doc = $this->getJson('/v1/system/openapi', $this->admin())->assertOk()->json();
        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame([['bearer' => []]], $doc['paths']['/v1/users']['post']['security']);
        $this->assertSame('^[a-z0-9._-]{3,40}$', $doc['paths']['/v1/users']['post']['requestBody']['content']['application/json']['schema']['properties']['username']['pattern']);
    }
}
