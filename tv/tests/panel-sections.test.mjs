/**
 * Yan menü (src/panel/meta.js SECTIONS, buildSections): menüde yalnızca bölümler, sayfalar bölümün sekmeleri.
 * Çalıştırma: npm test (node --test).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PAGES, SECTIONS, buildSections } from '../src/panel/meta.js';

const all = () => true;

test('menüde beş bölüm; menüye girebilen her sayfa bir bölümde', () => {
    assert.deepEqual(SECTIONS.map((s) => s.key), ['overview', 'content', 'inbox', 'growth', 'settings']);
    const keys = new Set(SECTIONS.map((s) => s.key));
    for (const p of PAGES.filter((x) => !x.hidden && !x.alias && x.key !== 'other' && x.key !== 'jarvis')) {
        assert.ok(keys.has(p.section), `bölümü yok: ${p.key}`);
    }
});

test('bölüm adresi ilk (tam ekran olmayan) sekmesi; sayfalar ve blog İçerik\'in başında, Proje Ayarlar\'ın başında', () => {
    const sections = Object.fromEntries(buildSections(all).map((s) => [s.key, s]));
    assert.deepEqual(sections.content.pages.slice(0, 2).map((p) => p.key), ['builder', 'posts']);
    assert.equal(sections.content.href, '/panel/blog', 'tam ekran Sayfa düzenleyiciyle açılmaz');
    assert.equal(sections.settings.pages[0].key, 'project-settings');
    assert.equal(sections.growth.pages.find((p) => p.key === 'languages').tab, 'Diller');
    assert.ok(!sections.settings.pages.some((p) => p.key === 'roles'), 'seyrek sayfa sekmede değil (Tüm İşlemler)');
});

test('yetkiye göre: sayfası olmayan bölüm menüde yok', () => {
    const can = (cap) => !cap || String(cap).split('|').includes('km_manage_posts');
    assert.deepEqual(buildSections(can).map((s) => s.key), ['content']);
});
