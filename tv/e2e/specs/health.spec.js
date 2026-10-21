/**
 * Sistem Sağlığı: menüden açılır, çekirdek ve modül kartları, bulgular (bağlantısıyla), hata kodları
 * alanı, Bildir (alıcı yokken uyarı; alıcıyla gönderim). Sonunda alıcı ayarı geri alınır.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login } from '../helpers.js';

const toasts = (page) => page.locator('[aria-live=polite]');

test('sistem sağlığı: kartlar, bulgular, bildir', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    expect((await api.put('/settings', { values: { 'health.report_to': '' } })).status).toBe(200);
    expect((await api.put('/admin/backups/auto', { enabled: false })).status).toBe(200);

    await login(page);
    await page.locator('aside nav a[href="/panel/sistem-sagligi"]').first().click();
    await expect(page).toHaveURL(/\/panel\/sistem-sagligi$/);
    for (const key of ['server', 'security', 'speed', 'database', 'storage', 'background', 'backup']) {
        await expect(page.locator(`[data-health=${key}]`)).toBeVisible();
    }
    await expect(page.locator('[data-health=database]')).toContainText('Tablo güncellemeleri');
    await expect(page.locator('[data-testid=health-level]')).not.toBeEmpty();

    // Otomatik yedek kapalı: bulgu, yanında Yedekleme sayfasına bağlantı.
    const finding = page.locator('[data-testid=health-findings] li', { hasText: 'Otomatik yedek kapalı' });
    await expect(finding).toBeVisible();
    await expect(finding.getByRole('link', { name: 'Yedekleme' })).toHaveAttribute('href', '/panel/sistem-ayarlari/yedekleme');
    await expect(page.locator('[data-testid=health-errors]')).toContainText('Kayıtlı hata yok');

    // Alıcı yokken Bildir uyarır, istek gitmez; alıcıyla gönderir.
    await page.click('[data-testid=health-report]');
    await expect(toasts(page)).toContainText('rapor alıcısını');
    expect((await api.put('/settings', { values: { 'health.report_to': 'servis@example.com' } })).status).toBe(200);
    await page.getByRole('button', { name: 'Yenile' }).click();
    await expect(page.locator('main').getByText('Rapor alıcısı yok')).toBeHidden();
    const sent = page.waitForResponse((r) => r.url().endsWith('/system/health/report'));
    await page.click('[data-testid=health-report]');
    expect((await sent).status()).toBe(200);
    await expect(toasts(page)).toContainText('gönderildi');

    expect((await api.put('/settings', { values: { 'health.report_to': '' } })).status).toBe(200);
    expect(errors()).toEqual([]);
});
