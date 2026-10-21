<?php

namespace Tests\Feature;

use App\Core\Security\Capability;
use App\Modules\Jarvis\Assistant\SystemPrompt;
use App\Modules\Jarvis\Skills\SkillRegistry;
use Tests\TestCase;

/**
 * Domain arama ayrı modül (Modules\Domains): uçları, araçlarını Jarvis'e kendi yetkisiyle eklemesi ve sohbet
 * kuralları. Ağa çıkan kontrol (DoH, RDAP) burada denenmez.
 */
class DomainsTest extends TestCase
{
    public function test_endpoints_answer_without_network(): void
    {
        $h = $this->admin();
        $options = $this->getJson('/v1/jarvis/domains/options', $h)->assertOk()->json();
        $this->assertNotEmpty($options['tlds']);
        $this->getJson('/v1/jarvis/jobs', $h)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/v1/jarvis/domains', $h)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/v1/jarvis/domains/options', $this->as('editor'))->assertForbidden();
    }

    public function test_tools_and_rules_are_added_to_jarvis(): void
    {
        foreach (['domain_check', 'domain_scan', 'domain_results', 'jarvis_job'] as $name) {
            $this->assertTrue(SkillRegistry::has($name), $name);
            $this->assertSame(Capability::USE_DOMAINS, SkillRegistry::capability($name));
        }
        $this->assertSame(Capability::VIEW_OVERVIEW, SkillRegistry::capability('site_overview'));
        $this->assertStringContainsString('Domain arama kuralları', SystemPrompt::blocks()[0]['text']);
    }
}
