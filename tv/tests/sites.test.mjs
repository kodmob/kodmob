/**
 * Siteler sayfasının yardımcıları (src/domain/sites.js): anahtar önerisi ve alan adı listesi,
 * kodmob-api Core\Sites\SiteContext kurallarıyla aynı sonuç.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isLocalHost, localDomain, parseDomains, siteKey, siteOrigin } from '../src/domain/sites.js';

test('addan anahtar önerisi', () => {
    assert.equal(siteKey('Şişli Çiçekçi İstanbul ÖZEL'), 'sisli-cicekci-istanbul-ozel');
    assert.equal(siteKey('  Demo Site!! '), 'demo-site');
    assert.equal(siteKey('Café 2026'), 'cafe-2026');
    assert.equal(siteKey('a'.repeat(31) + ' b'), 'a'.repeat(31));
    assert.equal(siteKey(''), '');
});

test('alan adı listesi', () => {
    assert.deepEqual(parseDomains('https://Demo.com/x, www.b.com\n c.com c.com'), ['demo.com', 'b.com', 'c.com']);
    assert.deepEqual(parseDomains('demo.localhost:8090  example.org.'), ['demo.localhost', 'example.org']);
    assert.deepEqual(parseDomains(''), []);
});

test('yerel deneme adresi: panel bu bilgisayardaysa anahtar.localhost, bağlantı bu sunucunun portuyla', () => {
    for (const host of ['localhost', '127.0.0.1', '[::1]', 'demo.localhost']) assert.equal(isLocalHost(host), true, host);
    for (const host of ['kodmob.com', 'localhost.com', '10.0.0.5']) assert.equal(isLocalHost(host), false, host);
    assert.equal(localDomain('deneme'), 'deneme.localhost');
    const site = { url: '', domains: ['deneme.com', 'deneme.localhost'] };
    assert.equal(siteOrigin(site, { hostname: '127.0.0.1', protocol: 'http:', port: '8090' }), 'http://deneme.localhost:8090');
    assert.equal(siteOrigin(site, { hostname: 'kodmob.com', protocol: 'https:', port: '' }), 'https://deneme.com');
    assert.equal(siteOrigin({ url: 'https://ozel.com', domains: ['deneme.com'] }, { hostname: 'kodmob.com', protocol: 'https:', port: '' }), 'https://ozel.com');
});

test('alt site sözlüğü ana sözlüğün üstüne derin birleşir', async () => {
    const { deepMerge } = await import('../vite/i18n-pages.js');
    const base = { meta: { siteName: 'Ana', description: 'Açıklama' }, nav: { brand: 'ana', list: ['a', 'b'] }, only: 1 };
    assert.deepEqual(deepMerge(base, { meta: { siteName: 'Alt' }, nav: { list: ['c'] } }), {
        meta: { siteName: 'Alt', description: 'Açıklama' }, nav: { brand: 'ana', list: ['c'] }, only: 1,
    });
});
