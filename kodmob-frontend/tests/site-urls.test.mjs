/**
 * Site adresleri (vite/site-urls.js) — kodmob-core ile ortak vektörler (tests/fixtures/site-urls.json)
 * test profilinin rota tablosu ve dilleriyle (tests/fixtures/profile) üretilir: kodmob-core'nin test sitesi de
 * bu tablodan. Ayrıca kodmob-core'nin test manifest'indeki adresler derlemenin ürettikleriyle aynı olmalı.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { ROUTES as SITE_ROUTES } from './fixtures/profile/routes.config.js';
import { frameworkRoutes } from '../vite/framework-routes.js';
import { readLegal } from '../vite/legal.js';
import { LOCALES, DEFAULT_LOCALE } from './fixtures/profile/locales.js';
import { pathFor, shellPath, localeProblems, updateHistory, RESERVED } from '../vite/site-urls.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const API_TESTS = path.resolve(HERE, '../../kodmob-core/tests');
const vectors = JSON.parse(fs.readFileSync(path.join(API_TESTS, 'fixtures/site-urls.json'), 'utf-8'));
const LEGAL = readLegal([{ dir: path.resolve(HERE, '../src/legal'), source: 'framework' }]);
const ROUTES = [...SITE_ROUTES, ...frameworkRoutes(Object.keys(LOCALES), SITE_ROUTES, { legal: LEGAL })];
const route = (name) => ROUTES.find((r) => r.name === name);

test('adres üretimi: ortak vektörler', () => {
    for (const v of vectors.urls) {
        const r = route(v.name);
        assert.equal(pathFor(r, v.locale, LOCALES, r.dynamic ? { slug: v.slug } : {}), v.url, `${v.name} ${v.locale}`);
    }
});

test('kodmob-core test manifest\'i gerçek adreslerle aynı', () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(API_TESTS, 'fixtures/site/km-manifest.json'), 'utf-8'));
    assert.equal(manifest.default, DEFAULT_LOCALE);
    for (const r of manifest.routes) {
        assert.equal(r.path || r.pattern, pathFor(route(r.name), r.locale, LOCALES), `${r.name} ${r.locale}`);
    }
    for (const [code, info] of Object.entries(manifest.locales)) assert.equal(info.prefix, LOCALES[code].prefix, `önek ${code}`);
});

test('varsayılan dil öneksiz; kabuk adresten bağımsız', () => {
    assert.deepEqual(localeProblems(LOCALES, DEFAULT_LOCALE), []);
    assert.equal(LOCALES[DEFAULT_LOCALE].prefix, '');
    assert.equal(shellPath(route('project'), 'tr', '__entry'), '_pages/tr/projeler/__entry/index.html');
    assert.equal(shellPath(route('home'), 'en', '__entry'), '_pages/en/index.html');
    assert.equal(shellPath(route('panel'), 'tr', '__entry'), 'panel/index.html');
});

test('önek kuralları', () => {
    const base = { tr: { prefix: '' }, en: { prefix: 'en' } };
    assert.deepEqual(localeProblems(base, 'tr'), []);
    assert.ok(localeProblems({ ...base, en: { prefix: '' } }, 'tr').some((p) => p.includes('yalnızca varsayılan')));
    assert.ok(localeProblems({ ...base, de: { prefix: 'en' } }, 'tr').some((p) => p.includes('aynı')));
    assert.ok(localeProblems({ ...base, xx: { prefix: RESERVED[1] } }, 'tr').some((p) => p.includes('sitenin kendi')));
    assert.ok(localeProblems({ tr: { prefix: 'tr' }, en: { prefix: 'en' } }, 'tr').some((p) => p.includes('öneksiz')));
});

test('adres geçmişi: kalkan önek ve değişen adres legacy olur', () => {
    const before = { routes: { 'home|tr': '/tr/', 'projects|tr': '/tr/projeler/', 'projects|en': '/en/projects/' }, prefixes: { tr: 'tr', en: 'en' }, legacy: { prefixes: {}, paths: [] } };
    const routes = { 'home|tr': '/', 'projects|tr': '/projeler/', 'projects|en': '/en/work/' };
    const { history, changed } = updateHistory(before, routes, { tr: '', en: 'en' });
    assert.ok(changed);
    assert.deepEqual(history.legacy.prefixes, { tr: ['tr'] });
    assert.deepEqual(history.legacy.paths.map((p) => p.from), ['/tr/', '/tr/projeler/', '/en/projects/']);

    // Aynı build tekrar: değişiklik yok.
    assert.equal(updateHistory(history, routes, { tr: '', en: 'en' }).changed, false);

    // Eski adres yeniden kullanılırsa legacy'den düşer (yeni sayfa önceliklidir).
    const back = updateHistory(history, { ...routes, 'projects|en': '/en/projects/' }, { tr: '', en: 'en' }).history;
    assert.ok(!back.legacy.paths.some((p) => p.from === '/en/projects/'));
});

test('her profilin geçmiş dosyası dilleriyle tutarlı', async () => {
    const profiles = path.resolve(HERE, '../src/profiles');
    for (const name of fs.readdirSync(profiles)) {
        const file = path.join(profiles, name, 'i18n/url-history.json');
        if (!fs.existsSync(file)) continue;
        const history = JSON.parse(fs.readFileSync(file, 'utf-8'));
        const { LOCALES: locales } = await import(pathToFileURL(path.join(profiles, name, 'locales.js')).href);
        for (const [code, info] of Object.entries(locales)) assert.equal(history.prefixes[code], info.prefix, `${name}: önek ${code}`);
    }
});
