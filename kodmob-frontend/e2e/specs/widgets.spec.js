/**
 * Genel bakış kartları (kodmob-core Core\Dashboard\Widgets): düzenle kipinde gizle / geri ekle, boyut, sıra; düzen
 * sunucuda kişiye saklanır (yeniden yüklemede kalır), "Varsayılana dön" siler.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login } from '../helpers.js';

test('kartlar: gizle, geri ekle, boyut, sıra; düzen kalır, varsayılana döner', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await api.del('/me/dashboard');
    await login(page);
    const grid = page.locator('[data-testid=widgets]');
    const card = (key) => grid.locator(`[data-widget="${key}"]`);
    await expect(card('content')).toBeVisible();
    await expect(card('content').locator('.pn-widget-stat').first()).toBeVisible();
    await expect(card('activity')).toBeVisible();

    await page.locator('[data-testid=widgets-edit]').click();
    // Gizle: kart çıkar, gizli kartlarda görünür.
    await card('content').locator('[data-testid=widget-hide]').click();
    await expect(card('content')).toHaveCount(0);
    await expect(page.locator('[data-testid=widgets-hidden] [data-show=content]')).toBeVisible();
    // Boyut: son işlemlerim geniş.
    await card('activity').locator('[data-testid=widget-size]').selectOption('lg');
    await expect(card('activity')).toHaveAttribute('data-size', 'lg');
    // Sıra: son işlemlerimi bir öne al.
    const order = async () => grid.locator('[data-widget]').evaluateAll((nodes) => nodes.map((n) => n.dataset.widget));
    const before = await order();
    await card('activity').getByRole('button', { name: 'Öne al' }).click();
    const after = await order();
    expect(after.indexOf('activity')).toBe(before.indexOf('activity') - 1);

    // Kaydedildi: yeniden yüklemede aynı düzen.
    await expect.poll(async () => (await api.get('/admin/dashboard')).json.custom).toBe(true);
    await page.reload();
    await expect(card('activity')).toHaveAttribute('data-size', 'lg');
    await expect(card('content')).toHaveCount(0);
    expect(await order()).toEqual(after);

    // Geri ekle: veri gelir; varsayılana dön.
    await page.locator('[data-testid=widgets-edit]').click();
    await page.locator('[data-testid=widgets-hidden] [data-show=content]').click();
    await expect(card('content').locator('.pn-widget-stat').first()).toBeVisible();
    await page.locator('[data-testid=widgets-reset]').click();
    await expect(card('activity')).toHaveAttribute('data-size', 'md');
    expect((await api.get('/admin/dashboard')).json.custom).toBe(false);
    expect(errors()).toEqual([]);
});
