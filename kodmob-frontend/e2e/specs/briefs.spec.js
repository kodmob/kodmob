/**
 * Talepler: talebin yolu (yeni → okundu → yanıtlandı). Açınca okundu sayılır, "Yanıtladım" ilerletir, arşivden geri
 * alınınca okundu olur.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, stamp } from '../helpers.js';

test('talebin yolu: açınca okundu, Yanıtladım, arşiv ve geri', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const created = await request.post('/api/v1/briefs', {
        data: { name: `E2E Akış ${stamp()}`, email: 'e2e@example.com', services: ['web'], message: 'Uçtan uca test talebi, lütfen dikkate almayın.', consent: true, locale: 'tr', started_at: Date.now() - 60000 },
    });
    expect(created.status()).toBe(201);
    const { id } = await created.json();

    await login(page);
    await page.goto(`/panel/talepler/${id}`);
    const flow = page.locator('[data-testid=brief-flow]');
    await expect(flow.locator('[data-flow=read][aria-current=step]')).toBeVisible();
    await expect(flow.locator('[data-flow=new]')).toHaveClass(/is-done/);

    await page.locator('[data-testid=brief-replied]').click();
    await expect(flow.locator('[data-flow=replied][aria-current=step]')).toBeVisible();
    await expect(page.locator('[data-testid=brief-replied]')).toBeHidden();
    expect((await api.get(`/admin/briefs/${id}`)).json.status).toBe('replied');

    await page.getByRole('button', { name: 'Arşive al' }).click();
    await expect(flow).toBeHidden();
    await page.getByRole('button', { name: 'Arşivden çıkar' }).click();
    await expect(flow.locator('[data-flow=read][aria-current=step]')).toBeVisible();
    expect(errors()).toEqual([]);
});
