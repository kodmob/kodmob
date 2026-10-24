/**
 * Başlangıç profili (tv/src/profiles/starter): `npm run e2e -- --starter` site bu profille derlenince koşar
 * (kodmob.com derlemesinde atlanır). Sade site: ana sayfa, blog, iletişim formu (talebe düşer), yasal sayfalar,
 * İngilizce, 404; panel profilin modülleriyle (blog, talepler), kodmob.com'un türleri ve araçları yok.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, password, USER, openFromMenu, section } from '../helpers.js';
import { PROFILE } from '../../profile.js';

test.skip(PROFILE !== 'starter', 'yalnızca başlangıç profiliyle (npm run e2e -- --starter)');

test('başlangıç: sayfalar, iletişim formu, yasal metinler, dil', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);

    await page.goto('/');
    await expect(page).toHaveTitle(/Yeni Site/);
    await expect(page.locator('h1')).toContainText('Yeni siteniz');
    const menu = page.locator('.site-nav .site-nav-link');
    await expect(menu).toHaveText(['Ana Sayfa', 'Blog', 'İletişim']);
    await expect(page.locator('a[href="/projeler/"]')).toHaveCount(0);

    // İletişim formu: eksik alanlar; dolu form talep olarak düşer (hizmet seçimi istemez).
    await page.click('.site-nav a[href="/iletisim/"]');
    const form = page.locator('[data-testid=contact-form]');
    await form.getByRole('button', { name: 'Gönder' }).click();
    await expect(form.locator('.field-error').first()).toBeVisible();
    await form.locator('#contact-name').fill('Deneme Ziyaretçi');
    await form.locator('#contact-email').fill('ziyaretci@example.com');
    await form.locator('#contact-message').fill('Merhaba, hizmetleriniz hakkında bilgi almak istiyorum.');
    await form.locator('input[type=checkbox]').check();
    await page.waitForTimeout(3200); // spam kuralı: form en az 3 sn açık kalmalı
    await form.getByRole('button', { name: 'Gönder' }).click();
    await expect(form.locator('[data-done-title]')).toBeVisible();
    const briefs = (await api.get('/admin/briefs?source=contact')).json;
    expect(briefs.items.some((b) => b.email === 'ziyaretci@example.com')).toBeTruthy();

    // Yasal metinler (çatının şablonları; eski adres 301 ile /yasal/ altına) ve İngilizce.
    await page.goto('/kvkk/');
    await expect(page).toHaveURL(/\/yasal\/kvkk-aydinlatma-metni\/$/);
    await expect(page.locator('h1')).toHaveText('KVKK aydınlatma metni');
    await expect(page.locator('[data-testid=legal-body] h2').first()).toBeVisible();
    await page.goto('/en/');
    await expect(page.locator('h1')).toContainText('Your new site');
    await page.goto('/yok-boyle-bir-sayfa/');
    await expect(page.locator('h1')).toContainText('Sayfa bulunamadı');
    expect(errors()).toEqual([]);
});

test('başlangıç: panel profilin modülleriyle', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    // Örnek araç paketi (extensions.spec; tam çalıştırmada açık) sayılmaz.
    expect((await api.get('/me')).json.modules.filter((m) => m !== 'merhaba')).toEqual(['blog', 'briefs']);
    await api.get('/admin/content/projects').then((r) => expect(r.status).toBe(404));

    await page.goto('/panel/');
    await page.fill('#login-user', USER);
    await page.fill('#login-pass', password());
    await page.click('button[type=submit]');
    await expect(section(page, 'overview')).toBeVisible({ timeout: 20000 });
    await openFromMenu(page, 'inbox', '/panel/talepler');
    await openFromMenu(page, 'content', '/panel/blog');
    for (const href of ['/panel/projeler', '/panel/hizmetler', '/panel/referanslar', '/panel/teklif-hesaplayici', '/panel/siteler']) {
        await expect(page.locator(`aside a[href="${href}"], [data-testid=section-tabs] a[href="${href}"]`)).toHaveCount(0);
    }
    expect(errors()).toEqual([]);
});
