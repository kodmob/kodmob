/**
 * Düzenleyici: yeni kayıt, dil ekleme (kopya), dil başına yayın, kaynak değişince "Güncellenmeli",
 * dil kaldırma; görsel alt metinleri dil kaydından; blog çeviri grubu.
 */
import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { TV } from '../env.js';
import { apiClient, confirmYes, consoleErrors, login, saveEditor, stamp, statusOf, requires } from '../helpers.js';

const tabs = (page) => page.locator('[data-testid=locale-tabs] .pn-locale-tab').evaluateAll((els) => els.map((e) => e.dataset.locale).join(','));
const selectedTab = (page) => page.locator('.pn-locale-tab[aria-selected=true]').getAttribute('data-locale');

test('proje: dil ekle, yayınla, eskime, kaldır', async ({ page, request }) => {
    await requires(request, { types: ['projects'] });
    const errors = consoleErrors(page);
    const s = stamp();
    await login(page);
    await page.goto('/panel/projeler/yeni');
    await expect(page.locator('[data-testid=locale-tabs]')).toBeVisible();
    expect(await tabs(page), 'yeni kayıt yalnız varsayılan dille').toBe('tr');
    await page.fill('#f-l-title', `E2E Düzenleyici ${s}`);
    await page.fill('#f-l-slug', `e2e-duzen-${s}`);
    await page.evaluate(() => (window.Alpine.$data(document.querySelector('[x-data^="kmEditor"]')).item.status = 'publish'));
    await saveEditor(page);
    await expect(page).toHaveURL(/\/panel\/projeler\/\d+$/);
    expect((await statusOf(request, `/projeler/e2e-duzen-${s}/`)).status).toBe(200);

    // EN: kopya ile ekle, taslak; yayına al.
    await page.click('[data-testid=locale-add]');
    await page.click('.pn-menu [data-locale=en]');
    await expect(page.locator('[data-testid=locale-adder] input[value=copy]')).toBeChecked();
    await page.click('[data-testid=locale-adder-ok]');
    await expect.poll(() => tabs(page)).toBe('tr,en');
    expect(await selectedTab(page)).toBe('en');
    await expect(page.locator('#f-l-title')).toHaveValue(`E2E Düzenleyici ${s}`);
    await page.fill('#f-l-title', `E2E Editor ${s}`);
    await page.fill('#f-l-slug', `e2e-editor-${s}`);
    await saveEditor(page);
    expect((await statusOf(request, `/en/projects/e2e-editor-${s}/`)).status, 'EN taslak').toBe(404);
    await page.click('[data-testid=locale-status] [data-locale=en] .pn-switch');
    await saveEditor(page);
    expect((await statusOf(request, `/en/projects/e2e-editor-${s}/`)).status, 'EN yayında').toBe(200);
    await expect(page).toHaveURL(/\?dil=en$/);

    // Kaynak (TR) değişince EN "Güncellenmeli"; "Güncel say".
    await page.click('[data-testid=locale-tabs] [data-locale=tr]');
    await page.fill('#f-l-title', `E2E Düzenleyici ${s} yeni`);
    await saveEditor(page);
    await page.click('[data-testid=locale-tabs] [data-locale=en]');
    await expect(page.locator('[data-testid=locale-stale]')).toBeVisible();
    await page.click('[data-testid=locale-stale] .btn-ghost');
    await saveEditor(page);
    await expect(page.locator('[data-testid=locale-stale]')).toBeHidden();

    // EN'i kaldır: sitede 404.
    await page.click('[data-testid=locale-tabs] .ml-auto > button');
    await page.click('[data-testid=locale-remove]');
    await confirmYes(page);
    await expect.poll(() => tabs(page)).toBe('tr');
    await saveEditor(page);
    expect((await statusOf(request, `/en/projects/e2e-editor-${s}/`)).status).toBe(404);
    expect(errors()).toEqual([]);
});

test('görsel alt metinleri dil kaydından; varsayılan dil zorunlu', async ({ page, request }) => {
    await requires(request, { types: ['projects'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const s = stamp();
    const image = path.join(TV, 'e2e', 'fixtures', 'gorsel.jpg');
    const media = await api.upload('/admin/media', { file: { name: `e2e-${s}.jpg`, mimeType: 'image/jpeg', buffer: fs.readFileSync(image) }, 'alts[tr]': `E2E görsel ${s}` });
    expect(media.status).toBe(201);
    const project = await api.post('/admin/content/projects', { status: 'publish', cover: media.json.id, locales: { tr: { status: 'publish' } }, i18n: { tr: { title: `E2E Görsel ${s}`, slug: `e2e-gorsel-${s}` } } });
    expect(project.status).toBe(201);

    await login(page);
    await page.goto(`/panel/projeler/${project.json.id}`);
    await expect(page.locator('.pn-alt-line').first()).toContainText(`E2E görsel ${s}`);
    await page.locator('.pn-image-preview .pn-image-hit').first().click();
    for (const code of ['tr', 'en', 'de']) await expect(page.locator(`#alt-${code}`)).toBeVisible();
    await expect(page.locator('label[for=alt-tr]')).toContainText('Türkçe');
    await expect(page.locator('label[for=alt-en]')).toContainText('boşsa Türkçe');
    await page.keyboard.press('Escape');
    expect(errors()).toEqual([]);
});

test('blog: çeviri oluştur, düzenleyicisi açılır, gruptan ayır', async ({ page }) => {
    const errors = consoleErrors(page);
    const s = stamp();
    await login(page);
    await page.goto('/panel/blog/yeni');
    await expect(page.locator('[data-testid=post-translations]')).toContainText('Kaydettikten sonra');
    await page.fill('#f-s-title', `E2E yazı ${s}`);
    await saveEditor(page);
    const postUrl = new URL(page.url()).pathname;
    await expect(page.locator('[data-testid=post-translations] [data-locale=en]')).toBeVisible();

    await page.click('[data-testid=post-translations] [data-locale=en] .btn-secondary');
    await page.click('[data-testid=post-translations] [data-locale=en] .pn-menu .menu-item:first-child');
    await expect.poll(() => new URL(page.url()).pathname).not.toBe(postUrl);
    await expect(page.locator('[data-testid=post-translations] [data-locale=en]')).toContainText('bu yazı');
    await expect(page.locator('[data-testid=post-translations] [data-locale=tr]')).toContainText('kaynak');

    const unlinked = page.waitForResponse((r) => r.url().endsWith('/unlink'));
    await page.click('[data-testid=post-translations] > button.text-danger');
    await confirmYes(page);
    expect((await unlinked).status()).toBe(200);
    await expect.poll(() => page.locator('[data-testid=post-translations] .pn-lang .btn-secondary').count()).toBeGreaterThanOrEqual(2);
    expect(errors()).toEqual([]);
});
