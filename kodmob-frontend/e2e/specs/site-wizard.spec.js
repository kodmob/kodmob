/**
 * Site Sihirbazı: panelde işletme bilgisi → öneri (test ortamında yapay zekâ anahtarı yok: yalnızca logo yazısı ve
 * renkler) → kurulum; yeni site kendi alan adında o logo ve renkle açılır. Sonunda kayıttan çıkarılır.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, stamp, requires } from '../helpers.js';
import { PORT } from '../env.js';

test('sihirbaz: öneri, kurulum, yeni sitede logo ve renk', async ({ page, request, browser }) => {
    await requires(request, { modules: ['sites'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const id = stamp();
    const domain = `klinik-${id}.localhost`;

    await login(page);
    await page.goto('/panel/siteler');
    await page.click('[data-testid=site-new]');
    await expect(page.locator('[data-testid=wizard]')).toBeVisible();
    await page.fill('[data-testid=site-name]', `Gülümse Klinik ${id}`);
    await page.fill('[data-testid=wizard-sector]', 'diş kliniği');
    await page.click('[data-testid=wizard-plan]');
    await expect(page.locator('[data-testid=wizard-about] ~ .field-error')).toContainText('en az 20'); // kısa tanım eksik
    await page.fill('[data-testid=wizard-about]', 'Adana Seyhan\'da implant, ortodonti ve çocuk diş hekimliği yapan aile kliniği.');
    await page.click('[data-testid=wizard-plan]');
    const result = page.locator('[data-testid=wizard-plan-result]');
    await expect(result).toContainText('Yapay zekâsız');
    await expect(result.locator('input').first()).toHaveValue('gülümse');
    const color = await result.locator('input[type=color]').first().inputValue();

    await page.fill('[data-testid=site-domains]', domain);
    await page.fill('[data-testid=site-admin-username]', `klinik-${id}`);
    await page.fill('[data-testid=site-admin-email]', `klinik-${id}@example.com`);
    await page.fill('[data-testid=site-admin-password]', `klinik-sifre-${id}`);
    await page.click('[data-testid=site-save]');
    await expect(page.locator('[data-testid=site-created]')).toContainText('kuruldu', { timeout: 60000 });
    await page.locator('[data-testid=site-dialog] button[type=submit]').click();
    const key = (await api.get('/system/sites')).json.sites.find((s) => s.domains.includes(domain))?.key;
    expect(key).toBeTruthy();

    // Yeni site kendi alan adında sihirbazın logo yazısı ve rengiyle.
    const context = await browser.newContext({ baseURL: `http://${domain}:${PORT}` });
    const other = await context.newPage();
    await other.goto('/');
    await expect(other.locator('.site-header .site-logo-text')).toHaveText('gülümse');
    await expect(other).toHaveTitle(new RegExp(`Gülümse Klinik ${id}`));
    expect(await other.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim())).toBe(color);
    await context.close();

    expect((await api.del(`/system/sites/${key}`)).status).toBe(200);
    expect(errors()).toEqual([]);
});
