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
    const links = await page.locator('aside nav a[href^="/panel"]:visible').evaluateAll((anchors) => [
        // Etiket: sayaç rozeti (gizli "0" dahil) çıkarılmış ad.
        ...new Map(anchors.map((a) => [a.getAttribute('href'), a.textContent.replace(/\s+/g, ' ').replace(/\s*\d+$/, '').trim()])).entries(),
    ]);
    // Varsayılan menü: Genel bakış, Temel, Proje modülleri, Proje Ayarları, altta Sistem; seyrek sayfalar Tüm İşlemler'de.
    expect(links.length, 'menü sayfaları').toBeGreaterThan(8);
    expect(links.map(([href]) => href)).not.toContain('/panel/roller');
    expect(links.map(([href]) => href)).not.toContain('/panel/diger-islemler');
    expect(links.map(([href]) => href)).toEqual(expect.arrayContaining(['/panel/proje-ayarlari', '/panel/sistem-ayarlari']));
    await expect(page.locator('[data-testid=nav-system] a[href="/panel/sistem-ayarlari"]').first()).toBeVisible();
    await expect(page.locator('[data-testid=project-name]').first()).not.toBeEmpty();

    for (const [href, label] of links) {
        await page.locator(`aside nav a[href="${href}"]:visible`).first().click();
        // Kısayol öğesi ("Araç ekle") sayfanın kendi başlığını gösterir.
        await expect(title(page), href).toHaveText(href === '/panel/araclar/kesfet' ? 'Araç Pazarı' : label);
        await expect(page.locator('[data-testid=forbidden]')).toHaveCount(0);
        await expect(page.locator('main [aria-busy=true]'), `${href} görünümü yüklendi`).toHaveCount(0);
        if (href === '/panel/sayfa-duzenleyici') {
            // Sayfa düzenleyici tam ekran açılır (menünün üstünü kaplar); "Panele dön" ile çıkılır.
            await expect(page.locator('[data-testid=builder]')).toBeVisible();
            await page.getByRole('button', { name: 'Panele dön' }).click();
            await expect(page.locator('[data-testid=builder]')).toHaveCount(0);
        }
    }

    // Menüde olmayan sayfalar Tüm İşlemler'den açılır (sayfa satırları; sekme kısayolları değil).
    await page.goto('/panel/diger-islemler');
    const menu = new Set(links.map(([href]) => href));
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
