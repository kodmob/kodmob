<?php

namespace Tests\Unit;

use App\Core\Support\HtmlSanitizer;
use App\Core\Support\Text;
use App\Modules\Site\Render\EntrySsr;
use App\Modules\Site\Render\ListSsr;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_sanitizer_keeps_editor_markup_and_drops_dangerous_parts(): void
    {
        $html = '<!-- wp:paragraph --><p class="x" onclick="a()">Şirket <b>güçlü</b> <a href="javascript:alert(1)">x</a> <a href="https://k.com" target="_blank">y</a></p>'
            .'<script>alert(1)</script><figure><img src="/media/a.webp" srcset="/media/a-300.webp 300w, javascript:x 2w" alt="Ağaç" style="x" width="10"><figcaption>Not</figcaption></figure>'
            .'<custom>iç <em>metin</em></custom><iframe src="x"></iframe>';

        $this->assertSame(
            '<p>Şirket <b>güçlü</b> <a>x</a> <a href="https://k.com" target="_blank" rel="noopener">y</a></p><figure><img src="/media/a.webp" srcset="/media/a-300.webp 300w" alt="Ağaç" width="10"><figcaption>Not</figcaption></figure>iç <em>metin</em>',
            HtmlSanitizer::clean($html)
        );
    }

    public function test_text_helpers(): void
    {
        $this->assertSame('dijital-tarim-platformu-cag-omur-is', Text::slug('Dijital Tarım Platformu: Çağ & Ömür İş'));
        $this->assertSame('http://kodmob.com/x', Text::url('kodmob.com/x'));
        $this->assertSame('', Text::url('javascript:alert(1)'));
        $this->assertSame('/tr/', Text::url('/tr/'));
        $this->assertSame('Ad Soyad %65', Text::field("  <b>Ad</b>\n  Soyad %65 "));
        $this->assertSame("Satır 1\n\nSatır 2", Text::textarea("Satır 1\r\n\r\n\r\n\r\nSatır 2  "));
        $this->assertSame('ekran-goruntusu-2.png', Text::fileName('Ekran Görüntüsü (2).PNG'));
        $this->assertSame('12.500', Text::number(12500));
    }

    public function test_entry_ssr_fills_markers_and_opens_gate(): void
    {
        $html = '<main><template x-if="$store.km.entry" data-km-ssr><article>'
            .'<h1 x-text="p.tagline || p.title"><!--km:e:tagline|title--></h1>'
            .'<p x-text="p.client"><!--km:e:client--></p>'
            .'<div x-html="p.html"><!--km:h:html--></div>'
            .'<div x-html="$store.km.paragraphs(p.about)"><!--km:p:about--></div>'
            .'</article></template><!--/km:ssr--></main>';
        $entry = ['tagline' => '', 'title' => 'A & <B>', 'html' => '<p>Gövde</p>', 'about' => "Bir\n\nİki <i>"];

        $this->assertSame(
            '<main><article><h1 x-text="p.tagline || p.title">A &amp; &lt;B&gt;</h1><p x-text="p.client"></p>'
            .'<div x-html="p.html"><p>Gövde</p></div><div x-html="$store.km.paragraphs(p.about)"><p>Bir</p><p>İki &lt;i&gt;</p></div></article></main>',
            EntrySsr::apply($html, $entry)
        );
        // İşaretsiz şablon olduğu gibi kalır.
        $this->assertSame('<main><h1>x</h1></main>', EntrySsr::apply('<main><h1>x</h1></main>', $entry));
    }

    public function test_list_ssr_renders_items_before_the_template(): void
    {
        $html = '<div><template x-for="(p, i) in $store.km.projects" :key="p.id" data-km-ssr-list="projects" data-km-ssr-route="project" data-km-ssr-prefer="featured" data-km-ssr-limit="2">'
            .'<a :href="p.url" class="card" x-cloak><h3 x-text="p.title"></h3><p x-text="p.summary"></p>'
            .'<template x-for="t in p.tags"><span x-text="t"></span></template><img :alt="p.title"></a></template></div>';
        $data = ['projects' => [
            ['id' => 1, 'slug' => 'a', 'title' => 'A & B', 'summary' => 'Özet', 'featured' => true],
            ['id' => 2, 'slug' => 'b', 'title' => 'Öne çıkmayan', 'summary' => '', 'featured' => false],
            ['id' => 3, 'slug' => 'c', 'title' => 'C', 'summary' => 'Üç', 'featured' => true],
            ['id' => 4, 'slug' => 'd', 'title' => 'D', 'summary' => 'Dört', 'featured' => true],
        ]];

        $out = ListSsr::apply($html, $data, static fn (string $route, string $slug) => "/{$route}/{$slug}/");

        $this->assertSame(2, substr_count($out, 'data-km-ssr-item'), 'öne çıkanlar, en çok 2');
        $this->assertStringContainsString('<a data-km-ssr-item :href="p.url" href="/project/a/" class="card"><h3 x-text="p.title">A &amp; B</h3><p x-text="p.summary">Özet</p>', $out);
        $this->assertStringContainsString('<img :alt="p.title" alt="C">', $out);
        $this->assertStringNotContainsString('Öne çıkmayan', $out);
        $this->assertStringContainsString('<template x-for="t in p.tags"><span x-text="t"></span></template>', $out, 'iç şablon Alpine\'a kalır');
        $this->assertStringContainsString('</a><template x-for="(p, i) in $store.km.projects"', $out, 'kopyalar şablonun önünde, şablon yerinde');
        $this->assertSame($html, ListSsr::apply($html, ['projects' => []], static fn () => ''));
    }
}
