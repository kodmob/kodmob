<?php

namespace Tests\Feature;

use App\Core\Ai\Ai;
use App\Core\Ai\AiSettings;
use App\Core\Ai\Providers\GeminiProvider;
use App\Core\Ai\Providers\OpenAiProvider;
use App\Core\Support\Options;
use App\Core\Support\Secret;
use App\Modules\Auth\Models\User;
use App\Modules\I18n\ContentTranslator;
use App\Modules\Jarvis\Skills\SkillRegistry;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Yapay zekâ sağlayıcıları (App\Core\Ai): ayarlar, OpenAI ve Gemini istek / yanıt çevirisi, Jarvis, çeviri. */
class AiTest extends TestCase
{
    private const OPENAI_KEY = 'sk-proj-test-0123456789abcdefghij';

    private const GOOGLE_KEY = 'AIzaTest0123456789abcdefghijklmnop';

    public function test_settings_default_to_claude_and_migrate_old_jarvis_key(): void
    {
        $view = AiSettings::view();
        $this->assertSame('anthropic', $view['provider']);
        $this->assertSame(['anthropic', 'openai', 'google'], array_column($view['providers'], 'key'));
        $this->assertSame(['claude-opus-5-5', 'gpt-6.1-sol', 'gemini-3.8-flash'], array_column($view['providers'], 'model'));

        // Eski Jarvis ayarı: tek Claude anahtarı → Claude sağlayıcısı (anahtar şifreli taşınır).
        Options::set('jarvis_ai', ['api_key' => Secret::seal('sk-ant-eski-anahtar-0123456789abcdef'), 'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'max_steps' => 7]);
        $s = AiSettings::get();
        $this->assertSame(['sk-ant-eski-anahtar-0123456789abcdef', 'claude-sonnet-5-5', 'high', 7], [$s['providers']['anthropic']['api_key'], $s['providers']['anthropic']['model'], $s['effort'], $s['max_steps']]);
        $this->assertTrue(Ai::available());
    }

    public function test_settings_endpoints_keep_keys_secret(): void
    {
        $h = $this->admin();
        $this->getJson('/v1/system/ai', $this->as('editor'))->assertForbidden();
        $view = $this->putJson('/v1/system/ai', [
            'provider' => 'openai',
            'providers' => ['openai' => ['api_key' => self::OPENAI_KEY, 'model' => 'gpt-6-luna'], 'google' => ['model' => 'gemini-yeni-model-9']],
        ], $h)->assertOk()->json();
        $openai = collect($view['providers'])->keyBy('key')['openai'];
        $this->assertSame(['openai', true, '…ghij', 'gpt-6-luna'], [$view['provider'], $openai['configured'], $openai['key_hint'], $openai['model']]);
        $this->assertSame('gemini-yeni-model-9', collect($view['providers'])->keyBy('key')['google']['model'], 'listede olmayan model de kabul edilir');
        $this->assertStringNotContainsString(self::OPENAI_KEY, json_encode($view));
        $this->assertStringNotContainsString(self::OPENAI_KEY, json_encode(Options::get('ai_settings')), 'şifreli saklanır');
        $this->assertStringNotContainsString(self::OPENAI_KEY, (string) DB::table('events')->get()->toJson());

        $this->putJson('/v1/system/ai', ['provider' => 'yok'], $h)->assertStatus(422);
        $this->putJson('/v1/system/ai', ['providers' => ['openai' => ['api_key' => 'kısa']]], $h)->assertStatus(422);
        $this->putJson('/v1/system/ai', ['providers' => ['openai' => ['clear_key' => true]]], $h)->assertJsonPath('configured', false);
    }

    public function test_openai_responses_api_round_trip_with_a_tool(): void
    {
        $this->useProvider('openai', self::OPENAI_KEY, 'gpt-6.1-sol');
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'şifreli', 'summary' => []];
        $call = ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'site_overview', 'arguments' => '{}', 'status' => 'completed'];
        Http::fake([OpenAiProvider::URL.'/responses' => Http::sequence()
            ->push(['model' => 'gpt-6.1-sol', 'status' => 'completed', 'output' => [$reasoning, $call], 'usage' => ['input_tokens' => 120, 'output_tokens' => 30]])
            ->push(['model' => 'gpt-6.1-sol', 'status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Sitede 0 proje var.']]]], 'usage' => ['input_tokens' => 200, 'output_tokens' => 12]]),
        ]);

        $h = $this->admin();
        $id = $this->postJson('/v1/jarvis/conversations', ['title' => 'Deneme'], $h)->assertCreated()->json('id');
        $res = $this->postJson("/v1/jarvis/conversations/{$id}/messages", ['text' => 'Sitede kaç proje var?'], $h)->assertOk()->json();
        $this->assertNull($res['error']);
        $this->assertFalse($res['pending']);
        $this->assertStringContainsString('Sitede 0 proje var.', json_encode($res['transcript'], JSON_UNESCAPED_UNICODE));

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $first = $sent[0];
        $this->assertSame([false, ['reasoning.encrypted_content'], 'gpt-6.1-sol', ['effort' => 'medium']], [$first['store'], $first['include'], $first['model'], $first['reasoning']]);
        $this->assertStringContainsString("Sen Jarvis'sin", $first['instructions']);
        $this->assertSame(['role' => 'user', 'content' => 'Sitede kaç proje var?'], $first['input'][0]);
        $overview = collect($first['tools'])->firstWhere('name', 'site_overview');
        $this->assertSame('function', $overview['type']);
        $this->assertContains(['type' => 'web_search'], $first['tools']);
        // İkinci tur: düşünme öğesi ve çağrı aynen, ardından araç sonucu.
        $second = $sent[1]['input'];
        $this->assertSame($reasoning, $second[1]);
        $this->assertSame($call, $second[2]);
        $this->assertSame(['function_call_output', 'call_1'], [$second[3]['type'], $second[3]['call_id']]);

        $stored = DB::table('jarvis_messages')->where('conversation_id', $id)->where('role', 'assistant')->orderBy('id')->first();
        $this->assertSame('openai', json_decode($stored->native, true)['provider']);
        $this->assertSame('tool_use', json_decode($stored->content, true)[0]['type']);
    }

    public function test_gemini_request_and_response_shape(): void
    {
        $this->useProvider('google', self::GOOGLE_KEY, 'gemini-3.8-flash');
        $modelParts = [['functionCall' => ['name' => 'site_overview', 'args' => []], 'thoughtSignature' => 'imza-1']];
        Http::fake([GeminiProvider::URL.'/models/gemini-3.8-flash:generateContent' => Http::sequence()
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => $modelParts], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 10]])
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Tamam: '], ['text' => 'özet hazır.']]], 'finishReason' => 'STOP']]]),
        ]);
        $tool = ['name' => 'site_overview', 'description' => 'Özet', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string', 'default' => 'x']], 'additionalProperties' => false]];
        $history = [
            // Claude'la başlamış bir sohbet: Claude'un araç turu Gemini'ye düz metin olarak gider.
            ['role' => 'user', 'content' => 'Merhaba'],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'site_overview', 'input' => []]], 'native' => ['provider' => 'anthropic']],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"projects":0}', 'is_error' => false]]],
            ['role' => 'user', 'content' => 'Şimdi tekrar özetle'],
        ];

        $first = Ai::send($history, [['type' => 'text', 'text' => 'Kısa yanıt ver.']], [$tool]);
        $this->assertSame(['tool_use', 'site_overview'], [$first['stop_reason'], $first['content'][0]['name']]);
        $this->assertSame(['input_tokens' => 50, 'output_tokens' => 15], $first['usage'], 'düşünme jetonları çıktıya sayılır');
        $this->assertSame($modelParts, $first['native']['parts'], 'imza saklanır');

        $history[] = ['role' => 'assistant', 'content' => $first['content'], 'native' => $first['native']];
        $history[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $first['content'][0]['id'], 'content' => '{"projects":0}', 'is_error' => false]]];
        $second = Ai::send($history, [['type' => 'text', 'text' => 'Kısa yanıt ver.']], [$tool]);
        $this->assertSame([['type' => 'text', 'text' => 'Tamam: özet hazır.']], $second['content']);

        $bodies = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $body = $bodies[0];
        $this->assertSame(['parts' => [['text' => 'Kısa yanıt ver.']]], $body['systemInstruction']);
        $this->assertSame(['user', 'model', 'user'], array_column($body['contents'], 'role'), 'aynı rol art arda birleşir');
        $this->assertStringContainsString('[Araç çağrıldı: site_overview', $body['contents'][1]['parts'][0]['text']);
        $this->assertStringContainsString('[Araç sonucu (site_overview)', $body['contents'][2]['parts'][0]['text']);
        $declaration = $body['tools'][0]['functionDeclarations'][0];
        $this->assertSame(['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], $declaration['parameters'], 'desteklenmeyen anahtarlar atılır');
        // İkinci istekte Gemini'nin kendi turu imzasıyla, sonucu işlev yanıtı olarak.
        $contents = $bodies[1]['contents'];
        $this->assertSame($modelParts, $contents[3]['parts']);
        $this->assertSame(['name' => 'site_overview', 'response' => ['result' => '{"projects":0}']], $contents[4]['parts'][0]['functionResponse']);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-goog-api-key', self::GOOGLE_KEY));
    }

    public function test_translation_and_model_list_use_the_active_provider(): void
    {
        $this->useProvider('google', self::GOOGLE_KEY, 'gemini-3.8-flash');
        Http::fake([
            GeminiProvider::URL.'/models/gemini-3.8-flash:generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"title":"Hello"}']]], 'finishReason' => 'STOP']]]),
            GeminiProvider::URL.'/models*' => Http::response(['models' => [
                ['name' => 'models/gemini-3.8-flash', 'displayName' => 'Gemini 3.8 Flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/text-embedding-9', 'displayName' => 'Embedding', 'supportedGenerationMethods' => ['embedContent']],
            ]]),
        ]);
        $this->assertTrue(ContentTranslator::available());
        $this->assertSame(['title' => 'Hello'], (new ContentTranslator)->translate(['title' => 'Merhaba'], 'tr', 'en'));

        $this->postJson('/v1/system/ai/models', ['provider' => 'google'], $this->admin())
            ->assertOk()->assertJsonPath('models', [['id' => 'gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash']]);
        $this->postJson('/v1/system/ai/models', ['provider' => 'openai'], $this->admin())->assertStatus(422);
    }

    public function test_provider_errors_are_explained(): void
    {
        $this->useProvider('openai', self::OPENAI_KEY, 'gpt-6.1-sol');
        Http::fake([OpenAiProvider::URL.'/responses' => Http::sequence()
            ->push(['error' => ['message' => 'Incorrect API key']], 401)
            ->push(['error' => ['message' => 'The model does not exist']], 404)]);
        $h = $this->admin();
        $this->postJson('/v1/system/ai/test', ['provider' => 'openai'], $h)->assertStatus(401)->assertJsonPath('code', 'km_ai_auth');
        $this->postJson('/v1/system/ai/test', ['provider' => 'openai'], $h)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'model bulunamadı'));
    }

    private function useProvider(string $provider, string $key, string $model): void
    {
        AiSettings::save(['provider' => $provider, 'providers' => [$provider => ['api_key' => $key, 'model' => $model]]]);
    }

    public function test_site_overview_tool_runs_for_a_user_and_without_one(): void
    {
        $this->admin();
        $this->postJson('/v1/briefs', ['name' => 'Ayşe Deneme', 'email' => 'ayse@example.com', 'services' => ['web'], 'message' => 'Kurumsal web sitesi için teklif almak istiyoruz, teşekkürler.', 'consent' => true, 'locale' => 'tr', 'started_at' => (time() - 60) * 1000])->assertCreated();
        Auth::setUser(User::query()->where('username', 'yonetici')->firstOrFail());
        $out = SkillRegistry::get('site_overview')->run([]);
        $this->assertArrayHasKey('projects', $out['counts']);
        $this->assertSame(1, $out['briefs']['new']);

        // Zamanlanmış işte kullanıcı yok: araç yine çalışır, talepler gösterilmez.
        Auth::forgetUser();
        $this->assertSame(0, SkillRegistry::get('site_overview')->run([])['briefs']['new']);
    }
}
