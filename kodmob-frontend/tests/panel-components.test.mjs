/**
 * Panel bileşenleri (src/panel/*.js; Alpine): init / destroy'da `this.x = …` ile atanan her özellik bileşenin döndürdüğü
 * nesnede tanımlı olmalı. Alpine tanımsız özelliği en dıştaki bileşene (panel) yazar: kapanan görünümün `destroyed`'ı
 * ya da olay dinleyicisi (`_onRoute`) sonraki görünüme geçer (Sayfa düzenleyici ikinci açılışta "Sayfa açılıyor…"da
 * kalıyordu). Karışım nesnesi (editor-languages) katıldığı bileşenin tanımlarıyla birlikte denetlenir.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../src/panel');
const MIXINS = { 'editor-languages.js': 'editor.js' };

/** Bileşen nesnesinin üst düzey anahtarları (8 boşluk girintili: `ad:`, `ad,`, `ad(`, `get ad(`, `async ad(`). */
const declared = (source) => new Set([...source.matchAll(/^ {8}(?:async |get |set )?([A-Za-z_$][\w$]*)\s*(?:[:,(]|$)/gm)].map((m) => m[1]));
const assigned = (source) => new Set([...source.matchAll(/this\.([A-Za-z_$][\w$]*)\s*=(?!=)/g)].map((m) => m[1]));

test('panel bileşenleri: sonradan atanan özellikler bileşende tanımlı', () => {
    const problems = [];
    for (const file of fs.readdirSync(DIR).filter((f) => f.endsWith('.js'))) {
        const source = fs.readFileSync(path.join(DIR, file), 'utf-8');
        if (!/^\s*return \{$/m.test(source)) continue;
        const known = declared(source);
        if (MIXINS[file]) for (const key of declared(fs.readFileSync(path.join(DIR, MIXINS[file]), 'utf-8'))) known.add(key);
        for (const key of assigned(source)) if (!known.has(key)) problems.push(`${file}: this.${key}`);
    }
    assert.deepEqual(problems, [], 'bileşenin nesnesine ekleyin (ör. `destroyed: false`, `_onRoute: null`)');
});
