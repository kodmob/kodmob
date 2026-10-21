/**
 * Tüm İşlemler (üst bardaki ızgara simgesi): kartlar, arama, Hızlı İşlemler (yıldızla ekleme, sunucuda saklanma, sıralama,
 * çıkarma, varsayılana dönüş) ve yan menüden ulaşım.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, requires } from '../helpers.js';

test('Tüm İşlemler: kartlar ve kişiye özel Hızlı İşlemler', async ({ page, request }) => {
    await requires(request, { modules: ['sms'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    expect((await api.del('/me/quick-actions')).status).toBe(200);

    await login(page);
    await expect(page.locator('aside nav a[href="/panel/diger-islemler"]')).toHaveCount(0);
    await page.click('[data-testid=all-ops]');
    await expect(page).toHaveURL(/\/panel\/diger-islemler$/);
    for (const group of ['content', 'crm', 'site', 'seo', 'settings', 'jarvis', 'admin', 'account']) {
        await expect(page.locator(`[data-group=${group}]`)).toBeVisible();
    }
    // Varsayılan Hızlı İşlemler (hiç düzenlenmemiş).
    const quick = page.locator('[data-testid=quick] [data-quick]');
    await expect(quick).toHaveCount(6);
    await expect(quick.first()).toContainText('Yeni yazı');

    // Arama yalnızca eşleşen satırları bırakır.
    await page.fill('[data-testid=other-search]', 'robots');
    await expect(page.locator('[data-op]')).toHaveCount(1);
    await expect(page.locator('[data-op=seo-robots]')).toBeVisible();
    await page.fill('[data-testid=other-search]', '');

    // Yıldızla ekle → sunucuda saklanır (yeniden yüklemede de var).
    await page.locator('[data-op=settings-sms] [data-star]').click();
    await expect(quick).toHaveCount(7);
    await expect(quick.last()).toContainText('SMS ayarları');
    await expect.poll(async () => (await api.get('/me/quick-actions')).json.keys?.at(-1)).toBe('settings-sms');
    await page.reload();
    await expect(quick).toHaveCount(7);

    // Düzenle: öne al, çıkar; sıra sunucuda.
    await page.click('[data-testid=quick-edit]');
    await page.locator('[data-quick=settings-sms] [data-move=left]').click();
    await expect(quick.nth(5)).toHaveAttribute('data-quick', 'settings-sms');
    await page.locator('[data-quick=posts-new] button[aria-label$="çıkar"]').click();
    await expect(quick).toHaveCount(6);
    await expect.poll(async () => (await api.get('/me/quick-actions')).json.keys).toEqual(['briefs', 'texts', 'seo-redirects', 'users', 'settings-sms', 'history']);

    // Varsayılana dön.
    await page.click('[data-testid=quick-reset]');
    await expect(quick).toHaveCount(6);
    await expect(quick.first()).toHaveAttribute('data-quick', 'posts-new');
    await expect.poll(async () => (await api.get('/me/quick-actions')).json.keys).toBeNull();

    // Kutu sayfaya götürür (düzenleme bitince).
    await page.click('[data-testid=quick-edit]');
    await page.locator('[data-quick=seo-redirects] a').click();
    await expect(page).toHaveURL(/\/panel\/seo\/yonlendirmeler$/);
    expect(errors()).toEqual([]);
});
