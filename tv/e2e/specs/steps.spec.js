/**
 * Genel bakış › Sıradaki adımlar (kodmob-api Core\Setup\Onboarding): sırayla adımlar, sıradakinin düğmesi o ekranı
 * açar, yapılan adım kendiliğinden işaretlenir; kart gizlenip yeniden gösterilir.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login } from '../helpers.js';

test('sıradaki adımlar: sırayla, ilgili ekrana götürür, yapılınca işaretlenir', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await login(page);
    const card = page.locator('[data-testid=next-steps]');
    await expect(card).toBeVisible();
    await expect(card.locator('[data-step]')).toHaveCount((await api.get('/admin/overview')).json.steps.length);

    // E-posta adımı Sistem Ayarları › E-posta'yı açar.
    await card.locator('[data-step=mail] a').click();
    await expect(page).toHaveURL(/\/panel\/sistem-ayarlari\/e-posta$/);

    // Otomatik yedek açılınca adım işaretlenir.
    const before = (await api.get('/admin/overview')).json.steps.find((s) => s.key === 'backup').done;
    await api.put('/admin/backups/auto', { enabled: true });
    try {
        await page.goto('/panel/');
        await expect(card.locator('[data-step=backup]')).toHaveClass(/is-done/);
    } finally {
        if (!before) await api.put('/admin/backups/auto', { enabled: false });
    }

    // Gizle / göster (bu tarayıcıda hatırlanır).
    await card.locator('[data-testid=next-steps-hide]').click();
    await expect(card).toHaveCount(0);
    await page.reload();
    await expect(page.locator('[data-testid=next-steps-show]')).toBeVisible();
    await page.locator('[data-testid=next-steps-show]').click();
    await expect(card).toBeVisible();
    expect(errors()).toEqual([]);
});
