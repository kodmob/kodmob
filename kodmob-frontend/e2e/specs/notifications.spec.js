/**
 * Bildirimler sayfası (/panel/bildirimler): zilden "Tümünü gör", durum ve tür süzgeci, okundu / okunmadı say
 * (tek tek ve seçerek), sil. Bildirimi yeni talep üretir (talep yetkisi olanlara gider).
 */
import { test, expect } from '@playwright/test';
import { apiClient, confirmYes, consoleErrors, login, stamp } from '../helpers.js';

test('bildirimler: zilden sayfaya, süzgeç, okundu / okunmadı, sil', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await api.post('/notifications/read', { all: true });
    const name = `E2E Bildirim ${stamp()}`;
    const created = await request.post('/api/v1/briefs', {
        data: { name, email: 'e2e@example.com', services: ['web'], message: 'Uçtan uca test talebi, lütfen dikkate almayın.', consent: true, locale: 'tr', started_at: Date.now() - 60000 },
    });
    expect(created.status()).toBe(201);

    await login(page);
    await page.locator('[data-tour=notifications] > button').click();
    await page.locator('[data-testid=notifications-all]').click();
    await expect(page).toHaveURL(/\/panel\/bildirimler$/);
    const item = page.locator('[data-notification]', { hasText: name });
    await expect(item).toHaveAttribute('data-read', '0');

    // Okunmamış süzgeci; adres ?durum= ile, tür seçicide Talepler.
    const status = page.locator('[data-testid=notifications-status]');
    await status.locator('[data-status=unread]').click();
    await expect(page).toHaveURL(/\?durum=okunmamis$/);
    await expect(page.locator('[data-testid=notifications-kind] option[value=brief]')).toHaveCount(1);
    await expect(page.locator('[data-notification]')).toHaveCount(1);

    // Okundu say: okunmamışlardan çıkar, zil sayısı düşer.
    await item.getByRole('button', { name: 'Okundu say' }).click();
    await expect(page.locator('[data-testid=notifications-empty]')).toBeVisible();
    expect((await api.get('/notifications')).json.unread).toBe(0);

    // Okunmuşlarda seçip "Okunmadı say".
    await status.locator('[data-status=read]').click();
    await expect(page).toHaveURL(/\?durum=okunmus$/);
    await item.locator('input[type=checkbox]').check();
    await page.locator('[data-testid=notifications-bulk]').getByRole('button', { name: 'Okunmadı say' }).click();
    await expect(item).toHaveCount(0);
    expect((await api.get('/notifications')).json.unread).toBe(1);

    // Tümü: tek bildirimi sil; okunmuşları temizle onaylı.
    await status.locator('[data-status=all]').click();
    await expect(page).toHaveURL(/\/panel\/bildirimler$/);
    await item.getByRole('button', { name: 'Sil' }).click();
    await expect(item).toHaveCount(0);
    expect((await api.get('/notifications?page=1&kind=brief')).json.items.some((n) => n.title.includes(name))).toBe(false);
    if (await page.locator('[data-testid=notifications-clear]').isEnabled()) {
        await page.locator('[data-testid=notifications-clear]').click();
        await confirmYes(page);
        await expect.poll(async () => (await api.get('/notifications?page=1&status=read')).json.total).toBe(0);
    }
    expect(errors()).toEqual([]);
});
