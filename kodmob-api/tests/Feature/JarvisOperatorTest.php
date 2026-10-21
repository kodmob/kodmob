<?php

namespace Tests\Feature;

use App\Core\Ai\AiSettings;
use App\Core\Ai\Providers\OpenAiProvider;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Jarvis'in sözleşme araçları ve Operatör (Modules\Jarvis\Skills\ContractTools, Assistant\JarvisActions): okuma aracı
 * panel ucunu kullanıcının adına çağırır ve kişisel veriyi süzer; yazma aracı önerilir, onaylanınca çalışır (İşlem
 * Geçmişi kullanıcının adıyla), reddedilince çalışmaz; öneri beklerken yeni mesaj gönderilemez; araçlar yetkiye göre.
 */
class JarvisOperatorTest extends TestCase
{
    private function provider(array ...$outputs): void
    {
        AiSettings::save(['provider' => 'openai', 'providers' => ['openai' => ['api_key' => 'sk-proj-test-0123456789abcdefghij', 'model' => 'gpt-6.1-sol']]]);
        $sequence = Http::sequence();
        foreach ($outputs as $output) {
            $sequence->push(['model' => 'gpt-6.1-sol', 'status' => 'completed', 'output' => $output, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]);
        }
        Http::fake([OpenAiProvider::URL.'/responses' => $sequence]);
    }

    private static function toolCall(string $name, array $args, string $id = 'call_1'): array
    {
        return [['type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => $id, 'name' => $name, 'arguments' => json_encode($args), 'status' => 'completed']];
    }

    private static function say(string $text): array
    {
        return [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]];
    }

    private function conversation(array $h): int
    {
        return (int) $this->postJson('/v1/jarvis/conversations', ['title' => 'Operatör'], $h)->assertCreated()->json('id');
    }

    public function test_read_tool_calls_the_panel_endpoint_as_the_user_and_strips_personal_data(): void
    {
        DB::table('seo_404')->insert([
            'path' => '/eski-hizmet/', 'path_hash' => sha1('/eski-hizmet/'), 'hits' => 7, 'referrer' => 'https://ornek.com/yazi?email=ayse@example.com',
            'bot' => false, 'internal' => false, 'suggestion' => '/hizmetler/', 'status' => 'new', 'first_seen_at' => Clock::now(), 'last_seen_at' => Clock::now(),
        ]);
        $this->provider(self::toolCall('seo_not_found', []), self::say('Bir kırık adres var.'));
        $h = $this->admin();
        $id = $this->conversation($h);
        $res = $this->postJson("/v1/jarvis/conversations/{$id}/messages", ['text' => 'Kırık adresler var mı?'], $h)->assertOk()->json();
        $this->assertNull($res['error']);
        $this->assertSame([], $res['actions']);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $tools = collect($sent[0]['tools'])->pluck('name');
        $this->assertTrue($tools->contains('seo_not_found'));
        $this->assertTrue($tools->contains('redirect_create'));
        $output = collect($sent[1]['input'])->firstWhere('type', 'function_call_output')['output'];
        $this->assertStringContainsString('"path":"/eski-hizmet/"', $output);
        $this->assertStringContainsString('"referrer_host":"ornek.com"', $output);
        $this->assertStringNotContainsString('ayse@example.com', $output);
    }

    public function test_write_tool_waits_for_approval_and_runs_in_the_users_name(): void
    {
        $this->provider(self::toolCall('redirect_create', ['source' => '/eski/', 'target' => '/yeni/', 'code' => 301]), self::say('Yönlendirme eklendi.'));
        $h = $this->admin();
        $id = $this->conversation($h);
        $res = $this->postJson("/v1/jarvis/conversations/{$id}/messages", ['text' => '/eski/ adresini /yeni/ adresine yönlendir.'], $h)->assertOk()->json();
        $this->assertFalse($res['pending']);
        $this->assertCount(1, $res['actions']);
        $action = $res['actions'][0];
        $this->assertSame(['redirect_create', 'Yönlendirme ekle: /eski/ → /yeni/ (301)'], [$action['tool'], $action['summary']]);
        $this->assertSame(0, DB::table('seo_redirects')->count(), 'onaysız çalışmaz');
        // Öneri beklerken yeni mesaj olmaz (araç sonucu eksik kalırdı).
        $this->postJson("/v1/jarvis/conversations/{$id}/messages", ['text' => 'Başka bir şey'], $h)->assertStatus(409)->assertJsonPath('code', 'km_jarvis_awaiting');
        $this->assertSame($action['id'], $this->getJson("/v1/jarvis/conversations/{$id}", $h)->assertOk()->json('actions.0.id'));

        $done = $this->postJson("/v1/jarvis/actions/{$action['id']}/approve", [], $h)->assertOk()->json();
        $this->assertSame([], $done['actions']);
        $row = DB::table('seo_redirects')->first();
        $this->assertSame(['/eski', '/yeni/', 301], [$row->source, $row->target, (int) $row->code]);
        $this->assertStringContainsString('Yönlendirme eklendi.', json_encode($done['transcript'], JSON_UNESCAPED_UNICODE));
        // İşlem Geçmişi: yönlendirme ve onay, ikisi de kullanıcının adıyla.
        $user = $this->userId('yonetici');
        $this->assertSame(1, DB::table('events')->where('event_type', 'seo.redirect_created')->where('user_id', $user)->count());
        $this->assertSame(1, DB::table('events')->where('event_type', 'jarvis.action_approved')->where('user_id', $user)->count());
        $this->postJson("/v1/jarvis/actions/{$action['id']}/approve", [], $h)->assertStatus(409);
    }

    public function test_rejected_proposal_does_not_run(): void
    {
        $this->provider(self::toolCall('redirect_create', ['source' => '/a/', 'target' => '/b/']), self::say('Tamam, yapmıyorum.'));
        $h = $this->admin();
        $id = $this->conversation($h);
        $action = $this->postJson("/v1/jarvis/conversations/{$id}/messages", ['text' => 'Yönlendir.'], $h)->assertOk()->json('actions.0');
        $res = $this->postJson("/v1/jarvis/actions/{$action['id']}/reject", [], $h)->assertOk()->json();
        $this->assertSame(0, DB::table('seo_redirects')->count());
        $this->assertSame('rejected', DB::table('jarvis_actions')->where('id', $action['id'])->value('status'));
        $this->assertStringContainsString('onaylamadı', (string) Http::recorded()->map(fn ($p) => $p[0]->data())->last()['input'][2]['output']);
        $this->assertStringContainsString('Tamam, yapmıyorum.', json_encode($res['transcript'], JSON_UNESCAPED_UNICODE));
        // Başkasının önerisine karar verilemez.
        $this->postJson("/v1/jarvis/actions/{$action['id']}/approve", [], $this->as('sales'))->assertNotFound();
    }

    public function test_tools_follow_the_users_permissions(): void
    {
        // Satış rolü (SEO yetkisi yok): SEO araçları ona verilmez.
        $this->provider(self::say('Merhaba.'));
        $sales = $this->as('sales');
        $sid = $this->conversation($sales);
        $this->postJson("/v1/jarvis/conversations/{$sid}/messages", ['text' => 'Merhaba'], $sales)->assertOk();
        $tools = collect(Http::recorded()->map(fn ($p) => $p[0]->data())->last()['tools'])->pluck('name');
        $this->assertFalse($tools->contains('seo_not_found'));
        $this->assertFalse($tools->contains('redirect_create'));
        $this->assertTrue($tools->contains('site_overview'));
    }
}
