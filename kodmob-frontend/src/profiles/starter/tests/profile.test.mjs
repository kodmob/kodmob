/**
 * Profilin kendi testleri (npm test çalıştırır; çatının testleri kodmob-frontend/tests'te). Yeni projede bu klasöre sitenize özgü
 * denetimler eklenir: rotalar, sözlükler, profilin blokları.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROUTES } from '../routes.config.js';
import { LOCALES, DEFAULT_LOCALE } from '../locales.js';

const PROFILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const keys = (node, prefix = '') => Object.entries(node).flatMap(([key, value]) =>
    value && typeof value === 'object' && !Array.isArray(value) ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]);

test('her rota varsayılan dilde var, adlar tekil', () => {
    const names = ROUTES.map((r) => r.name);
    assert.equal(new Set(names).size, names.length);
    for (const route of ROUTES) assert.ok(route.paths[DEFAULT_LOCALE], `${route.name}: ${DEFAULT_LOCALE} yolu yok`);
});

test('sözlükler her dilde aynı anahtarları taşır', () => {
    const dict = (code) => JSON.parse(fs.readFileSync(path.join(PROFILE, 'i18n', `${code}.json`), 'utf-8'));
    const base = keys(dict(DEFAULT_LOCALE)).sort();
    for (const code of Object.keys(LOCALES)) assert.deepEqual(keys(dict(code)).sort(), base, `${code} sözlüğü`);
});
