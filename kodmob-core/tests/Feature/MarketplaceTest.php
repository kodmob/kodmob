<?php

namespace Tests\Feature;

use App\Core\Extensions\Marketplace;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Keşfet kataloğu: depodan okunur ve saklanır, okunamazsa son kopya ya da çatıyla gelen; Yenile; adres boşsa istek yok. */
class MarketplaceTest extends TestCase
{
    private const URL = 'https://raw.githubusercontent.com/kodmob/kodmob-explore/main/catalog.json';

    private function remote(array $tools): array
    {
        return ['format' => 1, 'tools' => $tools];
    }

    private function tool(string $key, string $version = '1.0.0'): array
    {
        return ['key' => $key, 'label' => ucfirst($key), 'description' => 'Açıklama', 'author' => 'Kodmob', 'license' => 'MIT', 'version' => $version, 'kodmob' => '>=3.0 <4',
            'repository' => 'https://github.com/kodmob/kodmob-explore/tree/main/tools/'.$key, 'download' => "https://raw.githubusercontent.com/kodmob/kodmob-explore/main/dist/{$key}-{$version}.zip",
            'sha256' => str_repeat('a', 64), 'verified' => false];
    }

    public function test_discover_lists_tools_added_to_the_explore_repository(): void
    {
        config(['kodmob.marketplace_url' => self::URL]);
        $calls = 0;
        $catalog = $this->remote([$this->tool('stok-takibi')]);
        Http::fake([self::URL => function () use (&$calls, &$catalog) {
            $calls++;

            return Http::response($catalog);
        }]);
        $admin = $this->admin();

        $res = $this->getJson('/v1/system/tools', $admin)->assertOk();
        $this->assertSame(['stok-takibi'], array_column($res->json('catalog'), 'key'));
        $this->assertSame('remote', $res->json('catalog_source.source'));
        $this->assertArrayNotHasKey('download', $res->json('catalog.0'), 'indirme adresi ve özet panele gitmez');
        $this->getJson('/v1/system/tools', $admin)->assertOk();
        $this->assertSame(1, $calls, 'taze kopya varken yeniden okunmaz');

        // Depoya yeni araç eklendi: Yenile hemen okur.
        $catalog = $this->remote([$this->tool('stok-takibi'), $this->tool('randevu')]);
        $this->postJson('/v1/system/tools/catalog/refresh', [], $admin)->assertOk();
        $this->assertSame(['stok-takibi', 'randevu'], array_column($this->getJson('/v1/system/tools', $admin)->json('catalog'), 'key'));
        $this->assertSame(2, $calls);
        $this->assertSame('randevu', Marketplace::find('randevu')['key'], 'kodmob:add aynı kataloğu kullanır');
    }

    public function test_unreachable_repository_keeps_the_last_copy(): void
    {
        config(['kodmob.marketplace_url' => self::URL]);
        $up = true;
        $ok = $this->remote([$this->tool('stok-takibi')]);
        Http::fake([self::URL => function () use (&$up, $ok) {
            return $up ? Http::response($ok) : Http::response('', 503);
        }]);
        $admin = $this->admin();
        $this->postJson('/v1/system/tools/catalog/refresh', [], $admin)->assertOk();

        $up = false;
        $res = $this->postJson('/v1/system/tools/catalog/refresh', [], $admin)->assertOk();
        $this->assertSame(['stok-takibi'], array_column($res->json('catalog'), 'key'));
        $this->assertStringContainsString('503', (string) $res->json('catalog_source.error'));
    }

    public function test_without_an_address_no_request_is_made_and_the_bundled_catalog_is_used(): void
    {
        Http::fake();
        $res = $this->getJson('/v1/system/tools', $this->admin())->assertOk();
        $this->assertSame('bundled', $res->json('catalog_source.source'));
        Http::assertNothingSent();
    }
}
