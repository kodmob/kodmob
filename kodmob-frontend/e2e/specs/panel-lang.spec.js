/**
 * Çok dilli panel (kodmob-core Modules\System\PanelLanguage): frontend-lang.json indir, doldur, Sistem Ayarları › Panel
 * dili'nden içe aktar; Profilim'de dili seç; panelin metinleri ve sunucu mesajı o dilde. Sonunda Türkçe'ye dönülür.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login } from '../helpers.js';

test('panel dili: dışa aktar, çevir, içe aktar, seç; ekran ve mesajlar çevrilir', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await api.put('/me/panel-language', { code: 'tr' });
    await api.del('/system/panel-languages/en');

    // Dosya: bütün panel metinleri ve kodlu sunucu mesajları, çevirileri boş.
    const file = (await api.get('/system/panel-languages/export?code=en&name=English')).json;
    expect(file.format).toBe('kodmob-panel-lang/1');
    expect(file.strings).toHaveProperty('Kartları düzenle', '');
    const code = Object.keys(file.messages).find((k) => file.messages[k].tr === 'Bu dil kurulu değil.');
    expect(code).toMatch(/^km_m_[0-9a-f]{8}$/);
    file.strings['Kartları düzenle'] = 'Edit cards';
    file.strings['Özet'] = 'Summary';
    file.strings['Panel dili'] = 'Panel language';
    file.messages[code].text = 'This language is not installed.';

    await login(page);
    await page.goto('/panel/sistem-ayarlari/panel-dili');
    await page.locator('[data-testid=panel-lang-import]').setInputFiles({ name: 'frontend-lang.en.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(file)) });
    await expect(page.locator('[data-testid=panel-languages] [data-language=en]')).toContainText('English');

    try {
        // Profilim: dil seçilir, sayfa yenilenir.
        await page.goto('/panel/profilim');
        await page.locator('[data-testid=panel-language-picker] select').selectOption('en');
        await page.waitForLoadState('load');
        await page.goto('/panel/');
        await expect(page.locator('[data-testid=widgets-edit]')).toContainText('Edit cards');
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        // Sunucu mesajının kodu sözlükte (mesaj çevirisi backend testinde: PanelLanguageTest).
        expect((await api.get('/panel-lang/en')).json.messages[code]).toBe('This language is not installed.');
    } finally {
        await api.put('/me/panel-language', { code: 'tr' });
        await api.del('/system/panel-languages/en');
    }
    expect(errors()).toEqual([]);
});
