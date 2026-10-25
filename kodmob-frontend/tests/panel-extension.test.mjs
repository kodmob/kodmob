/**
 * Panel uzantı noktası (profilin panel.js'i; src/panel/extension.js, project.js): projenin içerik türleri
 * ve özel sayfaları çatının dosyalarına dokunmadan adres tablosuna, menüye ve tura girer.
 * Çalıştırma: npm test (node --test; etkin profil kodmob).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { FRAMEWORK_TYPES, panelTypes, projectPages, toolPages } from '../src/panel/extension.js';
import { extensionPanels, extensionsSource, loadExtensionPanels } from '../vite/extensions.js';
import { href, match, panelManifest, usePages, ROUTES } from '../src/panel/routes.js';
import { TYPES } from '../src/panel/types.js';
import { tourSteps } from '../src/panel/guide.js';
import { buildSections } from '../src/panel/meta.js';

test('türler: projeninkiler önce, blog çatıdan; tur metni türün tanımından', () => {
    assert.deepEqual(Object.keys(panelTypes({ courses: { slug: 'kurslar' } })), ['courses', 'posts', 'pages', 'products', 'tours', 'hotels']);
    assert.deepEqual(Object.keys(TYPES), ['projects', 'services', 'references', 'posts', 'pages', 'products', 'tours', 'hotels']);
    assert.equal(TYPES.posts, FRAMEWORK_TYPES.posts);
    // Blok sayfaları menüde değil (Sayfa düzenleyiciden açılır); Tüm İşlemler'de.
    const content = buildSections(() => true).find((s) => s.key === 'content').pages.map((p) => p.key);
    assert.ok(content.includes('posts') && content.includes('projects') && !content.includes('pages'));
    const all = () => true;
    const step = tourSteps(buildSections(all), all).find((s) => s.id === 'content');
    assert.match(step.text, /Portföy/, 'projenin türü İçerik sekmelerinde');
});

test('özel sayfalar: anahtar ve şablon denetlenir, boşluklar dolar', () => {
    assert.throws(() => projectPages([{ key: 'Bad-Key', path: 'x', template: 'x.njk' }]));
    assert.throws(() => projectPages([{ key: 'reports', path: 'raporlar' }]), /template/);
    const [page] = projectPages([{ key: 'reports', path: 'raporlar/:tab?', label: 'Raporlar', template: 'panel/_reports.njk' }]);
    assert.equal(page.group, 'project');
    assert.equal(page.can, null);
});

test('özel sayfa adres tablosuna içerik türlerinden önce girer; çatının sayfasıyla çakışamaz', () => {
    usePages(projectPages([{ key: 'reports', path: 'raporlar/:tab?', label: 'Raporlar', template: 'panel/_reports.njk' }]));
    usePages(projectPages([{ key: 'reports', path: 'raporlar/:tab?', label: 'Raporlar', template: 'panel/_reports.njk' }]));
    assert.equal(ROUTES.filter((r) => r.name === 'reports').length, 1, 'iki kez eklenmez');
    assert.ok(ROUTES.findIndex((r) => r.name === 'reports') < ROUTES.findIndex((r) => r.name === 'editor'));
    assert.equal(href('reports'), '/panel/raporlar');
    assert.equal(match('/panel/raporlar').view, 'reports');
    assert.equal(panelManifest().routes.reports, 'raporlar/:tab?');
    assert.throws(() => usePages(projectPages([{ key: 'settings', path: 'x', template: 'x.njk' }])), /çatının sayfası/);
});

test('araç paketi: sayfaları paketin klasöründen; anahtar aracınkiyle başlar, yetki zorunlu', async () => {
    const dir = fileURLToPath(new URL('../../kodmob-core/tests/fixtures/extensions', import.meta.url));
    const list = extensionPanels(dir);
    assert.deepEqual(list.map((e) => e.key), ['merhaba']);
    const [page] = toolPages(await loadExtensionPanels(list));
    assert.equal(page.key, 'merhaba');
    assert.equal(page.tool, 'merhaba');
    assert.equal(page.group, 'project');
    assert.equal(page.can, 'km_use_merhaba');
    assert.equal(page.template, 'merhaba/panel/_merhaba.njk');
    assert.equal(typeof page.load, 'function');
    assert.match(extensionsSource(list), /import \* as p0 from ".*merhaba\/panel\/index\.js";\nexport default \[\{ key: "merhaba", panel: p0 \}\];/);

    const panel = (pages) => [{ key: 'stok', panel: { PAGES: pages } }];
    assert.throws(() => toolPages(panel([{ key: 'rapor', path: 'r', template: 't.njk', can: 'km_x' }])), /"stok" ile başlamalı/);
    assert.throws(() => toolPages(panel([{ key: 'stok-rapor', path: 'r', template: 't.njk' }])), /can/);
    assert.equal(toolPages(panel([{ key: 'stok-rapor', path: 'r', template: 't.njk', can: 'km_x' }]))[0].key, 'stok-rapor');
    assert.deepEqual(extensionPanels(path.join(dir, 'yok')), []);
});
