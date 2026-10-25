/**
 * Panel bağlantıları elle yazılmaz: adresler rota tablosundan gelir (src/panel/routes.js;
 * şablonda `$href(...)`, kodda `href()` / `navigate()`). Bu test kaynakta string olarak
 * yazılmış eski hash adresi (`'#/…'`) ya da panel yolu (`'/panel/…'`) bırakmaz.
 *
 * İzinli dosyalar eski adresleri çevirmek ya da tabloyu tanımlamak için bunları içerir.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../src');
const DIRS = ['panel', 'pages/panel', 'ui'];
const ALLOWED = new Set(['panel/routes.js', 'panel/app.js', 'panel/meta.js', 'panel/markdown.js']);
const RULES = [
    { name: "eski hash adresi ('#/…')", re: /['"`]#\// },
    { name: "elle yazılmış panel yolu ('/panel/…')", re: /['"`]\/panel\/[a-z0-9]/ },
];

function files(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
        const full = path.join(dir, e.name);
        return e.isDirectory() ? files(full) : /\.(js|njk|html)$/.test(e.name) ? [full] : [];
    });
}

test('panel kaynağında elle yazılmış adres yok', () => {
    const problems = [];
    for (const dir of DIRS) {
        for (const file of files(path.join(SRC, dir))) {
            const rel = path.relative(SRC, file).split(path.sep).join('/');
            if (ALLOWED.has(rel)) continue;
            fs.readFileSync(file, 'utf-8').split('\n').forEach((line, i) => {
                // Yorum satırları örnek adres taşıyabilir.
                if (/^\s*(\*|\/\/|\{#)/.test(line)) return;
                for (const rule of RULES) if (rule.re.test(line)) problems.push(`${rel}:${i + 1} ${rule.name}: ${line.trim().slice(0, 120)}`);
            });
        }
    }
    assert.deepEqual(problems, [], `routes.js'teki href()/navigate() kullanın:\n${problems.join('\n')}`);
});
