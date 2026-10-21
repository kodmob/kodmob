/**
 * İşlem kataloğu (src/panel/operations.js): Diğer İşlemler kartları ve Hızlı İşlemler anahtarları.
 * Çalıştırma: npm test (node --test).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { CARDS, DEFAULT_QUICK, ITEMS, QUICK_MAX, RESOLVED, cardsFor, itemFor } from '../src/panel/operations.js';
import { PAGES, TYPES } from '../src/panel/meta.js';
import { match } from '../src/panel/routes.js';

console.error = () => {};

test('anahtarlar benzersiz ve sunucunun kabul ettiği biçimde (kodmob-api PreferencesController)', () => {
    const keys = RESOLVED.flatMap((c) => c.links.map((l) => l.key));
    assert.equal(new Set(keys).size, keys.length, 'aynı anahtar iki kez');
    for (const key of keys) assert.match(key, /^[a-z0-9][a-z0-9:_-]{0,59}$/, key);
    assert.equal(new Set(CARDS.map((c) => c.key)).size, CARDS.length);
});

test('her bağlantı panelde bir sayfaya çıkar (sekme dahil)', () => {
    for (const item of ITEMS.values()) {
        const [pathname, search = ''] = item.href.split('?');
        const route = match(pathname, search);
        assert.notEqual(route.view, 'notFound', `${item.key}: ${item.href}`);
        assert.ok(item.label && item.icon, `${item.key}: ad ve ikon`);
    }
});

test('menüdeki her sayfa bir kartta; içerik türlerinin listesi ve "Yeni …" kendiliğinden', () => {
    const pages = new Set([...ITEMS.values()].map((i) => i.page));
    for (const p of PAGES.filter((x) => x.key !== 'other')) assert.ok(pages.has(p.key), `kartta yok: ${p.key}`);
    for (const type of Object.keys(TYPES)) {
        assert.ok(ITEMS.has(type), type);
        assert.match(ITEMS.get(`${type}-new`).href, /\/yeni$/, `${type}-new`);
    }
});

test('varsayılan Hızlı İşlemler katalogda; yetkiye göre süzme', () => {
    assert.ok(DEFAULT_QUICK.length <= QUICK_MAX);
    for (const key of DEFAULT_QUICK) assert.ok(ITEMS.has(key), key);

    // Hiç yetkisi olmayan: yalnızca herkese açık bağlantılar (kendi işlem geçmişi, Profilim, Kullanım Kılavuzu) kalır.
    assert.deepEqual(cardsFor(() => false).map((c) => [c.key, c.links.map((l) => l.key)]), [['admin', ['history']], ['account', ['profile', 'guide']]]);
    // "a|b" yetkilerinden biri yeter.
    const settings = cardsFor((cap) => cap === 'km_manage_backups').find((c) => c.key === 'settings');
    assert.deepEqual(settings.links.map((l) => l.key), ['system-settings', 'settings-backup']);
    // Ayarlar ikiye ayrıldı: kaydedilmiş eski "settings" Hızlı İşlemi Proje Ayarları'na çıkar.
    assert.equal(itemFor('settings').key, 'project-settings');
});
