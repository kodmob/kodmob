<?php

namespace Tests\Feature;

use App\Core\Content\Languages;
use App\Core\Settings\SettingsService;
use App\Core\Sites\Sites;
use App\Modules\Content\PageTexts;
use App\Modules\Seo\Services\PageMeta;
use Illuminate\Support\Facades\DB;
use Tests\Support\Portfolio\ServiceType;
use Tests\TestCase;

/**
 * Site Sihirbazı (Modules\Site\SiteWizard): işletme bilgisinden öneri, kurulumda alt sitenin içine uygulanması.
 * Yapay zekâ motoru ve çevirmen sahte (kapsayıcıdan).
 */
class SiteWizardTest extends TestCase
{
    private function business(array $over = []): array
    {
        return $over + ['name' => 'Gülümse Diş Kliniği', 'sector' => 'diş kliniği', 'city' => 'Adana', 'about' => 'Seyhan\'da implant, ortodonti ve çocuk diş hekimliği; yurt dışından gelen hastalara da hizmet veriyoruz.', 'facts' => '2012\'den beri, 3 hekim', 'tone' => 'samimi', 'languages' => ['tr']];
    }

    private function site(array $plan): array
    {
        return ['key' => 'gulumse', 'name' => 'Gülümse Diş Kliniği', 'domains' => ['gulumse.kodmob.test'], 'admin' => ['username' => 'gulumse-yonetici', 'email' => 'yonetici@gulumse.test', 'password' => 'gizli-sifre-123'], 'plan' => $plan];
    }

    public function test_plan_without_ai_suggests_identity_only(): void
    {
        $h = $this->admin();
        $this->postJson('/v1/system/sites/plan', ['name' => 'X'], $h)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['sector', 'about']]]);

        $plan = $this->postJson('/v1/system/sites/plan', $this->business(), $h)->assertOk()->json();
        $this->assertFalse($plan['ai']);
        $this->assertSame(['gülümse', 'diş kliniği'], [$plan['identity']['logo'], $plan['identity']['logo_sub']]);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $plan['identity']['color']);
        $this->assertStringContainsString('Yapay zekâ anahtarı', $plan['warnings'][0]);
        $this->assertSame('#123456', $this->postJson('/v1/system/sites/plan', $this->business(['color' => '#123456']), $h)->json('identity.color'));
    }

    public function test_plan_with_ai_is_applied_to_the_new_site(): void
    {
        $h = $this->admin();
        $seen = null;
        app()->instance('sites.wizard', function (array $request) use (&$seen) {
            $seen = $request;

            return [
                'identity' => ['logo' => 'gülümse', 'logo_sub' => 'diş', 'color' => '#0E7490', 'color_dark' => 'koyu'],
                'description' => 'Adana Seyhan\'da implant, ortodonti ve çocuk diş hekimliği.',
                'services' => [['title' => 'İmplant', 'text' => 'Eksik dişler için kalıcı çözüm.'], ['title' => 'Ortodonti', 'text' => 'Şeffaf plak ve braket.'], ['title' => '']],
                'texts' => ['home.hero.title' => 'Gülüşünüz bizim işimiz', 'uydurma.anahtar' => 'x'],
            ];
        });
        app()->instance('i18n.translator', static fn (array $fields, string $from, string $to) => [
            'texts' => array_map(static fn ($t) => '[EN] '.$t, $fields['texts']),
            'services' => array_map(static fn ($s) => ['title' => '[EN] '.$s['title'], 'text' => '[EN] '.$s['text']], $fields['services']),
            'description' => '[EN] '.$fields['description'],
        ]);

        $plan = $this->postJson('/v1/system/sites/plan', $this->business(['languages' => ['tr', 'en']]), $h)->assertOk()->json();
        $this->assertTrue($plan['ai']);
        $this->assertSame(['tr' => ['home.hero.title' => 'Gülüşünüz bizim işimiz']], array_intersect_key($plan['texts'], ['tr' => 1]), 'bilinmeyen anahtar düşer');
        $this->assertSame('[EN] Gülüşünüz bizim işimiz', $plan['texts']['en']['home.hero.title']);
        $this->assertSame(['#0e7490', '#0a1633'], [$plan['identity']['color'], $plan['identity']['color_dark']], 'geçersiz renk yerine palet');
        $this->assertCount(2, $plan['services'], 'boş başlıklı hizmet düşer');
        // Yapay zekâya yalnızca işletme bilgisi ve şablon metinleri gider.
        $this->assertSame(['name', 'sector', 'city', 'about', 'facts'], array_keys($seen['facts']));
        $this->assertSame(['home.hero.title'], array_keys($seen['texts']));

        // Kullanıcı önizlemede düzenler, sonra kurar.
        $plan['texts']['tr']['home.hero.title'] = 'Gülüşünüz, bizim işimiz';
        $site = $this->postJson('/v1/system/sites', $this->site($plan), $h)->assertOk()->json();
        $this->assertSame(['texts' => 2, 'services' => 2, 'languages' => ['tr', 'en']], $site['wizard']);

        Sites::run('gulumse', function () {
            $settings = SettingsService::instance();
            $this->assertSame(['#0e7490', 'gülümse', 'Gülümse Diş Kliniği'], [$settings->get('site.color'), $settings->get('site.logo'), $settings->get('site.name')]);
            $this->assertSame('Gülüşünüz, bizim işimiz', PageTexts::overrides()['tr']['home.hero.title']);
            $this->assertSame('[EN] Gülüşünüz bizim işimiz', PageTexts::overrides()['en']['home.hero.title']);
            $services = DB::table('contents')->where('type', ServiceType::POST_TYPE)->get();
            $this->assertSame(['draft', 'draft'], $services->pluck('status')->all(), 'hizmetler taslak');
            $this->assertSame('Gülümse Diş Kliniği · diş kliniği', PageMeta::all()['home']['tr']['title'] ?? null);
        });
        $this->assertFalse(DB::table('contents')->where('type', ServiceType::POST_TYPE)->exists(), 'ana siteye dokunulmaz');
    }

    public function test_unselected_languages_are_switched_off(): void
    {
        $h = $this->admin();
        $plan = $this->postJson('/v1/system/sites/plan', $this->business(), $h)->json();
        $site = $this->postJson('/v1/system/sites', $this->site($plan), $h)->assertOk()->json();
        $this->assertSame(['tr'], $site['wizard']['languages']);
        Sites::run('gulumse', function () {
            Languages::forget();
            $this->assertSame(['tr'], array_values(array_map(static fn ($l) => $l['code'], array_filter(Languages::all(), static fn ($l) => $l['active']))));
        });
    }
}
