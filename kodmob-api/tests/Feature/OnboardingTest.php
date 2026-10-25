<?php

namespace Tests\Feature;

use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Modules\Content\AutoBackup;
use App\Modules\Site\SiteModule;
use App\Modules\Site\SiteProfile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sıradaki adımlar (Core\Setup\Onboarding; Module::steps): gerçek veriden "yapıldı", yetkiye göre. */
class OnboardingTest extends TestCase
{
    private function steps(array $h): array
    {
        return array_column($this->getJson('/v1/admin/overview', $h)->assertOk()->json('steps'), null, 'key');
    }

    public function test_steps_follow_real_data_and_permissions(): void
    {
        $h = $this->admin();
        $steps = $this->steps($h);
        $this->assertSame(['identity', 'contact', 'home', 'content', 'mail', 'backup'], array_keys($steps), 'sırayla');
        $this->assertSame([], array_keys(array_filter($steps, static fn ($s) => $s['done'])), 'yeni kurulumda hiçbiri yapılmadı');
        $this->assertSame(['route' => 'settings', 'tab' => 'mail'], array_intersect_key($steps['mail'], ['route' => 1, 'tab' => 1]));
        $this->assertSame(['type' => 'posts'], $steps['content']['params']);
        $this->assertSame('site.email', $steps['contact']['anchor'], 'ayar adımı alanın kendisine götürür');

        $s = SettingsService::instance();
        $s->set(SiteProfile::NAME, 'Harman');
        foreach ([SiteModule::EMAIL => 'info@ornek.test', SiteModule::PHONE => '0212 000 00 00', SiteModule::ADDRESS => 'İzmir',
            SiteModule::LEGAL_NAME => 'Harman Ltd.', SiteModule::LEGAL_COURT => 'İzmir'] as $key => $value) {
            $s->set($key, $value);
        }
        $now = Clock::now();
        DB::table('page_layouts')->insert(['zone' => 'home.main', 'published' => '[]', 'published_at' => $now]);
        DB::table('contents')->insert(['type' => 'km_page', 'status' => 'publish', 'title' => 'Kampanya', 'data' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('mail_log')->insert(['recipient' => 'info@ornek.test', 'subject' => 'Deneme', 'kind' => 'test', 'status' => 'sent', 'created_at' => $now]);
        AutoBackup::save(['enabled' => true]);

        $steps = $this->steps($h);
        foreach (['identity', 'contact', 'home', 'mail', 'backup'] as $key) {
            $this->assertTrue($steps[$key]['done'], $key);
        }
        $this->assertFalse($steps['content']['done'], 'blok sayfası ilk içerik sayılmaz');
        $this->postJson('/v1/admin/content/projects', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'İlk proje']]], $h)->assertCreated();
        $this->assertTrue($this->steps($h)['content']['done']);

        // Editör: sayfa düzeni ve içerik (ayar, e-posta, yedek yetkisi yok).
        $this->assertSame(['home', 'content'], array_keys($this->steps($this->as('editor'))));
    }
}
