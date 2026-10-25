/**
 * dfGrid: sürükle-bırak ve klavyeyle sıralama, arama varken sıralama ipucu, canlı şerit
 * ("1 yeni kayıt · 1 güncelleme", tıklayınca vurgu), blogda çeviri grupları.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, createProject, login, stamp, requires } from '../helpers.js';

const GRID = '[data-testid=content-grid]';
const rowKeys = (page) => page.locator(`${GRID} tr[data-grid-row]:visible`).evaluateAll((rows) => rows.map((r) => r.dataset.key));

async function openProjects(page) {
    await page.goto('/panel/projeler');
    await expect(page.locator(`${GRID} tr[data-grid-row]`).first()).toBeVisible();
    // Canlı imleç alınana kadar (şerit sayımı yüklemeden sonra başlar).
    await page.waitForFunction((sel) => window.Alpine.$data(document.querySelector(sel)).liveLoading === false, GRID);
}

test('projeler: sürükle-bırak ve klavyeyle sıralama kaydedilir', async ({ page, request }) => {
    await requires(request, { types: ['projects'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const s = stamp();
    for (const n of [1, 2, 3]) await createProject(api, { title: `E2E Sıra ${n} ${s}`, slug: `e2e-sira-${n}-${s}` });
    await login(page);
    await openProjects(page);
    const keys0 = await rowKeys(page);
    expect(await page.locator(`${GRID} .grid-handle:visible`).count()).toBe(keys0.length);

    // HTML5 sürükle-bırak: fareyle adım adım (headless'ta tek hamle bazen sürüklemeyi başlatmaz).
    await expect(page.locator('[data-testid=grid-reorder-hint]')).toBeHidden();
    const dragged = async () => {
        const saved = page.waitForResponse((r) => r.url().endsWith('/admin/content/projects/order'), { timeout: 5000 }).catch(() => null);
        const from = await page.locator(`[data-handle="${keys0[0]}"]`).boundingBox();
        const to = await page.locator(`tr[data-key="${keys0[2]}"]`).boundingBox();
        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
        await page.mouse.down();
        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2 + 8, { steps: 4 });
        await page.mouse.move(to.x + 40, to.y + to.height / 2, { steps: 12 });
        await page.mouse.up();
        return !!(await saved);
    };
    let ok = false;
    for (let attempt = 0; attempt < 3 && !ok; attempt++) ok = await dragged();
    expect(ok, 'sürükle-bırak sırayı kaydetti').toBe(true);
    await expect.poll(() => rowKeys(page)).toEqual([keys0[1], keys0[2], keys0[0], ...keys0.slice(3)]);
    const server = (await api.get('/admin/content/projects')).json.items.map((i) => String(i.id));
    expect(server).toEqual(await rowKeys(page));
    await expect(page.locator(`tr[data-key="${keys0[0]}"]`)).toHaveClass(/row-fresh/);

    // Tutamakta ↑ iki kez: eski sıra.
    await page.focus(`[data-handle="${keys0[0]}"]`);
    for (let i = 0; i < 2; i++) {
        const done = page.waitForResponse((r) => r.url().endsWith('/admin/content/projects/order'));
        await page.keyboard.press('ArrowUp');
        await done;
    }
    await expect.poll(() => rowKeys(page)).toEqual(keys0);

    // Arama varken sıralama kapalı, ipucu ve "Sıraya göre göster".
    await page.fill(`${GRID} input[type=search]`, 'E2E');
    await expect(page.locator('[data-testid=grid-reorder-hint]')).toBeVisible();
    await expect(page.locator(`${GRID} .grid-handle:visible`)).toHaveCount(0);
    await page.click('[data-testid=grid-reorder-hint] button');
    await expect(page.locator('[data-testid=grid-reorder-hint]')).toBeHidden();
    expect(errors()).toEqual([]);
});

test('canlı şerit: başka yerden eklenen ve değişen kayıt sayılır, tıklayınca vurgulanır', async ({ page, request }) => {
    await requires(request, { types: ['projects'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const s = stamp();
    const existing = await createProject(api, { title: `E2E Canlı A ${s}`, slug: `e2e-canli-a-${s}` });
    await login(page);
    await openProjects(page);

    const created = await createProject(api, { title: `E2E Canlı B ${s}`, slug: `e2e-canli-b-${s}`, status: 'draft' });
    const record = (await api.get(`/admin/content/projects/${existing.id}`)).json;
    record.i18n.tr.summary = `Güncellendi ${s}`;
    expect((await api.put(`/admin/content/projects/${existing.id}`, record)).status).toBe(200);

    const strip = page.locator('[data-grid-live]');
    await expect(strip).toContainText('1 yeni kayıt · 1 güncelleme', { timeout: 30000 });
    expect(await rowKeys(page), 'tablo kendiliğinden değişmez').not.toContain(String(created.id));
    await strip.click();
    await expect(page.locator(`tr[data-key="${created.id}"]`)).toHaveClass(/row-fresh/);
    await expect(page.locator(`tr[data-key="${existing.id}"]`)).toHaveClass(/row-fresh/);
    await expect(strip).toBeHidden();

    expect((await api.del(`/admin/content/projects/${created.id}`)).status).toBe(204);
    await expect(strip).toContainText('1 kayıt silindi', { timeout: 30000 });
    expect(errors()).toEqual([]);
});

test('blog: aynı yazının çevirileri grup olarak; "Grupla" kapatılınca düz liste', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const s = stamp();
    const post = await api.post('/admin/content/posts', { status: 'publish', title: `E2E Grup ${s}`, locale: 'tr' });
    expect(post.status).toBe(201);
    expect((await api.post(`/admin/content/posts/${post.json.id}/translations`, { locale: 'en', mode: 'copy' })).status).toBe(201);

    await login(page);
    await page.goto('/panel/blog');
    const group = page.locator(`${GRID} tr[data-grid-group]:visible`, { hasText: `E2E Grup ${s}` });
    await expect(group).toBeVisible();
    await expect(group).toContainText('TR · EN');

    await page.click('[data-testid=grid-group]');
    await expect(page.locator(`${GRID} tr[data-grid-group]:visible`)).toHaveCount(0);
    await page.click('[data-testid=grid-group]');
    await expect(page.locator(`${GRID} tr[data-grid-group]:visible`).first()).toBeVisible();
    expect(errors()).toEqual([]);
});
