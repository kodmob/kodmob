/**
 * Çoklu Dil sayfası: sekmeler adreste, derlemede olmayan dil pasif eklenir, metin süzgeci adreste,
 * tek dilli kip (diğer diller 302 ile varsayılana).
 */
import { test, expect } from '@playwright/test';
import { apiClient, confirmYes, consoleErrors, login, statusOf } from '../helpers.js';

test('sekmeler, dil ekle/sil, metin süzgeci adreste', async ({ page }) => {
    const errors = consoleErrors(page);
    await login(page);
    await page.goto('/panel/diller');
    await expect(page.locator('[data-testid=i18n-coverage]')).toBeVisible();

    await page.click('[data-testid=language-tabs] [data-tab=list]');
    await expect(page).toHaveURL(/\/panel\/diller\/liste$/);
    await expect(page.locator('[data-testid=language-list] li[data-code=en]')).toBeVisible();

    // Derlemede olmayan dil: pasif eklenir, "Derlemede yok"; silinir.
    await page.click('[data-testid=language-add]');
    const form = page.locator('[data-testid=language-form]');
    await form.locator('input[name=code]').fill('fr');
    await form.locator('input[name=name]').fill('Fransızca');
    await form.locator('input[name=native]').fill('Français');
    await page.click('[data-testid=language-form-save]');
    const fr = page.locator('[data-testid=language-list] li[data-code=fr]');
    await expect(fr).toContainText('Derlemede yok');
    await expect(fr).toContainText('Pasif');
    await fr.locator('button[aria-label=Sil]').click();
    await confirmYes(page);
    await expect(fr).toHaveCount(0);

    await page.click('[data-testid=language-tabs] [data-tab=urls]');
    await expect(page.locator('[data-testid=i18n-urls]')).toContainText('/en/blog/:slug/');

    await page.click('[data-testid=language-tabs] [data-tab=texts]');
    await expect(page).toHaveURL(/\/panel\/diller\/metinler$/);
    await page.click('.pn-locale-tab[data-locale=en]');
    await page.click('[data-testid=texts-filter] [data-filter=outdated]');
    await expect(page).toHaveURL(/\/panel\/diller\/metinler\?dil=en&filtre=guncellenmeli$/);
    expect(errors()).toEqual([]);
});

test('tek dilli kip: diğer diller 302 ile varsayılana, geri açılınca yayında', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await login(page);
    await page.goto('/panel/diller');
    const toggle = page.locator('[data-testid=i18n-toggle]');
    await expect(toggle).toBeChecked();
    // Ayarlar yüklenirken anahtar kapalıdır; açılmadan tıklanırsa tıklama boşa gider.
    await expect(toggle).toBeEnabled();
    expect((await statusOf(request, '/en/')).status).toBe(200);

    try {
        const saved = () => page.waitForResponse((r) => r.url().includes('/settings') && r.request().method() === 'PUT');
        let done = saved();
        await page.locator('label.pn-switch:has([data-testid=i18n-toggle])').click();
        await confirmYes(page); // "Tek dilli moda geçilsin mi?"
        await done;
        await expect(toggle).not.toBeChecked();
        await expect.poll(async () => (await statusOf(request, '/en/')).status).toBe(302);

        await expect(toggle).toBeEnabled();
        done = saved();
        await page.locator('label.pn-switch:has([data-testid=i18n-toggle])').click();
        await done;
        await expect(toggle).toBeChecked();
        await expect.poll(async () => (await statusOf(request, '/en/')).status).toBe(200);
    } finally {
        // Senaryo yarıda kalsa da sonraki testler çok dilli sitede çalışsın.
        await api.put('/settings', { values: { 'i18n.enabled': true } });
    }
    expect(errors()).toEqual([]);
});
