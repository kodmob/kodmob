/**
 * Bloklar (vite/blocks.js, src/blocks/template.js) — kodmob-api ile ortak örnekler. Çalıştırma: npm test.
 *
 * Şablon örnekleri kodmob-api/tests/fixtures/blocks/template.json'da; PHP tarafı (Site\Blocks\Template) aynı
 * dosyayla sınanır (BlocksTest). Bloğun sayfadaki hali de iki tarafta aynı olmalı (test sitesinin kitaplığı).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse, render } from '../src/blocks/template.js';
import { blockProblems, instanceOf, readBlocks, renderInstance, resolveRefs, spriteIcons, linkHref } from '../vite/blocks.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_TESTS = path.resolve(HERE, '../../kodmob-api/tests');
const SRC = path.resolve(HERE, '../src');

const dictionary = (code) => JSON.parse(fs.readFileSync(path.join(SRC, 'i18n', `${code}.json`), 'utf-8'));
const translator = (dict) => (key) => {
    const value = key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), dict);
    if (value === undefined) throw new Error(`tanımsız anahtar: ${key}`);
    return value;
};

test('şablon: kodmob-api ile aynı örnekler', () => {
    const { cases } = JSON.parse(fs.readFileSync(path.join(API_TESTS, 'fixtures/blocks/template.json'), 'utf-8'));
    for (const c of cases) assert.equal(render(c.template, c.data), c.html, c.name);
    assert.throws(() => parse('{{#a}}açık'), /kapanmadı/);
    assert.throws(() => parse('{{#a}}{{/b}}'), /beklenmedik/);
});

test('blok: sunucudaki çizimle aynı sarmalayıcı ve bağlantı', () => {
    const library = JSON.parse(fs.readFileSync(path.join(API_TESTS, 'fixtures/site/km-blocks.json'), 'utf-8'));
    const block = { def: { fields: library.blocks.note.fields }, template: library.blocks.note.template };
    const instance = { id: 'b1', type: 'note', data: { url: 'route:blog', tone: 'plain' }, i18n: { en: { text: 'Hi' } } };
    const url = (name) => ({ blog: '/en/blog/' })[name] || null;
    // BlocksTest::test_single_block_render_for_the_editor ile aynı çıktı (önizleme işaretleriyle).
    assert.equal(renderInstance(block, instance, 'en', url, { preview: true }),
        '<div class="km-block" data-km-block="note" data-km-id="b1"><p class="note is-plain"><a href="/en/blog/" data-km-field="text">Hi</a><svg><use href="#i-check"/></svg></p></div>');
    // Ziyaretçiye işaret gitmez.
    assert.doesNotMatch(renderInstance(block, instance, 'en', url), /data-km-field/);
    assert.equal(linkHref('route:yok', url), '');
    assert.equal(linkHref('https://ornek.com', url), 'https://ornek.com');
});

test('çatının blokları geçerli, varsayılan metinleri her dilde var', () => {
    const blocks = readBlocks(path.join(SRC, 'blocks'), 'framework');
    assert.ok(Object.keys(blocks).length >= 8);
    for (const name of ['marquee', 'letters']) assert.equal(blocks[name].def.category, 'animation', `${name} Animasyon kategorisinde`);
    for (const name of ['marquee', 'letters']) assert.equal(blocks[name].def.library, false, `${name} Keşfet'ten eklenir`);
    const icons = new Set(spriteIcons(path.join(SRC, 'shell/icons.html')));
    const translators = Object.fromEntries(['tr', 'en', 'de'].map((code) => [code, translator(dictionary(code))]));
    for (const [name, block] of Object.entries(blocks)) {
        assert.deepEqual(blockProblems(block.def), [], name);
        assert.ok(icons.has(block.def.icon), `${name}: ikon sprite'ta yok (${block.def.icon})`);
        const instance = instanceOf(block, { type: name }, { id: 'x', codes: ['tr', 'en', 'de'], defaultLocale: 'tr', translators });
        for (const code of ['tr', 'en', 'de']) {
            const html = renderInstance(block, instance, code, () => '/');
            assert.match(html, new RegExp(`^<div class="km-block" data-km-block="${name}"`));
            assert.doesNotMatch(html, /⟦|undefined|\[object Object\]/, `${name} (${code})`);
        }
        // Satır ve ikon alanlarındaki ikonlar sprite'ta.
        for (const field of block.def.fields.filter((f) => f.type === 'rows')) {
            for (const row of instance.i18n.tr[field.name] || instance.data[field.name] || []) {
                for (const column of field.columns.filter((c) => c.type === 'icon')) assert.ok(icons.has(row[column.name]), `${name}.${field.name}: ${row[column.name]}`);
            }
        }
    }
});

test('ilk blok: dil metinlerinin sözlük anahtarı (sayfa metinleri taşınsın), düz metin listesi satıra', () => {
    const block = { def: { fields: [
        { name: 'title', type: 'text', localized: true, default: '@home.title' },
        { name: 'checks', type: 'rows', localized: true, columns: [{ name: 'icon', type: 'icon', default: 'check' }, { name: 'text', type: 'text' }], default: '@home.checks' },
        { name: 'steps', type: 'rows', localized: true, columns: [{ name: 'title', type: 'text' }, { name: 'url', type: 'link' }] },
        { name: 'url', type: 'link', default: 'route:contact' },
        { name: 'plain', type: 'text', localized: true, default: '@@etiket' },
    ] } };
    const dict = { 'home.title': 'Başlık', 'home.checks': ['Bir', 'İki'], 'home.steps': [{ title: 'Adım', url: '/a/' }] };
    const t = (key) => dict[key];
    const instance = instanceOf(block, { type: 'x', data: { steps: '@home.steps' } }, { id: 'd1', codes: ['tr'], defaultLocale: 'tr', translators: { tr: t } });
    assert.deepEqual(instance.i18n.tr.checks, [{ icon: 'check', text: 'Bir' }, { icon: 'check', text: 'İki' }]);
    assert.deepEqual(instance.texts, { title: 'home.title', 'checks.0.text': 'home.checks.0', 'checks.1.text': 'home.checks.1', 'steps.0.title': 'home.steps.0.title' });
    // Sözlükten gelmeyen blokta anahtar yok.
    assert.equal(instanceOf({ def: { fields: [{ name: 'a', type: 'text', localized: true, default: 'düz' }] } }, { type: 'x' }, { id: 'd2', codes: ['tr'], defaultLocale: 'tr', translators: { tr: t } }).texts, undefined);
});

test('başvurular: @anahtar sözlükten, @@ düz', () => {
    const t = (key) => ({ 'a.b': 'Merhaba', list: [{ text: '@a.b' }] })[key];
    assert.equal(resolveRefs('@a.b', t), 'Merhaba');
    assert.equal(resolveRefs('@@etiket', t), '@etiket');
    assert.deepEqual(resolveRefs('@list', t), [{ text: 'Merhaba' }]);
    assert.deepEqual(blockProblems({ label: 'X', category: 'yok', fields: [{ name: 'a', type: 'rows' }] }), ['category bilinmiyor: yok (section, content, animation, commerce, entry)', 'a: rows için columns gerekir']);
});
