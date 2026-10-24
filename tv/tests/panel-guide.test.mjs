/**
 * Kullanım Kılavuzu (src/panel/guide.js): tur adımları ve rehberler.
 * Çalıştırma: npm test (node --test).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { GUIDES, NAV_TEXT, SECTION_TEXT, tourSteps } from '../src/panel/guide.js';
import { PAGES, SECTIONS, buildSections } from '../src/panel/meta.js';
import { ROUTES, TAB_SLUGS, TYPE_SLUGS } from '../src/panel/routes.js';

const GUIDE_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../src/panel/guides');

test('menüde olabilecek her sayfanın tur metni var (içerik türü genel metinle)', () => {
    for (const p of PAGES.filter((x) => !x.hidden && x.view !== 'list')) assert.ok(NAV_TEXT[p.key], `tur metni yok: ${p.key}`);
    for (const key of Object.keys(NAV_TEXT)) assert.ok(PAGES.some((p) => p.key === key), `bilinmeyen sayfa: ${key}`);
});

test('tur: karşılama, üst bar, yan menünün bölümleri sırasıyla, kapanış', () => {
    const all = () => true;
    const steps = tourSteps(buildSections(all), all);
    assert.equal(steps[0].id, 'welcome');
    assert.equal(steps.at(-1).id, 'done');
    assert.equal(new Set(steps.map((s) => s.id)).size, steps.length, 'aynı adım iki kez');
    const nav = steps.filter((s) => s.area === 'nav' && s.id !== 'project');
    assert.equal(steps.find((s) => s.area === 'nav').id, 'project', 'menü adımları proje seçiciyle başlar');
    assert.deepEqual(nav.map((s) => s.id), SECTIONS.map((s) => s.key));
    for (const key of SECTIONS.map((s) => s.key)) assert.ok(SECTION_TEXT[key], `bölüm metni yok: ${key}`);
    for (const s of nav) {
        assert.equal(s.target, `nav-${s.id}`);
        assert.ok(s.text, `metin yok: ${s.id}`);
    }
});

test('tur yetkiye göre: Jarvis düğmesi ve yetkisiz sayfalar yok', () => {
    const can = (cap) => !cap || String(cap).split('|').some((c) => ['km_manage_posts', 'km_manage_media'].includes(c));
    const ids = tourSteps(buildSections(can), can).map((s) => s.id);
    assert.ok(!ids.includes('top-jarvis'), 'Jarvis yetkisi yok');
    assert.ok(ids.includes('content') && ids.includes('top-all'), 'Tüm İşlemler üst barda');
    assert.ok(!ids.includes('settings') && !ids.includes('growth'), 'yetkisi olmayan bölüm turda yok');
});

test('rehberler: dosya var, bölümler benzersiz, panel bağlantıları rota tablosunda', () => {
    const routes = new Set(ROUTES.map((r) => r.name));
    assert.equal(new Set(GUIDES.map((g) => g.key)).size, GUIDES.length);
    for (const g of GUIDES) {
        const file = path.join(GUIDE_DIR, `${g.key}.html`);
        assert.ok(fs.existsSync(file), `dosya yok: ${g.key}.html`);
        assert.match(g.load.toString(), new RegExp(`guides/${g.key}\\.html\\?raw`), `${g.key}: yükleyici başka dosyayı açıyor`);
        const html = fs.readFileSync(file, 'utf-8');
        const ids = [...html.matchAll(/<h2 id="([^"]+)"/g)].map((m) => m[1]);
        assert.ok(ids.length >= 3, `${g.key}: en az 3 bölüm`);
        assert.equal(new Set(ids).size, ids.length, `${g.key}: aynı bölüm kimliği`);
        for (const [tag] of html.matchAll(/<a [^>]*data-route="[^"]*"[^>]*>/g)) {
            const attr = (name) => tag.match(new RegExp(`data-${name}="([^"]*)"`))?.[1];
            assert.ok(routes.has(attr('route')), `${g.key}: bilinmeyen rota ${attr('route')}`);
            if (attr('tab')) assert.ok(TAB_SLUGS[attr('route')]?.[attr('tab')], `${g.key}: bilinmeyen sekme ${attr('route')}/${attr('tab')}`);
            if (attr('type')) assert.ok(TYPE_SLUGS[attr('type')], `${g.key}: bilinmeyen tür ${attr('type')}`);
            assert.ok(!/\shref=/.test(tag), `${g.key}: rotalı bağlantıya adres yazılmaz`);
        }
    }
});
