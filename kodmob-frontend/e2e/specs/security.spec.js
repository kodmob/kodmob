/**
 * İki adımlı doğrulama: Profilim'den aç (QR, ilk kod, yedek kodlar), kodla giriş, yanlış kod,
 * yedek kod bir kez, kapat. Sonraki senaryolar şifreyle girebilsin diye sonda kapatılır.
 */
import { test, expect } from '@playwright/test';
import { USER, apiClient, consoleErrors, login, password, totp, section } from '../helpers.js';

async function signIn(page, code) {
    await page.goto('/panel/');
    await page.fill('#login-user', USER);
    await page.fill('#login-pass', password());
    await page.click('button[type=submit]');
    await expect(page.locator('#login-code')).toBeVisible();
    await page.fill('#login-code', code);
    await page.click('button[type=submit]');
}

test('oturum: jeton depoda değil, yenileme HttpOnly çerezde; yenilemede sürer, çıkış her sekmede', async ({ page, context, baseURL }) => {
    const errors = consoleErrors(page);
    await login(page);
    const stored = await page.evaluate(() => Object.keys(localStorage).filter((k) => /token/i.test(k) || /^ey/.test(localStorage.getItem(k) || '')));
    expect(stored, 'kalıcı depoda jeton yok').toEqual([]);
    // Yenileme jetonu (64 hane) hiçbir depoda yok; sekmede yalnızca kısa ömürlü erişim jetonu.
    const refreshLike = await page.evaluate(() => [localStorage, sessionStorage].flatMap((s) => Object.keys(s).map((k) => s.getItem(k))).filter((v) => /[0-9a-f]{64}/.test(v || '')));
    expect(refreshLike).toEqual([]);
    expect(await page.evaluate(() => document.cookie), 'çerez JavaScript\'e görünmez').not.toContain('km_refresh');
    const cookie = (await context.cookies(`${baseURL}/api/v1/auth/token/refresh`)).find((c) => c.name === 'km_refresh');
    expect(cookie?.httpOnly).toBe(true);
    expect(cookie?.sameSite).toBe('Strict');

    // Yenilemede oturum çerezle sürer (erişim jetonu bellekteydi).
    await page.reload();
    await expect(section(page, 'overview')).toBeVisible();

    // İkinci sekme: birinde çıkış yapılınca öbürü de giriş ekranına döner.
    const other = await context.newPage();
    await other.goto('/panel/profilim');
    await expect(other.locator('h1[x-text="title"]')).toHaveText('Profilim');
    await page.locator('button:has-text("Çıkış yap"):visible').first().click();
    await expect(page.locator('#login-user')).toBeVisible();
    await expect(other.locator('#login-user')).toBeVisible();
    // Çıkıştan sonra çerez sunucuda geçersiz: yenilemede yine giriş ekranı.
    await other.reload();
    await expect(other.locator('#login-user')).toBeVisible();
    expect(errors()).toEqual([]);
});

test('iki adımlı doğrulama: aç, kodla gir, yedek kod, kapat', async ({ page, request, browser, baseURL }) => {
    const errors = consoleErrors(page);
    await login(page);
    await page.goto('/panel/profilim');
    const box = page.locator('[data-testid=two-factor]');
    await expect(box.locator('[data-testid=two-factor-state]')).toHaveText('Kapalı');

    let secret = '';
    try {
        await box.locator('[data-testid=two-factor-enable]').click();
        await box.locator('[data-testid=two-factor-password]').fill(password());
        await box.locator('form:visible button[type=submit]').click();
        await expect(box.locator('[data-testid=two-factor-qr] svg')).toBeVisible();
        secret = (await box.locator('[data-testid=two-factor-secret]').textContent()).replace(/\s+/g, '');
        expect(secret).toMatch(/^[A-Z2-7]{32}$/);

        await box.locator('[data-testid=two-factor-code]').fill('000000');
        await box.locator('[data-testid=two-factor-confirm]').click();
        await expect(box.locator('.field-error:visible')).toContainText('hatalı');
        await box.locator('[data-testid=two-factor-code]').fill(totp(secret));
        await box.locator('[data-testid=two-factor-confirm]').click();
        const codes = box.locator('[data-testid=two-factor-codes] li');
        await expect(codes).toHaveCount(8);
        const recovery = (await codes.first().textContent()).trim();
        await box.locator('[data-testid=two-factor-done]').click();
        await expect(box.locator('[data-testid=two-factor-state]')).toHaveText('Açık');

        // Yeni oturum: şifreden sonra kod; yanlış kod reddedilir, doğru kodla girilir.
        const context = await browser.newContext({ baseURL });
        const fresh = await context.newPage();
        await signIn(fresh, '111111');
        await expect(fresh.locator('.pn-login-card .pn-alert')).toContainText('Doğrulama kodu hatalı');
        // Onayda kullanılan adım ikinci kez geçmez; bir sonraki adımın kodu (±1 pencere) geçer.
        await fresh.fill('#login-code', totp(secret, 1));
        await fresh.click('button[type=submit]');
        await expect(section(fresh, 'overview')).toBeVisible();
        await context.close();

        // Yedek kod bir kez geçer.
        const context2 = await browser.newContext({ baseURL });
        const second = await context2.newPage();
        await signIn(second, recovery);
        await expect(section(second, 'overview')).toBeVisible();
        await context2.close();
        const reuse = await request.post('/api/v1/auth/token', { data: { username: USER, password: password(), code: recovery } });
        expect(reuse.status(), 'yedek kod ikinci kez geçmez').toBe(403);

        // Kapat (şifreyle).
        await page.reload();
        await box.locator('[data-testid=two-factor-disable]').click();
        await box.locator('[data-testid=two-factor-password]').fill(password());
        await box.locator('form:visible button[type=submit]').click();
        await expect(box.locator('[data-testid=two-factor-state]')).toHaveText('Kapalı');
    } finally {
        // Senaryo yarıda kalsa da sonraki senaryolar şifreyle girebilsin.
        const state = await request.post('/api/v1/auth/token', { data: { username: USER, password: password() } });
        if ((await state.json()).code === 'otp_required') {
            const token = (await (await request.post('/api/v1/auth/token', { data: { username: USER, password: password(), code: totp(secret, 1) } })).json()).token;
            await request.post('/api/v1/me/two-factor/disable', { data: { password: password() }, headers: { Authorization: `Bearer ${token}` } });
        }
    }
    expect(errors()).toEqual([]);
    await apiClient(request); // şifreyle API oturumu yine açılıyor
});
