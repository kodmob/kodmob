/**
 * Araç paketlerinin panel parçaları (kodmob-core/extensions/<anahtar>/panel/index.js; docs/arac-paketi.md).
 * Derleme, tarayıcı ve Node testleri aynı listeyi kullanır:
 *
 * - `virtual:kodmob-extensions` modülü: `[{ key, panel }]` (panel: paketin index.js'i; `PAGES`). Vite'ta
 *   `kmExtensions()` eklentisi, Node testlerinde tests/profile-resolve.mjs üretir.
 * - Şablonlar (`template: 'panel/_x.njk'`) Nunjucks'a `<anahtar>/panel/_x.njk` olarak verilir; paketlerin
 *   klasörü şablon arama yollarının sonundadır.
 *
 * Klasör: KM_EXTENSIONS (testler) ya da kodmob-core/extensions. Tanımı (kodmob-tool.json) olmayan ya da
 * anahtarı klasör adıyla aynı olmayan klasör alınmaz; sunucu yüklemediği paketin yetkisini vermez, sayfası
 * görünmez.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const TV = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export const EXTENSIONS_DIR = process.env.KM_EXTENSIONS ? path.resolve(process.env.KM_EXTENSIONS) : path.resolve(TV, '..', 'kodmob-core', 'extensions');

export const VIRTUAL = 'virtual:kodmob-extensions';

/** Panel parçası olan paketler: `{ key, dir, entry }` (anahtar sırasıyla). */
export function extensionPanels(dir = EXTENSIONS_DIR) {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true })
        .filter((d) => d.isDirectory())
        .map((d) => ({ key: d.name, dir: path.join(dir, d.name), entry: path.join(dir, d.name, 'panel', 'index.js') }))
        .filter((e) => {
            try {
                return JSON.parse(fs.readFileSync(path.join(e.dir, 'kodmob-tool.json'), 'utf8')).key === e.key && fs.existsSync(e.entry);
            } catch {
                return false;
            }
        })
        .sort((a, b) => a.key.localeCompare(b.key));
}

/** Blok getiren paketler (`<anahtar>/blocks/<ad>/`; vite/blocks.js): `{ dir, source }`. */
export function extensionBlockDirs(dir = EXTENSIONS_DIR) {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true })
        .filter((d) => d.isDirectory() && fs.existsSync(path.join(dir, d.name, 'blocks')))
        .filter((d) => {
            try {
                return JSON.parse(fs.readFileSync(path.join(dir, d.name, 'kodmob-tool.json'), 'utf8')).key === d.name;
            } catch {
                return false;
            }
        })
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((d) => ({ dir: path.join(dir, d.name, 'blocks'), source: d.name }));
}

/** Sanal modülün kaynağı; `specifier`: paketin index.js'inin içe aktarma adı (Vite: dosya yolu, Node: file: adresi). */
export function extensionsSource(list, specifier = (file) => file.split(path.sep).join('/')) {
    const imports = list.map((e, i) => `import * as p${i} from ${JSON.stringify(specifier(e.entry))};`);
    const items = list.map((e, i) => `{ key: ${JSON.stringify(e.key)}, panel: p${i} }`);
    return `${imports.join('\n')}\nexport default [${items.join(', ')}];\n`;
}

/** Derleme (vite.config.js): paketlerin `PAGES`'i Node'da okunur (adres tablosu, şablonlar). */
export async function loadExtensionPanels(list = extensionPanels()) {
    return Promise.all(list.map(async (e) => ({ key: e.key, panel: await import(pathToFileURL(e.entry).href) })));
}

/** Vite eklentisi: `virtual:kodmob-extensions`. */
export function kmExtensions(list = extensionPanels()) {
    const id = `\0${VIRTUAL}`;
    return {
        name: 'kodmob-extensions',
        resolveId: (source) => (source === VIRTUAL ? id : null),
        load: (source) => (source === id ? extensionsSource(list) : null),
    };
}
