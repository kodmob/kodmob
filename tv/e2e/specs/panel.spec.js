/**
 * Panel kabuğu: giriş, menüdeki her sayfanın tembel yüklenerek açılması (src/panel/views.js),
 * derin adresler, bilinmeyen adres, kabuğun görünüm modüllerini taşımaması.
 */
import { test, expect } from '@playwright/test';
import { consoleErrors, login } from '../helpers.js';

const title = (page) => page.locator('h1[x-text="title"]');

test('menüdeki ve Tüm İşlemler\'deki her sayfa açılıyor, konsol hatası yok', async ({ page }) => {
    const errors = consoleErrors(page);
    await login(page);
    // Yan menü: yalnızca beş bölüm; her bölümün sayfaları üstte sekme.
    const sections = await page.locator('aside nav a[data-section]:visible').evaluateAll((anchors) => anchors.map((a) => a.dataset.section));
    expect(sections).toEqual(['overview', 'content', 'inbox', 'growth', 'settings']);
    await expect(page.locator('[data-testid=project-name]').first()).not.toBeEmpty();

    const links = new Map();
    for (const key of sections) {
        await page.locator(`aside nav a[data-section="${key}"]`).first().click();
        await expect(page.locator(`aside nav a[data-section="${key}"][aria-current=page]`).first(), `${key} açıldı`).toBeVisible();
        await expect(page.locator('main [aria-busy=true]')).toHaveCount(0);
        const tabs = await page.locator('[data-testid=section-tabs] a:visible').evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href')));
        for (const href of tabs.length ? tabs : [new URL(page.url()).pathname]) links.set(href, key);
    }
    expect([...links.keys()]).not.toContain('/panel/roller');
    expect([...links.keys()]).not.toContain('/panel/diger-islemler');
    expect([...links.keys()]).toEqual(expect.arrayContaining(['/panel/blog', '/panel/talepler', '/panel/seo', '/panel/proje-ayarlari', '/panel/sistem-ayarlari']));

    for (const [href, key] of links) {
        await page.locator(`aside nav a[data-section="${key}"]`).first().click();
        await expect(page.locator(`aside nav a[data-section="${key}"][aria-current=page]`).first()).toBeVisible();
        const tab = page.locator(`[data-testid=section-tabs] a[href="${href}"]`);
        if (await tab.count()) await tab.first().click();
        await expect.poll(() => new URL(page.url()).pathname, { message: href }).toBe(href);
        await expect(page.locator('[data-testid=forbidden]')).toHaveCount(0);
        await expect(page.locator('main [aria-busy=true]'), `${href} görünümü yüklendi`).toHaveCount(0);
        if (href === '/panel/sayfa-duzenleyici') {
            // Sayfa düzenleyici tam ekran açılır (menünün üstünü kaplar); "Panele dön" ile çıkılır.
            await expect(page.locator('[data-testid=builder]')).toBeVisible();
            await page.getByRole('button', { name: 'Panele dön' }).click();
            await expect(page.locator('[data-testid=builder]')).toHaveCount(0);
        } else {
            await expect(page.locator(`[data-testid=section-tabs] a[href="${href}"][aria-current=page]`).or(page.locator(`aside nav a[data-section="${key}"][aria-current=page]`)).first()).toBeVisible();
        }
    }

    // Menüde olmayan sayfalar Tüm İşlemler'den açılır (sayfa satırları; sekme kısayolları değil).
    await page.goto('/panel/diger-islemler');
    const menu = new Set(links.keys());
    const others = (await page.locator('[data-op] > a:not(.ml-4)').evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href'))))
        .filter((href, i, all) => all.indexOf(href) === i && !menu.has(href));
    for (const href of ['/panel/roller', '/panel/guvenlik', '/panel/islem-gecmisi']) expect(others).toContain(href);
    for (const href of others) {
        await page.goto(href);
        await expect(page.locator('[data-testid=forbidden]'), href).toHaveCount(0);
        await expect(page.locator('main [aria-busy=true]'), `${href} görünümü yüklendi`).toHaveCount(0);
    }
    expect(errors()).toEqual([]);
});

test('derin adresler yeniden yüklemede açılır; bilinmeyen adres "Sayfa bulunamadı"', async ({ page }) => {
    const errors = consoleErrors(page);
    await login(page);
    for (const [url, expected, marker] of [
        ['/panel/sistem-ayarlari/e-posta', 'Sistem Ayarları', '[data-testid=mail]'],
        ['/panel/ayarlar/arka-plan', 'Sistem Ayarları', '[data-testid=jobs]'],
        ['/panel/proje-ayarlari', 'Proje Ayarları', '[data-testid=project-settings]'],
        ['/panel/sistem-ayarlari/yedekleme', 'Sistem Ayarları', '[data-testid=backup]'],
        ['/panel/blog/yeni', 'Yeni yazı', '.pn-editor'],
        ['/panel/diller/liste', 'Sayfalar ve Diller', '[data-testid=language-list]'],
        ['/panel/seo/404', 'SEO', '[data-testid=seo-links]'],
    ]) {
        await page.goto(url);
        await expect(title(page), url).toHaveText(expected);
        await expect(page.locator(marker).first(), url).toBeVisible();
    }
    await page.goto('/panel/olmayan-bir-sayfa');
    await expect(title(page)).toHaveText('Sayfa bulunamadı');
    expect(errors()).toEqual([]);
});

test('kabuk görünüm modüllerini taşımaz (kod bölme)', async ({ request }) => {
    const html = await (await request.get('/panel/')).text();
    expect(html, 'düzenleyici ve tablo ilk açılışta inmez').not.toMatch(/richtext-|\/grid-[\w-]+\.js/);
    const entry = html.match(/src="(\/assets\/js\/panel-[^"]+\.js)"/)?.[1];
    expect(entry).toBeTruthy();
    const size = (await (await request.get(entry)).body()).length;
    expect(size, 'panel giriş dosyası').toBeLessThan(60_000);
});
