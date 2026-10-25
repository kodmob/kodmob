/**
 * Site üyeliği (araç `members`; kodmob-core Modules\Members): sitenin Hesabım sayfasında üye ol, hesabım, çıkış, giriş,
 * hesabı sil; panelde Gelenler › Üyeler. `npm run e2e -- --tools` (başlangıç profili + üyelik açık) koşar.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, requires, stamp } from '../helpers.js';

test('üyelik: kayıt, hesabım, çıkış, giriş, panelde üye, hesabı sil', async ({ page, request, browser }) => {
    await requires(request, { modules: ['members'] });
    const errors = consoleErrors(page);
    const email = `uye-${stamp()}@example.com`;
    const password = `Uye-sifre-${stamp()}9`;

    await page.goto('/hesap/?kip=kayit');
    const form = page.locator('[data-testid=account-register]');
    await expect(form).toBeVisible();
    // Bot süresi (3 sn) dolsun.
    await page.waitForTimeout(3200);
    await form.getByLabel('Ad soyad').fill('Deneme Üye');
    await form.getByLabel('E-posta').fill(email);
    await form.getByLabel('Şifre').fill(password);
    await form.locator('input[type=checkbox]').first().check();
    await form.getByRole('button', { name: 'Üye ol' }).click();
    const dashboard = page.locator('[data-testid=account-dashboard]');
    await expect(dashboard).toBeVisible();
    await expect(page.locator('[data-testid=account-email]')).toHaveText(email);
    await expect(page.locator('[data-testid=account-unverified]')).toBeVisible();

    // Çıkış, yeniden giriş (oturum çerezde: sayfa yenilenince sürer).
    await page.locator('[data-testid=account-logout]').click();
    const loginForm = page.locator('[data-testid=account-login]');
    await loginForm.getByLabel('E-posta').fill(email);
    await loginForm.getByLabel('Şifre').fill('yanlis-sifre');
    await loginForm.getByRole('button', { name: 'Giriş yap' }).click();
    await expect(loginForm.locator('.field-error').filter({ hasText: 'hatalı' })).toBeVisible();
    await loginForm.getByLabel('Şifre').fill(password);
    await loginForm.getByRole('button', { name: 'Giriş yap' }).click();
    await expect(dashboard).toBeVisible();
    await page.reload();
    await expect(dashboard).toBeVisible();

    // Panel: Gelenler › Üyeler'de görünür.
    const panel = await browser.newPage();
    await login(panel);
    await panel.goto('/panel/uyeler');
    await panel.locator('[data-testid=members-search]').fill(email);
    await expect(panel.locator('[data-testid=members] [data-member]')).toHaveCount(1);
    await panel.close();

    // Hesabı sil (KVKK).
    const del = page.locator('[data-testid=account-delete]');
    await del.getByLabel('Şu anki şifre').fill(password);
    await del.getByLabel('Onaylamak için SİL yazın').fill('SİL');
    await del.getByRole('button', { name: 'Hesabımı sil' }).click();
    await expect(page.locator('[data-testid=account-login]')).toBeVisible();
    const api = await apiClient(request);
    expect((await api.get(`/admin/members?search=${encodeURIComponent(email)}`)).json.total).toBe(0);
    expect(errors()).toEqual([]);
});
