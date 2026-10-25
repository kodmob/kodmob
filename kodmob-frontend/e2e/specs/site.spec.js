/**
 * Ziyaretçi sitesi: dil yapısı, ikon dosyası, kayıt sayfasının sunucuda üretilmesi (EntrySsr),
 * yol gösteren 404, silinen içeriğe 410, adresi değişen kayda 301, panelden site kimliği.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, createProject, stamp, statusOf, requires } from '../helpers.js';

test('ana sayfa: dil bağlantıları ve ayrı ikon dosyası', async ({ page }) => {
    const errors = consoleErrors(page);
    const res = await page.goto('/');
    expect(res.status()).toBe(200);
    await expect(page.locator('link[rel=alternate][hreflang=en]')).toHaveAttribute('href', /\/en\/$/);
    await expect(page.locator('html')).toHaveAttribute('data-icons', /^\/assets\/img\/icons-[a-f0-9]{8}\.svg$/);
    expect(await page.locator('symbol').count(), 'ikon seti sayfaya gömülmez').toBe(0);
    // Görünen ilk ikon harici dosyadan çiziliyor.
    await expect.poll(() => page.evaluate(() => {
        const use = [...document.querySelectorAll('svg.icon use')].find((u) => u.closest('svg').getBoundingClientRect().width > 0);
        try {
            return use ? use.getBBox().width : 0;
        } catch {
            return 0;
        }
    }), { timeout: 20000 }).toBeGreaterThan(0);
    // Alt bilgide "Kodmob Core ile geliştirildi" rozeti (sayfanın dilinde).
    await expect(page.locator('footer [data-testid=built-with]')).toContainText('Bu proje Kodmob Core ile geliştirildi');
    expect(errors()).toEqual([]);
});

test('kayıt sayfası içeriği sunucuda: JS olmadan da okunur', async ({ request, browser, baseURL }) => {
    await requires(request, { types: ['projects'] });
    const api = await apiClient(request);
    const s = stamp();
    await createProject(api, { title: `E2E SSR ${s}`, slug: `e2e-ssr-${s}`, summary: 'Sunucuda yazılan özet.' });

    const html = await (await request.get(`/projeler/e2e-ssr-${s}/`)).text();
    expect(html).toContain(`E2E SSR ${s}`);
    expect(html).toContain('Sunucuda yazılan özet.');
    expect(html, 'doldurulmamış işaret kalmaz').not.toMatch(/<!--km:[ehp]:/);

    const context = await browser.newContext({ javaScriptEnabled: false, baseURL });
    const page = await context.newPage();
    await page.goto(`/projeler/e2e-ssr-${s}/`);
    await expect(page.locator('h1', { hasText: `E2E SSR ${s}` })).toBeVisible();
    // Liste sayfası da (ListSsr): kayıt bağlantısı ve başlığı JavaScript'siz.
    await page.goto('/projeler/');
    await expect(page.locator(`a[href="/projeler/e2e-ssr-${s}/"]`)).toContainText(`E2E SSR ${s}`);
    await context.close();

    // JavaScript açıkken sunucu kopyaları kalkar: kayıt bir kez listelenir.
    const live = await browser.newContext({ baseURL });
    const livePage = await live.newPage();
    await livePage.goto('/projeler/');
    await expect(livePage.locator(`a[href="/projeler/e2e-ssr-${s}/"]`)).toHaveCount(1);
    await expect(livePage.locator('[data-km-ssr-item]')).toHaveCount(0);
    await live.close();
});

test('404: başka dilde mevcut kutusu, yazım hatası önerisi; silinen içerik 410', async ({ page, request }) => {
    await requires(request, { types: ['projects'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const s = stamp();
    const slug = `e2e-404-${s}`;
    const project = await createProject(api, { title: `E2E 404 ${s}`, slug });

    let res = await page.goto(`/en/projects/${slug}/`);
    expect(res.status()).toBe(404);
    await expect(page.locator('h1')).toContainText('Page not found');
    await expect(page.locator('[data-testid=nf-available] a')).toHaveAttribute('href', `/projeler/${slug}/`);
    await expect(page.locator('a[hreflang=tr]').first()).toHaveAttribute('href', `/projeler/${slug}/`);

    res = await page.goto(`/projeler/${slug.slice(0, -1)}/`);
    expect(res.status()).toBe(404);
    await expect(page.locator('[data-testid=nf-suggestions] a').first()).toHaveAttribute('href', `/projeler/${slug}/`);

    expect((await api.del(`/admin/content/projects/${project.id}`)).status).toBe(204);
    res = await page.goto(`/projeler/${slug}/`);
    expect(res.status()).toBe(410);
    await expect(page.locator('h1')).toContainText('İçerik kaldırıldı');
    expect(errors()).toEqual([]);
});

test('adresi değişen kayıt eski adresten 301', async ({ request }) => {
    await requires(request, { types: ['projects'] });
    const api = await apiClient(request);
    const s = stamp();
    const project = await createProject(api, { title: `E2E Adres ${s}`, slug: `e2e-eski-${s}` });
    const record = (await api.get(`/admin/content/projects/${project.id}`)).json;
    record.i18n.tr.slug = `e2e-yeni-${s}`;
    expect((await api.put(`/admin/content/projects/${project.id}`, record)).status).toBe(200);

    expect(await statusOf(request, `/projeler/e2e-eski-${s}/`)).toEqual({ status: 301, location: `/projeler/e2e-yeni-${s}/` });
    expect((await statusOf(request, `/projeler/e2e-yeni-${s}/`)).status).toBe(200);
});

test('site kimliği panelden: ad, logo yazısı ve renkler sitede (theme.css)', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const values = { 'site.name': 'Örnek Marka', 'site.logo': 'örnek', 'site.logo_sub': 'ajans', 'site.color': '#E11D48', 'site.color_dark': '#1E1B4B' };
    expect((await api.put('/settings', { values })).json.errors).toEqual({});
    try {
        await page.goto('/projeler/');
        await expect(page).toHaveTitle(/· Örnek Marka$/);
        await expect(page.locator('.site-header .site-logo-text')).toHaveText('örnek');
        await expect(page.locator('.site-header .site-logo-sub')).toHaveText('ajans');
        await expect(page.locator('link[href^="/api/v1/theme.css?v="]')).toHaveCount(1);
        expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim())).toBe('#e11d48');
        expect(errors()).toEqual([]);
    } finally {
        await api.put('/settings', { values: Object.fromEntries(Object.keys(values).map((k) => [k, ''])) });
    }
    await page.goto('/projeler/');
    await expect(page.locator('link[href^="/api/v1/theme.css"]')).toHaveCount(0);
    await expect(page.locator('.site-header .site-logo-text')).not.toHaveText('örnek');
});

test('sayfa önbelleği: değişmeyen sayfa tekrar istekte 304; geri / ileri önbelleğine izin (no-store yok)', async ({ request }) => {
    await request.get('/');
    const first = await request.get('/');
    expect(first.headers()['x-km-cache']).toBe('HIT');
    expect(first.headers()['cache-control']).not.toContain('no-store');
    const etag = first.headers().etag;
    expect(etag).toMatch(/^W\/"[0-9a-f]+-[0-9a-f]+"$/);
    const again = await request.get('/', { headers: { 'If-None-Match': `${etag.slice(0, -1)}-gzip"` } });
    expect(again.status()).toBe(304);
    expect(await again.text()).toBe('');
    expect((await request.get('/', { headers: { 'If-Modified-Since': first.headers()['last-modified'] } })).status()).toBe(304);
    expect((await request.get('/', { headers: { 'If-None-Match': 'W/"eski"' } })).status()).toBe(200);
});
