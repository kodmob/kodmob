<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * İstek sınırları (`throttle:N,1`) yol başına sayılır. Laravel'in varsayılanı kullanıcı (ya da IP)
 * başına tek sayaç tutar: panel aramasında harcanan istekler Site Sihirbazı'nın 6/dk sınırını
 * doldurup "Öneri oluştur"u 429'a düşürüyordu (uçtan uca testte yakalandı).
 */
class RateLimitTest extends TestCase
{
    public function test_each_route_has_its_own_counter(): void
    {
        $h = $this->admin();
        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/v1/search?q=proje', $h)->assertOk();
        }
        $this->postJson('/v1/system/sites/plan', ['name' => 'X'], $h)->assertStatus(422);
    }

    public function test_the_route_limit_still_applies(): void
    {
        $h = $this->admin();
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/v1/system/sites/plan', ['name' => 'X'], $h)->assertStatus(422);
        }
        $this->postJson('/v1/system/sites/plan', ['name' => 'X'], $h)->assertStatus(429);
        // Başka yol etkilenmez.
        $this->getJson('/v1/search?q=proje', $h)->assertOk();
    }
}
