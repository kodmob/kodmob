/**
 * Panel rota tablosu (src/panel/routes.js) — kodmob-core ile ortak vektörler.
 * Çalıştırma: npm test (node --test).
 *
 * Vektörler kodmob-core/tests/fixtures/panel-urls.json'dadır; PHP tarafı (PanelUrl) aynı
 * dosyayla sınanır. Ayrıca kodmob-core'nin test manifest'indeki `panel` bölümü bu tabloyla
 * aynı olmalı (iki taraf farklı tabloyla çalışmasın).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { href, match, fromLegacyHash, toPanelUrl, panelManifest, ROUTES } from '../src/panel/routes.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_TESTS = path.resolve(HERE, '../../kodmob-core/tests');
const vectors = JSON.parse(fs.readFileSync(path.join(API_TESTS, 'fixtures/panel-urls.json'), 'utf-8'));

// Konsol hatalarını (bilinmeyen rota uyarıları) test çıktısına basma.
console.error = () => {};

test('href: rota + parametre → adres', () => {
    for (const v of [...vectors.urls, ...vectors.fallbacks]) {
        assert.equal(href(v.name, v.params, v.query), v.url, `${v.name} ${JSON.stringify(v.params)}`);
    }
});

test('match: adres → aynı rota ve parametreler (gidiş-dönüş)', () => {
    for (const v of vectors.urls) {
        const [pathname, search = ''] = v.url.split('?');
        const route = match(pathname, search);
        assert.equal(route.name, v.name, v.url);
        assert.equal(href(route.name, route), pathname, `gidiş-dönüş: ${v.url}`);
    }
});

test('match: kenar durumları ve kanonik adres', () => {
    for (const v of vectors.matches) {
        const route = match(v.path);
        assert.equal(route.name, v.name, v.path);
        if (v.canonical) assert.equal(route.canonical, v.canonical, v.path);
    }
});

test('eski hash adresleri', () => {
    for (const v of vectors.legacy) {
        assert.equal(fromLegacyHash(v.hash), v.url, v.hash);
        assert.equal(toPanelUrl(v.hash), v.url, `toPanelUrl ${v.hash}`);
        assert.equal(toPanelUrl(`/panel/${v.hash}`), v.url, `toPanelUrl /panel/${v.hash}`);
    }
    assert.equal(toPanelUrl('/panel/talepler/3'), '/panel/talepler/3');
    assert.equal(toPanelUrl('https://kodmob.com/'), 'https://kodmob.com/');
});

test('her rotanın en az bir vektörü var', () => {
    const covered = new Set(vectors.urls.map((v) => v.name));
    for (const r of ROUTES) assert.ok(covered.has(r.name), `vektör yok: ${r.name}`);
});

test('kodmob-core test manifest\'i aynı rota tablosunu taşıyor', () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(API_TESTS, 'fixtures/site/km-manifest.json'), 'utf-8'));
    assert.deepEqual(manifest.panel, panelManifest());
});

test('eski Ayarlar adresleri yeni sayfalara açılır (kanonik adres yenisi)', () => {
    assert.equal(match('/panel/ayarlar').view, 'projectSettings');
    assert.equal(match('/panel/ayarlar').canonical, '/panel/proje-ayarlari');
    const mail = match('/panel/ayarlar/e-posta');
    assert.deepEqual([mail.view, mail.tab, mail.canonical], ['settings', 'mail', '/panel/sistem-ayarlari/e-posta']);
});

test('replaceUrl yalnızca aynı sayfanın adresini yazar (geç kalan sayfa, kişi başka sayfadayken adresi geri almaz)', async () => {
    const { replaceUrl } = await import('../src/panel/routes.js');
    const written = [];
    const saved = { window: globalThis.window, history: globalThis.history, CustomEvent: globalThis.CustomEvent };
    globalThis.window = { location: { pathname: '/panel/sistem-ayarlari', search: '' }, dispatchEvent() {} };
    globalThis.history = { state: null, replaceState: (state, title, url) => written.push(url) };
    globalThis.CustomEvent ??= class { constructor(type, init) { this.type = type; this.detail = init?.detail; } };
    try {
        replaceUrl('/panel/seo/ayarlar');
        assert.deepEqual(written, [], 'başka sayfanın adresi yazılmaz');
        replaceUrl('/panel/sistem-ayarlari/e-posta');
        assert.deepEqual(written, ['/panel/sistem-ayarlari/e-posta'], 'aynı sayfanın sekmesi yazılır');
        window.location.pathname = '/panel/projeler/yeni';
        replaceUrl('/panel/blog/12');
        assert.equal(written.length, 1, 'başka içerik türünün düzenleyicisi de başka sayfadır');
    } finally {
        Object.assign(globalThis, saved);
    }
});
