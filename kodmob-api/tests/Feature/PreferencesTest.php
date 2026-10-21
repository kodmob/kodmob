<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Kişiye özel tercihler: Diğer İşlemler > Hızlı İşlemler. */
class PreferencesTest extends TestCase
{
    public function test_quick_actions_are_personal_and_validated(): void
    {
        $admin = $this->admin();
        $editor = $this->as('editor');
        $this->getJson('/v1/me/quick-actions', $admin)->assertOk()->assertExactJson(['keys' => null]);

        $this->putJson('/v1/me/quick-actions', ['keys' => ['posts', 'settings-sms', 'posts', 'briefs']], $admin)
            ->assertOk()->assertExactJson(['keys' => ['posts', 'settings-sms', 'briefs']]);
        $this->getJson('/v1/me/quick-actions', $admin)->assertJsonPath('keys', ['posts', 'settings-sms', 'briefs']);
        $this->getJson('/v1/me/quick-actions', $editor)->assertExactJson(['keys' => null]); // başkasının seçimi görünmez

        $this->putJson('/v1/me/quick-actions', ['keys' => ['Büyük Harf']], $admin)->assertStatus(422);
        $this->putJson('/v1/me/quick-actions', ['keys' => array_map(static fn ($i) => "is-$i", range(1, 13))], $admin)->assertStatus(422);
        $this->putJson('/v1/me/quick-actions', ['keys' => 'posts'], $admin)->assertStatus(422);

        // Boş liste "hiç yok" demektir (varsayılan değil); silmek varsayılana döndürür.
        $this->putJson('/v1/me/quick-actions', ['keys' => []], $admin)->assertExactJson(['keys' => []]);
        $this->deleteJson('/v1/me/quick-actions', [], $admin)->assertExactJson(['keys' => null]);
        $this->getJson('/v1/me/quick-actions', $admin)->assertExactJson(['keys' => null]);

        $this->assertSame(0, DB::table('events')->where('event_type', 'like', '%quick%')->count(), 'işlem geçmişine yazılmaz');
        $this->getJson('/v1/me', $admin)->assertJsonMissingPath('preferences');
    }
}
