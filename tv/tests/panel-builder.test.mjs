/**
 * Sayfa düzenleyicinin verisi (src/panel/builder/model.js): çalışma kopyası, değer sırası (sunucudaki
 * BlockRenderer ile aynı), yerinde düzenleme yolu, taşıma, Jarvis metinleri, geri al. Çalıştırma: npm test.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as M from '../src/panel/builder/model.js';

const DEF = {
    label: 'Not',
    defaults: { data: { url: 'route:blog', tone: 'plain' }, i18n: { tr: { text: 'Varsayılan', items: [] }, en: { text: 'Default', items: [] } } },
    fields: [
        { name: 'text', type: 'text', localized: true },
        { name: 'url', type: 'link' },
        { name: 'tone', type: 'select', options: { plain: 'Sade', loud: 'Belirgin' } },
        { name: 'items', type: 'rows', localized: true, max: 3, columns: [{ name: 'title', type: 'text' }, { name: 'icon', type: 'icon', default: 'check' }, { name: 'url', type: 'link' }] },
    ],
};
const field = (name) => DEF.fields.find((f) => f.name === name);

test('çalışma kopyası: taslak, yoksa yayındaki, yoksa varsayılan; boş nesne düzeltilir', () => {
    assert.deepEqual(M.workingCopy({ draft: null, published: null, defaults: [{ id: 'd1', type: 'note', data: [], i18n: [] }] }), [{ id: 'd1', type: 'note', hidden: false, data: {}, i18n: {} }]);
    assert.equal(M.workingCopy({ draft: [{ id: 'a', type: 'note' }], published: [{ id: 'b', type: 'note' }] })[0].id, 'a');
    assert.equal(M.workingCopy({ draft: null, published: [{ id: 'b', type: 'note' }], defaults: [] })[0].id, 'b');
    // Boş liste "düzenlendi, boşaltıldı" demektir; varsayılana dönmez.
    assert.deepEqual(M.workingCopy({ draft: null, published: [], defaults: [{ id: 'd1', type: 'note' }] }), []);
});

test('değer sırası: o dil → varsayılan dil → bloğun varsayılanı', () => {
    const block = M.normalizeBlock({ id: 'x', type: 'note', data: { tone: 'loud' }, i18n: { tr: { text: 'Merhaba' } } });
    assert.equal(M.valueOf(block, DEF, field('text'), 'tr', 'tr'), 'Merhaba');
    assert.equal(M.valueOf(block, DEF, field('text'), 'en', 'tr'), 'Merhaba');
    assert.equal(M.valueOf(M.normalizeBlock({ id: 'y', type: 'note' }), DEF, field('text'), 'en', 'tr'), 'Default');
    assert.equal(M.valueOf(block, DEF, field('url'), 'en', 'tr'), 'route:blog');
    assert.equal(M.valueOf(block, DEF, field('tone'), 'en', 'tr'), 'loud');
    const next = M.setValue(block, field('text'), 'en', 'Hello');
    assert.equal(next.i18n.en.text, 'Hello');
    assert.equal(block.i18n.en, undefined, 'eski nesne değişmez');
});

test('yerinde düzenleme yolu: alan ve satır sütunu', () => {
    assert.equal(M.resolvePath(DEF, 'text').field.name, 'text');
    assert.deepEqual(Object.values(M.resolvePath(DEF, 'items.1.title')).map((v) => v?.name ?? v), ['items', 1, 'title']);
    assert.equal(M.resolvePath(DEF, 'items.x.title'), null);
    assert.equal(M.resolvePath(DEF, 'yok'), null);
    const block = M.normalizeBlock({ id: 'x', type: 'note', i18n: { tr: { items: [{ title: 'A', icon: 'star', url: '' }, { title: 'B', icon: 'zap', url: '' }] } } });
    const next = M.setPath(block, DEF, 'items.1.title', 'tr', 'tr', 'İki');
    assert.deepEqual(next.i18n.tr.items.map((r) => r.title), ['A', 'İki']);
    assert.equal(next.i18n.tr.items[1].icon, 'zap');
});

test('taşıma: aynı bölgede ve bölgeler arası', () => {
    const b = (id) => ({ id, type: 'note' });
    const layout = { a: [b('1'), b('2'), b('3')], c: [b('9')] };
    assert.deepEqual(M.moveBlock(layout, { zone: 'a', id: '1' }, { zone: 'a', index: 3 }).a.map((x) => x.id), ['2', '3', '1']);
    assert.deepEqual(M.moveBlock(layout, { zone: 'a', id: '3' }, { zone: 'a', index: 0 }).a.map((x) => x.id), ['3', '1', '2']);
    const moved = M.moveBlock(layout, { zone: 'a', id: '2' }, { zone: 'c', index: 0 });
    assert.deepEqual([moved.a.map((x) => x.id), moved.c.map((x) => x.id)], [['1', '3'], ['2', '9']]);
    assert.deepEqual(layout.a.map((x) => x.id), ['1', '2', '3'], 'eski düzen değişmez');
});

test('ekleme yeri: seçili bloğun ardı, yoksa sayfanın kendi bölgesi', () => {
    const page = { name: 'home', zones: ['site.top', 'home.top', 'home.main', 'home.bottom', 'site.bottom'] };
    const layout = { 'home.main': [{ id: 'a' }, { id: 'b' }], 'home.top': [] };
    assert.deepEqual(M.insertTarget(page, layout, null), { zone: 'home.main', index: 2 });
    assert.deepEqual(M.insertTarget(page, layout, { zone: 'home.main', id: 'a' }), { zone: 'home.main', index: 1 });
    assert.deepEqual(M.insertTarget({ name: 'contact', zones: ['site.top', 'contact.top', 'contact.bottom'] }, {}, null), { zone: 'contact.bottom', index: 0 });
    assert.equal(M.zoneLabel('site.top', {}, page), 'Bütün sayfalar · üstte');
    assert.equal(M.zoneLabel('home.bottom', {}, page), 'Sayfanın altı');
    assert.equal(M.zoneLabel('home.main', { label: 'Ana sayfa bölümleri' }, page), 'Ana sayfa bölümleri');
    // Blok sayfası: kaydın bölgesi (page.main@12) sayfanın kendi bölgesi.
    const blockPage = { name: 'page@12', zones: ['page.main@12', 'site.bottom'] };
    assert.deepEqual(M.insertTarget(blockPage, { 'page.main@12': [{ id: 'h' }] }, null), { zone: 'page.main@12', index: 1 });
    assert.equal(M.zoneLabel('page.main@12', { label: 'Sayfa içeriği' }, blockPage), 'Sayfa içeriği');
});

test('yeni sayfanın adres önerisi: Türkçe harfler, boşluk ve işaretler', () => {
    assert.equal(M.slugify('Yaz Kampanyası & İndirim!'), 'yaz-kampanyasi-indirim');
    assert.equal(M.slugify('  Çağrı Ölçüm Şubesi  '), 'cagri-olcum-subesi');
    assert.equal(M.slugify('Café — Über'), 'cafe-uber');
    assert.equal(M.slugify(''), '');
});

test('Jarvis metinleri: yalnızca dil metinleri gider; satırlarda diğer sütunlar korunur', () => {
    const block = M.normalizeBlock({ id: 'x', type: 'note', data: { url: '/a/' }, i18n: { tr: { text: 'Bir', items: [{ title: 'A', icon: 'star', url: '/x/' }] } } });
    assert.deepEqual(M.textsOf(block, DEF, 'tr', 'tr'), { text: 'Bir', items: [{ title: 'A' }] });
    const next = M.applyTexts(block, DEF, 'en', 'tr', { text: 'One', items: [{ title: 'Alpha' }, { title: 'Beta' }, { title: 'C' }, { title: 'D' }] });
    assert.equal(next.i18n.en.text, 'One');
    // Satır sayısı alanın sınırında (3); var olan satırın ikonu ve bağlantısı kalır, yenisi varsayılandan.
    assert.deepEqual(next.i18n.en.items, [{ title: 'Alpha', icon: 'star', url: '/x/' }, { title: 'Beta', icon: 'check', url: '' }, { title: 'C', icon: 'check', url: '' }]);
    assert.equal(next.i18n.tr.text, 'Bir');
});

test('geri al / yinele', () => {
    const h = M.createHistory(2);
    h.push({ v: 1 });
    h.push({ v: 2 });
    h.push({ v: 3 });
    assert.deepEqual(h.undo({ v: 4 }), { v: 3 });
    assert.deepEqual(h.undo({ v: 3 }), { v: 2 });
    assert.equal(h.undo({ v: 2 }), null, 'sınır: en çok 2 adım');
    assert.deepEqual(h.redo({ v: 2 }), { v: 3 });
    assert.ok(h.canUndo);
    const copy = M.copyBlock({ id: 'a', type: 'note', data: { x: 1 }, i18n: {} });
    assert.notEqual(copy.id, 'a');
    assert.deepEqual(copy.data, { x: 1 });
});
