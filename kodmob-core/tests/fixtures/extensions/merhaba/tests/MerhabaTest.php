<?php

namespace KodmobTools\Merhaba\Tests;

use Tests\TestCase;

/** Merhaba aracı: uç yetkiye bağlı ve projenin ayarını okur. */
class MerhabaTest extends TestCase
{
    public function test_greeting_needs_the_capability(): void
    {
        $this->getJson('/v1/tools/merhaba', $this->admin())->assertOk()->assertJsonPath('message', 'Merhaba!');
        $this->getJson('/v1/tools/merhaba', $this->as('editor'))->assertForbidden();
    }
}
