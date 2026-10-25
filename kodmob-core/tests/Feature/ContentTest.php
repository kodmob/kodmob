<?php

namespace Tests\Feature;

use App\Core\Settings\SettingDefinition;
use Tests\TestCase;

class ContentTest extends TestCase
{
    public function test_project_lifecycle(): void
    {
        $h = $this->admin();

        $created = $this->postJson('/v1/admin/content/projects', [
            'status' => 'publish',
            'categories' => ['web', 'yok'],
            'live_url' => 'ornek.com',
            'i18n' => ['tr' => ['title' => 'Çiftçi Pazarı', 'tags' => ['a', '', 'b']], 'en' => ['title' => 'Farmer Market']],
        ], $h)->assertCreated();
        $created->assertJsonPath('i18n.tr.slug', 'ciftci-pazari')
            ->assertJsonPath('i18n.en.slug', 'farmer-market')
            ->assertJsonPath('categories', ['web'])
            ->assertJsonPath('live_url', 'http://ornek.com')
            ->assertJsonPath('i18n.tr.tags', ['a', 'b']);
        $id = $created->json('id');

        $second = $this->postJson('/v1/admin/content/projects', ['status' => 'draft', 'i18n' => ['tr' => ['title' => 'Çiftçi Pazarı']]], $h)->assertCreated();
        $second->assertJsonPath('i18n.tr.slug', 'ciftci-pazari-2');

        $data = $this->getJson('/v1/page-data?route=project&locale=tr&slug=ciftci-pazari')->assertOk();
        $data->assertJsonPath('entry.title', 'Çiftçi Pazarı')->assertJsonPath('entry.slugs', ['tr' => 'ciftci-pazari', 'en' => 'farmer-market']);
        $this->getJson('/v1/page-data?route=project&locale=tr&slug=ciftci-pazari-2')->assertJsonPath('notFound', true);

        $this->postJson('/v1/admin/content/projects/order', ['ids' => [$second->json('id'), $id]], $h)->assertNoContent();
        $this->assertSame([$second->json('id'), $id], array_column($this->getJson('/v1/admin/content/projects', $h)->json('items'), 'id'));

        $this->deleteJson("/v1/admin/content/projects/$id", [], $h)->assertNoContent();
        $this->getJson("/v1/admin/content/projects/$id", $h)->assertNotFound()->assertJsonPath('code', 'km_not_found');
    }

    public function test_validation_errors_use_field_paths(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/admin/content/projects', ['i18n' => []], $h)
            ->assertStatus(422)->assertJsonPath('code', 'km_validation_failed')->assertJsonStructure(['details' => ['fields' => ['i18n.tr.title']]]);

        $this->postJson('/v1/admin/content/services', ['key' => 'web', 'i18n' => ['tr' => ['title' => 'Web']]], $h)->assertCreated();
        $this->postJson('/v1/admin/content/services', ['key' => 'web', 'i18n' => ['tr' => ['title' => 'Kopya']]], $h)
            ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['key']]]);
    }

    public function test_blog_html_is_sanitized_and_future_posts_hidden(): void
    {
        $h = $this->admin();
        $post = $this->postJson('/v1/admin/content/posts', [
            'status' => 'publish',
            'title' => 'Zamanlı Yazı',
            'locale' => 'tr',
            'date' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'html' => '<p onclick="x()">Merhaba <script>alert(1)</script><a href="javascript:x" target="_blank">bağ</a></p>',
        ], $h)->assertCreated();

        $post->assertJsonPath('status', 'future')->assertJsonPath('slug', 'zamanli-yazi')->assertJsonPath('html', '<p>Merhaba <a target="_blank" rel="noopener">bağ</a></p>');
        $this->assertSame([], $this->getJson('/v1/page-data?route=blog&locale=tr&needs=posts')->json('posts'));

        $this->postJson('/v1/admin/content/posts', ['status' => 'publish', 'title' => 'Yayında', 'locale' => 'tr', 'html' => '<p>Bir iki üç dört beş</p>'], $h)->assertCreated();
        $this->getJson('/v1/page-data?route=blog&locale=tr&needs=posts')->assertJsonCount(1, 'posts')->assertJsonPath('posts.0.excerpt', 'Bir iki üç dört beş');
    }

    public function test_settings_partial_save(): void
    {
        $h = $this->admin();
        $this->putJson('/v1/settings', ['values' => ['site.phone' => '+90 555', 'site.hours_start' => 99, 'yok' => 1]], $h)
            ->assertOk()->assertJsonPath('changed', ['site.phone'])->assertJsonStructure(['errors' => ['site.hours_start', 'yok']]);
        $this->getJson('/v1/site')->assertJsonPath('phone', '+90 555');
    }

    /** Ayarın yeri: Proje Ayarları (kimlik, iletişim, talepler…) ya da Sistem Ayarları (güvenlik, sistem sağlığı). */
    public function test_settings_carry_their_scope(): void
    {
        $scopes = collect($this->getJson('/v1/settings', $this->admin())->assertOk()->json('items'))->pluck('scope', 'key');
        $this->assertSame(['project', 'project', 'project', 'system', 'system'], [
            $scopes['site.name'], $scopes['site.phone'], $scopes['brief.notify_email'], $scopes['health.report_to'], $scopes['security.waf'],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        new SettingDefinition('x.y', 'string', '', 'X', scope: 'galaxy');
    }

    public function test_page_texts_and_estimate(): void
    {
        $h = $this->admin();
        $this->assertSame('Varsayılan başlık', $this->getJson('/v1/admin/texts', $h)->json('texts')['home.hero.title']['tr']);

        $this->putJson('/v1/admin/texts', ['values' => ['tr' => ['home.hero.title' => 'Yeni <b>başlık</b>', 'bilinmeyen' => 'x']]], $h)
            ->assertOk()->assertExactJson(['values' => ['tr' => ['home.hero.title' => 'Yeni başlık']]]);
        // Boş metin sözlükteki hale döndürür (ConvertEmptyStringsToNull kapalı olmalı).
        $this->putJson('/v1/admin/texts', ['values' => ['tr' => ['home.hero.title' => '']]], $h)->assertOk();
        $this->assertEmpty((array) $this->getJson('/v1/admin/texts', $h)->json('values'));

        $this->putJson('/v1/admin/estimate', ['value' => ['prices' => ['TRY' => ['types' => ['landing' => [9, 1]]]]]], $h)
            ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['prices.TRY.types.landing']]]);
        $this->putJson('/v1/admin/estimate', ['value' => ['prices' => ['TRY' => ['types' => ['landing' => [1000, 2000]]]]]], $h)
            ->assertOk()->assertJsonPath('value.prices.TRY.types.landing', [1000, 2000]);
    }
}
