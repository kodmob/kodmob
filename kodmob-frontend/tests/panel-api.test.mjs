/**
 * Panelin API istemcisi (src/panel/api.js): nesne kalıbında aynı ad iki kez yazılırsa sonraki öncekini sessizce
 * ezer (Mağaza'nın `order(id)`'si içerik sıralamasının `order(type, ids)`'ini ezmişti). Çalıştırma: npm test.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('API istemcisinde her yöntem adı bir kez', () => {
    const source = readFileSync(new URL('../src/panel/api.js', import.meta.url), 'utf8');
    const names = [...source.matchAll(/^ {4}([A-Za-z]\w*): \(/gm)].map((m) => m[1]);
    const seen = new Set();
    const duplicates = names.filter((name) => (seen.has(name) ? true : (seen.add(name), false)));
    assert.ok(names.length > 50, 'yöntemler okunamadı');
    assert.deepEqual(duplicates, []);
});
