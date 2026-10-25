/**
 * Web uygulaması (PWA): sitenin ve panelin bildirimi (manifest), service worker (public/sw.js), bağlantı yokken
 * çevrim dışı sayfası; panelde anlık bildirim kartı. Gerçek abonelik tarayıcının itme servisine bağlanır (testte yok);
 * şifreleme ve gönderim backend testlerinde (WebPushTest, PushTest).
 */
import { test, expect } from '@playwright/test';
import { consoleErrors, login } from '../helpers.js';

test('site: manifest, service worker ve çevrim dışı sayfası', async ({ page, context, request }) => {
    const errors = consoleErrors(page);
    await page.goto('/');
    await expect(page.locator('link[rel=manifest]')).toHaveAttribute('href', '/api/v1/manifest.webmanifest');
    const manifest = await (await request.get('/api/v1/manifest.webmanifest')).json();
    expect(manifest).toMatchObject({ start_url: '/', scope: '/', display: 'standalone' });
    expect(manifest.name.length).toBeGreaterThan(0);

    // Service worker sayfayı denetler hale gelir (kapsam /).
    const scope = await page.evaluate(async () => (await navigator.serviceWorker.ready).scope);
    expect(new URL(scope).pathname).toBe('/');
    await page.reload();
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);

    expect(errors()).toEqual([]);

    // Bağlantı yok: başka bir sayfaya gidince çevrim dışı sayfası (bağlantı hataları beklenir, denetlenmez).
    await context.setOffline(true);
    await page.goto('/bu-sayfa-cevrim-disi-denemesi/').catch(() => {});
    await expect(page.locator('h1')).toHaveText('İnternet bağlantısı yok');
    await context.setOffline(false);
});

test('panel: panelin manifesti, Bildirimler sayfasında anlık bildirim kartı', async ({ page, context }) => {
    await context.grantPermissions(['notifications']);
    const errors = consoleErrors(page);
    await login(page);
    await expect(page.locator('link[rel=manifest]')).toHaveAttribute('href', '/api/v1/manifest.webmanifest?app=panel');
    await page.goto('/panel/bildirimler');
    const card = page.locator('[data-testid=push-card]');
    await expect(card).toHaveAttribute('data-state', /^(off|unsupported)$/);
    if ((await card.getAttribute('data-state')) === 'off') await expect(card.locator('[data-testid=push-enable]')).toBeVisible();
    expect(errors()).toEqual([]);
});
