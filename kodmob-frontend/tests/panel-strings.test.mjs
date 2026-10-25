// Çok dilli panelin metin toplayıcısı (vite/panel-strings.js): arayüz metinleri alınır, sınıf adı / adres / anahtar alınmaz.
import test from 'node:test';
import assert from 'node:assert/strict';
import { collectPanelStrings, fromScript, fromTemplate, looksLikeText } from '../vite/panel-strings.js';

test('arayüz metni ile kod ayrılır', () => {
    for (const text of ['Kaydet', 'Bütün kartlar gizli.', 'Sil', 'aç', 'Yeni yazı', 'Anlık bildirimler']) assert.ok(looksLikeText(text), text);
    for (const text of ['btn btn-primary', 'km_push_none', '/panel/bildirimler', 'flex-1 min-w-0', 'item.title', 'a', '{{ t }}', 'data-testid', 'https://ornek.com']) assert.ok(!looksLikeText(text), text);
});

test('şablondan yazı, öznitelik ve ifadedeki metinler', () => {
    const set = fromTemplate(`{# yorum #}<button class="btn btn-primary" title="Kaydet ve kapat" @click="save()">Kaydet</button>
        <input placeholder="Ara…"><span x-text="editing ? 'Bitti' : 'Kartları düzenle'"></span><p>{{ year }} Toplam</p>`);
    assert.deepEqual([...set].sort(), ['Ara…', 'Bitti', 'Kaydet', 'Kaydet ve kapat', 'Kartları düzenle', 'Toplam'].sort());
});

test('betikten tırnaklı metinler; içe aktarma ve yorum değil', () => {
    const set = fromScript(`import { api } from './api.js';\n// Yorumdaki Metin\ntoast.success('Kartlar varsayılan düzene döndü.');\nconst k = 'km_steps_hidden';`);
    assert.deepEqual([...set], ['Kartlar varsayılan düzene döndü.']);
});

test('panelin kataloğu: rehberler hariç, sıralı ve tekil', () => {
    const list = collectPanelStrings(['src/pages/panel', 'src/panel'].map((d) => new URL(`../${d}`, import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')));
    assert.ok(list.length > 500);
    assert.ok(list.includes('Kartları düzenle'));
    assert.ok(list.includes('Bu cihazda aç'));
    assert.equal(new Set(list).size, list.length);
    assert.ok(!list.some((s) => s.includes('PageSpeed')), 'rehber metni katalogda değil');
});
