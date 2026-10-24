/**
 * Araç Pazarı (/panel/araclar): kurulu araç bu projede kapatılır, menüden ve işlemlerden kalkar, yeniden açılır;
 * Proje modüllerindeki "Araç ekle" Keşfet sekmesini açar.
 */
import { test, expect } from '@playwright/test';
import { confirmYes, consoleErrors, login, requires, openFromMenu, tabLink } from '../helpers.js';

test('Araç Pazarı: kurulu aracı kapat / aç, Keşfet', async ({ page, request }) => {
    await requires(request, { modules: ['domains'] });
    const errors = consoleErrors(page);
    await login(page);
    // Domain arama Gelenler'in sekmesi; Araç Pazarı Ayarlar'ın.
    await openFromMenu(page, 'inbox', '/panel/jarvis/domain-arama');
    const domains = tabLink(page, '/panel/jarvis/domain-arama');
    await openFromMenu(page, 'settings', '/panel/araclar');
    await expect(page.locator('[data-testid=tools]')).toBeVisible();

    // Domain arama kapatılır: menüden kalkar; yeniden açılınca geri gelir.
    const card = page.locator('[data-tool=domains]');
    await expect(card.locator('[data-testid=tool-state]')).toHaveText('Bu projede açık');
    await card.locator('[data-testid=tool-toggle]').dispatchEvent('click');
    await confirmYes(page);
    await expect(card.locator('[data-testid=tool-state]')).toHaveText('Bu projede kapalı');
    await expect(page.locator('aside a[href="/panel/jarvis/domain-arama"]')).toHaveCount(0);
    await expect(card.locator('[data-testid=tool-toggle]')).toBeEnabled();

    await card.locator('[data-testid=tool-toggle]').dispatchEvent('click');
    await expect(card.locator('[data-testid=tool-state]')).toHaveText('Bu projede açık');
    await openFromMenu(page, 'inbox', '/panel/jarvis/domain-arama');
    await expect(domains.first()).toBeVisible();
    expect(errors()).toEqual([]);
});

test('Araç Pazarı: çatının aracı derlemesiz eklenir (takıldıklarıyla), sitedeki sayfası açılır; çıkarılınca 404', async ({ page, request }) => {
    const errors = consoleErrors(page);
    await login(page);
    expect((await request.get('/oteller/')).status()).toBe(404);

    // Keşfet: Oteller → Mağaza ve Rezervasyon da birlikte eklenir.
    await page.goto('/panel/araclar/kesfet');
    const offer = page.locator('[data-testid=tools] [data-tool=hotels]');
    await expect(offer).toContainText('Mağaza, Rezervasyon aracına takılır; birlikte eklenir');
    await expect(offer).toContainText('Sitede 2 sayfası açılır');
    await offer.locator('[data-testid=tool-install]').click();
    await confirmYes(page);
    await openFromMenu(page, 'content', '/panel/oteller');
    await expect(tabLink(page, '/panel/urunler').first()).toBeVisible();
    expect((await request.get('/oteller/')).status()).toBe(200);

    // Kurulu sekmesi: önce takılı olan (Oteller), sonra Rezervasyon ve Mağaza çıkarılır.
    await page.goto('/panel/araclar');
    for (const key of ['hotels', 'booking', 'commerce']) {
        await page.locator(`[data-tool=${key}] [data-testid=tool-uninstall]`).click();
        await confirmYes(page);
        await expect(page.locator(`[data-tool=${key}] [data-testid=tool-uninstall]`)).toHaveCount(0);
    }
    await openFromMenu(page, 'content', '/panel/blog');
    await expect(tabLink(page, '/panel/oteller')).toHaveCount(0);
    expect((await request.get('/oteller/')).status()).toBe(404);
    expect(errors()).toEqual([]);
});
