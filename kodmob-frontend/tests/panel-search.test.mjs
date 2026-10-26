/**
 * Panel araması: eşleştirme (domain/search.js) ve yerel dizin (panel/search-index.js).
 * Çalıştırma: npm test (node --test).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { fold, mark, rank, score } from '../src/domain/search.js';
import { FIELDS, buildIndex, place, settingPlace } from '../src/panel/search-index.js';
import { match } from '../src/panel/routes.js';

console.error = () => {};
const HERE = path.dirname(fileURLToPath(import.meta.url));
const TEMPLATES = path.resolve(HERE, '../src/pages/panel');

test('Türkçe karakter ve büyük harf farkı yok; uzunluk korunur', () => {
    assert.equal(fold('Şifre İKİ Iğdır ÇÖÜ'), 'sifre iki igdir cou');
    for (const text of ['İstanbul', 'ışık', 'Ağ Güvenliği']) assert.equal(fold(text).length, text.length);
});

test('sıralama: başlıkta baştan eşleşme üstte, her kelime bir yerde geçmeli', () => {
    const entries = [
        { title: 'Gönderen adı', text: 'Ayarlar › E-posta smtp from' },
        { title: 'SMTP şifresi', text: 'Ayarlar › E-posta parola' },
        { title: 'SMTP sunucusu', text: 'Ayarlar › E-posta host' },
        { title: 'Şifremi değiştir', text: 'Profilim parola' },
    ];
    assert.deepEqual(rank(entries, 'smtp sun').map((e) => e.title), ['SMTP sunucusu']);
    assert.deepEqual(rank(entries, 'smtp').map((e) => e.title), ['SMTP şifresi', 'SMTP sunucusu', 'Gönderen adı']);
    assert.deepEqual(rank(entries, 'sifre').map((e) => e.title), ['Şifremi değiştir', 'SMTP şifresi']);
    assert.equal(score(entries[0], 'yok-boyle-bir-sey'), 0);
});

test('vurgulama HTML\'i kaçırır, eşleşen yeri işaretler', () => {
    assert.equal(mark('<b>Şifre</b> ayarı', 'sifre'), '&lt;b&gt;<mark>Şifre</mark>&lt;/b&gt; ayarı');
    assert.equal(mark('SMTP sunucusu', 'smtp sun'), '<mark>SMTP</mark> <mark>sun</mark>ucusu');
    assert.equal(mark('Düz metin', ''), 'Düz metin');
});

test('ayar kaydındaki ayarın yeri: grup ya da anahtar; bilinmeyen grup kapsamına göre Proje / Sistem Ayarları', () => {
    assert.deepEqual(settingPlace({ key: 'contact.phone', group: 'İletişim' }), { href: '/panel/proje-ayarlari', path: 'Proje Ayarları › İletişim' });
    assert.deepEqual(settingPlace({ key: 'health.report_to', group: 'Sistem sağlığı', scope: 'system' }), { href: '/panel/sistem-ayarlari', path: 'Sistem Ayarları › Genel › Sistem sağlığı' });
    assert.deepEqual(settingPlace({ key: 'security.max_attempts', group: 'Güvenlik' }), { href: '/panel/guvenlik/ayarlar', path: 'Güvenlik › Ayarlar' });
    assert.deepEqual(settingPlace({ key: 'seo.ai_bots', group: 'SEO' }), { href: '/panel/seo/robots', path: 'SEO › Robots ve llms' });
    assert.deepEqual(settingPlace({ key: 'seo.module.meta', group: 'SEO' }), { href: '/panel/seo', path: 'SEO › Genel bakış' });
    assert.deepEqual(settingPlace({ key: 'i18n.banner', group: 'Çoklu dil' }), { href: '/panel/diller', path: 'Sayfalar ve Diller › Genel bakış' });
    assert.deepEqual(place('settings', 'sms'), { href: '/panel/sistem-ayarlari/sms', path: 'Sistem Ayarları › SMS' });
});

test('panel formlarındaki her alan şablonda işaretli ve adresi bir sayfaya çıkar', () => {
    const html = fs.readdirSync(TEMPLATES).filter((f) => f.endsWith('.njk')).map((f) => fs.readFileSync(path.join(TEMPLATES, f), 'utf8')).join('\n');
    const anchors = new Set(FIELDS.map((f) => f.anchor));
    assert.equal(anchors.size, FIELDS.length, 'aynı alan iki kez');
    for (const f of FIELDS) {
        // Sağlayıcı kartları listeden üretilir: :data-setting="'ai.' + p.key" (ai.anthropic, ai.openai, ai.google).
        // SMS sağlayıcısının alanları tanımından üretilir: :data-setting="'sms.' + field.key" (NetGSM: username, sender…).
        const dynamic = (/^ai\.(anthropic|openai|google)$/.test(f.anchor) && html.includes(`:data-setting="'ai.' + p.key"`))
            || (/^sms\.(username|password|sender|iys_filter|app_name)$/.test(f.anchor) && html.includes(`:data-setting="'sms.' + field.key"`));
        assert.ok(dynamic || html.includes(`data-setting="${f.anchor}"`), `şablonda yok: data-setting="${f.anchor}"`);
        const p = place(f.view, f.tab);
        assert.notEqual(match(p.href, '').view, 'notFound', `${f.anchor}: ${p.href}`);
    }
    // Ayar kaydındaki ayarlar ortak alan şablonuyla işaretlenir.
    assert.ok(html.includes(':data-setting="s.key"'));
});

test('dizin: yetkiye göre; kayıttaki ayar aynı anahtarlı form alanının yerini alır', () => {
    const none = buildIndex(() => false, []);
    assert.ok(none.settings.every((s) => s.key.startsWith('field:profile.')), 'yetkisiz: yalnızca Profilim alanları');
    assert.ok(none.pages.some((p) => p.key === 'op:profile'));

    const all = buildIndex(() => true, [{ key: 'i18n.enabled', label: 'Çoklu dil', group: 'Çoklu dil', help: 'Tek dilli mod' }]);
    const i18n = all.settings.filter((s) => s.anchor === 'i18n.enabled');
    assert.deepEqual(i18n.map((s) => s.key), ['set:i18n.enabled']);
    const smtp = rank(all.settings, 'smtp sunucu')[0];
    assert.equal(smtp.href, '/panel/sistem-ayarlari/e-posta');
    assert.equal(smtp.anchor, 'mail.host');
});
