<?php

namespace Tests\Feature;

use App\Core\Health\Health;
use App\Core\Mail\SiteMail;
use App\Core\Settings\SettingsService;
use App\Modules\System\HealthReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/** Sistem Sağlığı: kartlar (çekirdek + modül kancası), hata kodları, Bildir ve aylık rapor. */
class HealthTest extends TestCase
{
    public function test_snapshot_has_core_and_module_cards(): void
    {
        $res = $this->getJson('/v1/system/health', $this->admin())->assertOk();
        $sections = collect($res->json('sections'))->keyBy('key');
        foreach (['server', 'security', 'speed', 'database', 'storage', 'background', 'backup'] as $key) {
            $this->assertTrue($sections->has($key), "kart yok: {$key}");
            $this->assertContains($sections[$key]['level'], ['ok', 'warn', 'critical']);
        }
        $this->assertSame(['server', 'security', 'speed', 'database', 'storage', 'background', 'backup'], array_values(array_intersect($sections->keys()->all(), ['server', 'security', 'speed', 'database', 'storage', 'background', 'backup'])));
        $this->assertContains(['label' => 'Tablo güncellemeleri', 'value' => 'Güncel'], $sections['database']['rows']);
        $this->assertNotEmpty($sections['database']['list']['items'], 'en büyük tablolar');
        // Otomatik yedek kapalı: dikkat bulgusu ve Yedekleme sayfasına bağlantı.
        $this->assertSame('warn', $sections['backup']['level']);
        $this->assertSame(['route' => 'settings', 'tab' => 'backup', 'label' => 'Yedekleme'], $sections['backup']['link']);
        $this->assertContains($res->json('level'), ['warn', 'critical']);
        $res->assertJsonPath('report_to', false)->assertJsonPath('monthly', false);
    }

    public function test_requires_the_capability(): void
    {
        $this->getJson('/v1/system/health', $this->as('editor'))->assertForbidden();
        $this->getJson('/v1/system/health/errors', $this->as('editor'))->assertForbidden();
    }

    public function test_card_level_is_its_worst_finding(): void
    {
        $this->assertSame('ok', Health::section('x', 'X', 'grid', [], [Health::flag('info', 'bilgi')])['level']);
        $this->assertSame('warn', Health::section('x', 'X', 'grid', [], [Health::flag('info', 'a'), Health::flag('warn', 'b')])['level']);
        $this->assertSame('critical', Health::worst(['warn', 'critical', 'ok']));
    }

    public function test_unexpected_errors_get_a_code_and_are_listed(): void
    {
        // Uygulamadaki yazmalar gibi kendi işleminde: PostgreSQL'de başarısız sorgu testin işlemini kilitlemesin.
        Route::get('v1/test/db-error', static fn () => DB::transaction(static fn () => DB::select('select * from tablo_yok where email = ?', ['gizli@ornek.com'])));
        Route::get('v1/test/error', static fn () => throw new RuntimeException('beklenmeyen durum'));

        $db = $this->getJson('/v1/test/db-error')->assertStatus(500)->assertJsonPath('code', 'km_database_error');
        $ref = (string) $db->json('details.ref');
        $this->assertMatchesRegularExpression('/^DB-[0-9A-F]{6}$/', $ref);
        $this->assertStringContainsString($ref, (string) $db->json('message'));
        $other = (string) $this->getJson('/v1/test/error')->assertStatus(500)->json('details.ref');
        $this->assertMatchesRegularExpression('/^ERR-[0-9A-F]{6}$/', $other);

        $h = $this->admin();
        $items = $this->getJson('/v1/system/health/errors', $h)->assertOk()->json('items');
        $this->assertSame([$other, $ref], array_column($items, 'ref'), 'yeniden eskiye');
        $this->assertSame('GET v1/test/db-error', $items[1]['request']);
        $this->assertStringContainsString('tablo_yok', $items[1]['error']);
        $this->assertStringContainsString('select * from tablo_yok where email = ?', $items[1]['query']);
        // SQL parametreleri (kişisel veri olabilir) yazılmaz.
        $this->assertStringNotContainsString('gizli@ornek.com', json_encode($items, JSON_UNESCAPED_UNICODE));

        $this->deleteJson('/v1/system/health/errors', [], $h)->assertOk();
        $this->assertSame([], $this->getJson('/v1/system/health/errors', $h)->json('items'));
    }

    public function test_report_goes_to_the_configured_recipient(): void
    {
        Mail::fake();
        $h = $this->admin();
        $this->postJson('/v1/system/health/report', [], $h)->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['to']]]);

        SettingsService::instance()->set(HealthReport::TO, 'servis@ornek.com');
        $res = $this->postJson('/v1/system/health/report', [], $h)->assertOk()->assertExactJson(['sent' => true]);
        $this->assertStringNotContainsString('servis@ornek.com', $res->getContent(), 'alıcı yanıtta yok');
        Mail::assertSent(SiteMail::class, static fn (SiteMail $m) => $m->hasTo('servis@ornek.com') && str_starts_with($m->subjectLine, 'Sistem sağlığı'));
        $this->assertSame('health.reported', DB::table('events')->orderByDesc('id')->value('event_type'));
    }

    public function test_monthly_report_only_when_enabled(): void
    {
        Mail::fake();
        SettingsService::instance()->set(HealthReport::TO, 'servis@ornek.com');
        HealthReport::monthly();
        Mail::assertNothingSent();

        SettingsService::instance()->set(HealthReport::MONTHLY, true);
        HealthReport::monthly();
        Mail::assertSent(SiteMail::class, 1);
    }
}
