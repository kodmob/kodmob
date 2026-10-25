/**
 * Yasal belgeler (gizlilik, KVKK, çerez, kullanım koşulları; modüllerinkiler: iletişim, bülten, mesafeli satış, ön
 * bilgilendirme, teslimat, iade, müşteri aydınlatma, rezervasyon koşulları). Bloklar gibi tanımlanır:
 *
 *   kodmob-frontend/src/legal/<ad>/doc.json     { group, order, module, updated, paths: { tr: 'gizlilik-politikasi', … } }
 *   kodmob-frontend/src/legal/<ad>/<dil>.html   şablon (blok şablon dili: {{controller.name}}, {{#modules.stripe}}…{{/modules.stripe}})
 *
 * Profil aynı adlı klasörle çatınınkini değiştirir (doc.json alanları üstüne yazar, şablon dil başına), yeni belge de
 * ekleyebilir. Her belge bir rotadır (adı `<ad>`, adresi `/yasal/<slug>/`): rotanın `module`'ü kapalıyken sayfa 404,
 * site haritası dışı; adres değişirse adres geçmişi 301 verir. Başlık ve açıklama sözlükte (`legal.<ad>.title`).
 * Derleme km-legal.json yazar; belgenin gövdesini sunucu doldurur (kodmob-core Site\Legal: açık modüller, Proje
 * Ayarları › Yasal, panelde özelleştirildiyse o metin).
 */
import fs from 'node:fs';
import path from 'node:path';
import { parse } from '../src/blocks/template.js';

/** Belgelerin gruplandığı başlıklar (sözlükte `legal.groups.<grup>`), bu sırayla. */
export const LEGAL_GROUPS = ['general', 'contact', 'newsletter', 'commerce', 'booking'];

/** Yasal sayfaların kökü (dil başına; listede olmayan dil İngilizcesini alır). */
export const LEGAL_BASE = { tr: '/yasal/', en: '/legal/', de: '/rechtliches/' };

const NAME = /^[a-z][a-zA-Z0-9]{1,40}$/;
const SLUG = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Kaynaklardaki belgeler (sonraki öncekinin üstüne): ad → { group, order, module, updated, paths, templates }.
 * Hatalı tanım derlemeyi durdurur.
 */
export function readLegal(sources) {
    const docs = {};
    for (const { dir, source } of sources) {
        if (!dir || !fs.existsSync(dir)) continue;
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            if (!entry.isDirectory()) continue;
            const folder = path.join(dir, entry.name);
            const where = `${source}: legal/${entry.name}`;
            if (!NAME.test(entry.name)) throw new Error(`[yasal] ${where}: ad küçük harfle başlamalı, harf ve rakam (ör. shopTerms)`);
            const jsonFile = path.join(folder, 'doc.json');
            let own = {};
            if (fs.existsSync(jsonFile)) {
                try {
                    own = JSON.parse(fs.readFileSync(jsonFile, 'utf-8'));
                } catch (error) {
                    throw new Error(`[yasal] ${where}: doc.json okunamadı (${error.message})`);
                }
            }
            const before = docs[entry.name] || { templates: {} };
            const doc = { ...before, ...own, paths: { ...before.paths, ...own.paths }, templates: { ...before.templates }, source };
            for (const file of fs.readdirSync(folder)) {
                const m = file.match(/^([a-z]{2}(?:-[A-Z]{2})?)\.html$/);
                if (!m) continue;
                const html = fs.readFileSync(path.join(folder, file), 'utf-8').trim();
                try {
                    parse(html);
                } catch (error) {
                    throw new Error(`[yasal] ${where}/${file}: ${error.message}`);
                }
                if (/\sstyle\s*=|<script\b/i.test(html)) throw new Error(`[yasal] ${where}/${file}: satır içi stil ya da betik olamaz (CSP)`);
                doc.templates[m[1]] = html;
            }
            const problems = legalProblems(doc);
            if (problems.length) throw new Error(`[yasal] ${where}:\n  ${problems.join('\n  ')}`);
            docs[entry.name] = doc;
        }
    }
    return docs;
}

/** Belge tanımı denetimi. */
export function legalProblems(doc) {
    const problems = [];
    if (!LEGAL_GROUPS.includes(doc.group)) problems.push(`group bilinmiyor: ${doc.group} (${LEGAL_GROUPS.join(', ')})`);
    if (!Number.isFinite(doc.order)) problems.push('order bir sayı olmalı');
    if (!DATE.test(doc.updated || '')) problems.push('updated YYYY-AA-GG olmalı');
    if (doc.module != null && !/^[a-z][a-z0-9-]*$/.test(doc.module)) problems.push(`module geçersiz: ${doc.module}`);
    const paths = Object.entries(doc.paths || {});
    if (!paths.length) problems.push('paths (dil → adres parçası) yok');
    for (const [code, slug] of paths) if (!SLUG.test(slug)) problems.push(`paths.${code} küçük harf, rakam ve tire olmalı: ${slug}`);
    if (!Object.keys(doc.templates).length) problems.push('şablon yok (<dil>.html)');
    return problems;
}

const ordered = (docs) => Object.entries(docs).sort(([, a], [, b]) => LEGAL_GROUPS.indexOf(a.group) - LEGAL_GROUPS.indexOf(b.group) || a.order - b.order);
const hubPath = (code) => LEGAL_BASE[code] || LEGAL_BASE.en;
const page = { template: 'pages/legal/index.njk', script: '/pages/legal/main.js' };

/**
 * Rotalar: özet sayfası (`legal`, `/yasal/`) ve her belge (`privacy` → `/yasal/gizlilik-politikasi/`). Profilin
 * rotalarında aynı adla yazılmış olan alınmaz (profil kendi sayfasını kullanır).
 */
export function legalRoutes(codes, docs, existing = []) {
    const names = new Set(existing.map((r) => r.name));
    const hub = { name: 'legal', paths: Object.fromEntries(codes.map((code) => [code, hubPath(code)])), ...page, titleKey: 'legal.title', descriptionKey: 'legal.description', data: () => ({ doc: '' }) };
    const routes = ordered(docs).map(([name, doc]) => ({
        name,
        paths: Object.fromEntries(codes.map((code) => [code, `${hubPath(code)}${doc.paths[code] || doc.paths.en || Object.values(doc.paths)[0]}/`])),
        ...page,
        legal: name,
        ...(doc.module ? { module: doc.module } : {}),
        titleKey: `legal.${name}.title`,
        descriptionKey: `legal.${name}.description`,
        data: () => ({ doc: name }),
    }));
    return [hub, ...routes].filter((r) => !names.has(r.name));
}

/** km-legal.json: sunucunun belge listesi (başlıklar sitenin dillerinde), şablonlar ve grup adları. */
export function legalManifest(docs, codes, translator) {
    return {
        version: 1,
        groups: Object.fromEntries(LEGAL_GROUPS.map((group) => [group, Object.fromEntries(codes.map((code) => [code, translator(code)(`legal.groups.${group}`)]))])),
        docs: Object.fromEntries(ordered(docs).map(([name, doc]) => [name, {
            group: doc.group,
            order: doc.order,
            module: doc.module || null,
            updated: doc.updated,
            titles: Object.fromEntries(codes.map((code) => [code, translator(code)(`legal.${name}.title`)])),
            descriptions: Object.fromEntries(codes.map((code) => [code, translator(code)(`legal.${name}.description`)])),
            templates: Object.fromEntries(Object.entries(doc.templates).filter(([code]) => codes.includes(code) || code === 'en')),
        }])),
    };
}
