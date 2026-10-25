/**
 * SMS metin kuralları (src/domain/sms.js) — kodmob-core ile ortak vektörler
 * (kodmob-core/tests/fixtures/sms.json; PHP tarafı tests/Feature/SmsTest.php).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { analyze } from '../src/domain/sms.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const vectors = JSON.parse(fs.readFileSync(path.resolve(HERE, '../../kodmob-core/tests/fixtures/sms.json'), 'utf-8'));

test('SMS metni: temizleme, kodlama, uzunluk, boy', () => {
    for (const v of vectors.texts) {
        const text = v.repeat ? v.repeat.repeat(v.count) : v.text;
        const info = analyze(text);
        const label = text.slice(0, 20);
        if ('clean' in v) {
            assert.equal(info.text, v.clean, `clean: ${label}`);
            assert.deepEqual(info.unsupported, v.unsupported, `unsupported: ${label}`);
        }
        assert.deepEqual([info.encoding, info.length, info.parts], [v.encoding, v.length, v.parts], label);
    }
});
