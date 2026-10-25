/**
 * Yasal belgeler (vite/legal.js, src/legal/<ad>/): her belgenin üç dilde şablonu, sözlükte başlığı ve adresi var;
 * şablonlardaki bağlantılar var olan belgelere, veri yolları sunucunun verdiği anahtarlara (kodmob-core
 * Site\Legal\LegalData ve modüllerin legal() kancası) gider; rotalar benzersiz.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { readLegal, legalRoutes, legalProblems, LEGAL_GROUPS } from '../vite/legal.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.resolve(HERE, '../src');
const LOCALES = ['tr', 'en', 'de'];
const docs = readLegal([{ dir: path.join(SRC, 'legal'), source: 'framework' }]);
const dict = Object.fromEntries(LOCALES.map((code) => [code, JSON.parse(fs.readFileSync(path.join(SRC, 'i18n', `${code}.json`), 'utf-8'))]));

/** Sunucunun şablona verdiği üst anahtarlar (LegalData + modüllerin legal()). */
const DATA = ['site', 'controller', 'controllerCard', 'modules', 'links', 'retention', 'commerce'];

test('her belge üç dilde: şablon, başlık, açıklama, adres', () => {
    assert.ok(Object.keys(docs).length >= 12);
    for (const [name, doc] of Object.entries(docs)) {
        assert.deepEqual(legalProblems(doc), [], name);
        for (const code of LOCALES) {
            assert.ok(doc.templates[code], `${name}: ${code}.html yok`);
            assert.ok(doc.paths[code], `${name}: paths.${code} yok`);
            assert.equal(typeof dict[code].legal[name]?.title, 'string', `${name}: legal.${name}.title (${code})`);
            assert.equal(typeof dict[code].legal[name]?.description, 'string', `${name}: description (${code})`);
        }
    }
    for (const code of LOCALES) for (const group of LEGAL_GROUPS) assert.ok(dict[code].legal.groups[group], `legal.groups.${group} (${code})`);
});

test('şablonlar: bağlantılar var olan belgelere, veri bilinen anahtarlardan', () => {
    for (const [name, doc] of Object.entries(docs)) {
        for (const [code, html] of Object.entries(doc.templates)) {
            const tags = [...html.replace(/\{\{!.*?\}\}/gs, '').matchAll(/\{\{\{?[#^/]?\s*([a-zA-Z.@]+)\s*\}?\}\}/g)].map((m) => m[1]);
            for (const tag of tags) {
                const [head, key] = tag.split('.');
                assert.ok(DATA.includes(head), `${name}/${code}: bilinmeyen veri ${tag}`);
                if (head === 'links') assert.ok(docs[key], `${name}/${code}: links.${key} belge değil`);
            }
            assert.match(html, /\{\{\{controllerCard\}\}\}|controller\.email/, `${name}/${code}: veri sorumlusu ya da iletişim yok`);
        }
    }
});

test('rotalar: özet sayfası ve belge başına bir rota, adresler benzersiz, modül rotada', () => {
    const routes = legalRoutes(LOCALES, docs);
    assert.equal(routes[0].name, 'legal');
    assert.deepEqual(routes[0].paths, { tr: '/yasal/', en: '/legal/', de: '/rechtliches/' });
    const paths = routes.flatMap((r) => Object.values(r.paths));
    assert.equal(new Set(paths).size, paths.length);
    const shop = routes.find((r) => r.name === 'shopTerms');
    assert.deepEqual([shop.module, shop.legal, shop.paths.tr], ['commerce', 'shopTerms', '/yasal/mesafeli-satis-sozlesmesi/']);
    // Profil aynı adla kendi sayfasını yazdıysa çatınınki alınmaz.
    assert.equal(legalRoutes(LOCALES, docs, [{ name: 'privacy' }]).some((r) => r.name === 'privacy'), false);
});
