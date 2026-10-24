/**
 * Yasal belgeler (tv/src/legal; kodmob-api Site\Legal): eski adresler 301 ile /yasal/ altına, özet sayfası açık
 * belgeleri listeler, modülü kapalı belge 404; panelden bir dilde özelleştirme ve şablona dönüş; Mağaza Araç
 * Pazarı'ndan eklenince satış belgeleri ve gizlilik politikasındaki alışveriş bölümü gelir, çıkarılınca gider.
 */
import { test, expect } from '@playwright/test';
import { confirmYes, consoleErrors, login, stamp, statusOf, openFromMenu } from '../helpers.js';

test('Yasal belgeler: adresler, modüle göre belgeler, panelden özelleştirme', async ({ page, request }) => {
    test.setTimeout(120000);
    const errors = consoleErrors(page);
    const tag = stamp();

    // Eski adresler kalıcı yönlendirmeyle yeni yerine.
    expect(await statusOf(request, '/gizlilik/')).toEqual({ status: 301, location: '/yasal/gizlilik-politikasi/' });
    expect((await statusOf(request, '/kvkk/')).location).toBe('/yasal/kvkk-aydinlatma-metni/');
    expect((await statusOf(request, '/en/privacy/')).location).toBe('/en/legal/privacy-policy/');

    // Özet sayfası: genel belgeler ve Talepler'in iletişim metni; Mağaza kapalı.
    await page.goto('/yasal/');
    const index = page.locator('[data-testid=legal-index]');
    for (const name of ['privacy', 'kvkk', 'cookies', 'terms', 'contactNotice']) await expect(index.locator(`[data-legal=${name}]`)).toBeVisible();
    await expect(index.locator('[data-legal=shopTerms]')).toHaveCount(0);
    expect((await request.get('/yasal/mesafeli-satis-sozlesmesi/')).status()).toBe(404);

    // Belge: içindekiler, numaralı bölümler, yan menüde açık olan; eksik firma bilgisi köşeli parantezle.
    await index.locator('[data-legal=privacy]').click();
    await expect(page).toHaveURL(/\/yasal\/gizlilik-politikasi\/$/);
    const body = page.locator('[data-testid=legal-body]');
    await expect(body.locator('h2#veri-sorumlusu')).toBeVisible();
    await expect(body).toContainText('[Unvan: Proje Ayarları › Yasal]');
    await expect(body).not.toContainText('Alışveriş yaptığınızda');
    await expect(page.locator('.legal-nav a[aria-current=page]')).toHaveText('Gizlilik politikası');
    await expect(page.locator('.legal-toc a[href="#bize-yazdiginizda"]')).toBeVisible();
    // İletişim formunun onay bağlantısı iletişim aydınlatma metnine.
    await page.goto('/iletisim/');
    expect(await page.locator('a[href="/yasal/iletisim-aydinlatma-metni/"]').count()).toBeGreaterThan(0);

    // Panel: Türkçe gizlilik politikasını özelleştir, sitede gör, şablona dön.
    await login(page);
    await page.goto('/panel/yasal-belgeler');
    await expect(page.locator('[data-testid=legal-missing]')).toBeVisible();
    const privacy = page.locator('[data-testid=legal-doc][data-legal=privacy]');
    await privacy.locator('[data-locale=tr] [data-testid=legal-edit]').click();
    const form = page.locator('[data-testid=legal-form]');
    await form.locator('.ProseMirror').click();
    await page.keyboard.press('Control+End');
    await page.keyboard.press('Enter');
    await page.keyboard.type(`Özel paragraf ${tag}`);
    await form.locator('[data-testid=legal-save]').click();
    await expect(form).toHaveCount(0);
    await expect(privacy.locator('[data-locale=tr] [data-testid=legal-state]')).toHaveText('Özelleştirildi');
    await expect(privacy.locator('[data-locale=en] [data-testid=legal-state]')).toHaveText('Şablon');
    await page.goto('/yasal/gizlilik-politikasi/');
    await expect(page.locator('[data-testid=legal-body]')).toContainText(`Özel paragraf ${tag}`);
    await page.goto('/panel/yasal-belgeler');
    await privacy.locator('[data-locale=tr] [data-testid=legal-edit]').click();
    await form.locator('[data-testid=legal-reset]').click();
    await confirmYes(page);
    await expect(privacy.locator('[data-locale=tr] [data-testid=legal-state]')).toHaveText('Şablon');
    await page.goto('/yasal/gizlilik-politikasi/');
    await expect(page.locator('[data-testid=legal-body]')).not.toContainText(`Özel paragraf ${tag}`);

    // Mağaza eklenince: satış belgeleri ve alışveriş bölümü; çıkarılınca gider.
    await page.goto('/panel/araclar/kesfet');
    await page.locator('[data-testid=tools] [data-tool=commerce] [data-testid=tool-install]').click();
    await openFromMenu(page, 'inbox', '/panel/siparisler');
    await page.goto('/yasal/');
    for (const name of ['shopTerms', 'preInformation', 'delivery', 'returns', 'orderNotice']) await expect(index.locator(`[data-legal=${name}]`)).toBeVisible();
    await page.goto('/yasal/gizlilik-politikasi/');
    await expect(page.locator('[data-testid=legal-body]')).toContainText('Alışveriş yaptığınızda');
    expect((await request.get('/yasal/mesafeli-satis-sozlesmesi/')).status()).toBe(200);
    expect((await statusOf(request, '/satis-sozlesmesi/')).location).toBe('/yasal/mesafeli-satis-sozlesmesi/');

    await page.goto('/panel/araclar');
    await page.locator('[data-tool=commerce] [data-testid=tool-uninstall]').click();
    await confirmYes(page);
    await expect(page.locator('[data-tool=commerce] [data-testid=tool-uninstall]')).toHaveCount(0);
    expect((await request.get('/yasal/mesafeli-satis-sozlesmesi/')).status()).toBe(404);
    expect(errors()).toEqual([]);
});
