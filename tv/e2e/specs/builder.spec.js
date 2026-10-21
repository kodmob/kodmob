/**
 * Sayfa düzenleyici (panel › Sayfa düzenleyici; docs/done-onyuz-raporu.md): sitenin gerçek sayfası çerçevede, bloğu
 * kitaplıktan ekleme (Keşfet bloğu ilk kullanımda kitaplığa girer), alandan ve yerinde yazma, taslağın
 * ziyaretçiye görünmemesi, yayın, geri al. Blok sayfası (yeni sayfa, yayın). Keşfet'te Animasyon blokları.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, stamp } from '../helpers.js';

test.skip(process.env.KM_PROFILE === 'starter', 'kodmob.com derlemesiyle');

test('sayfa düzenleyici: blok ekle, yaz, yayınla, geri al', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const s = stamp();
    // Temiz başlangıç: ana sayfanın altı boş ve yayında; Kayan etiketler kitaplıkta değil (Keşfet'ten).
    await api.put('/admin/layouts/home.bottom', { blocks: [] });
    await api.post('/admin/layouts/publish', {});
    await api.put('/admin/blocks/marquee', { added: false });

    await login(page);
    await page.goto('/panel/sayfa-duzenleyici?sayfa=home');
    const frame = page.frameLocator('[data-testid=builder-frame]');
    await expect(frame.locator('[data-km-zone="home.bottom"]')).toBeAttached({ timeout: 20000 });
    await expect(page.locator('[data-testid=builder-page]')).toContainText('Ana Sayfa');

    // Bölgenin "Blok ekle"si kitaplığı o yere açar; Animasyon kategorisi.
    await page.locator('.bd-zone[data-zone="home.bottom"] .bd-add').click();
    await expect(page.locator('[data-testid=builder-tab-add]')).toHaveAttribute('aria-selected', 'true');
    await page.locator('.bd-cats .chip', { hasText: 'Animasyon' }).click();
    const card = page.locator('[data-testid=builder-card][data-block="marquee"]');
    await expect(card).toContainText('Keşfet');
    await card.click();
    const marquee = frame.locator('[data-km-zone="home.bottom"] [data-km-block="marquee"]');
    await expect(marquee).toBeVisible();
    expect((await api.get('/admin/blocks')).json.items.find((b) => b.type === 'marquee').inLibrary).toBe(true);

    // Alan: yazdıkça önizleme yenilenir.
    const inspector = page.locator('[data-testid=builder-inspector]');
    await expect(inspector).toContainText('Kayan etiketler');
    const footer = inspector.locator('[data-field="footer"] input');
    await footer.fill(`Alt not ${s}`);
    await expect(marquee.locator('.km-marquee-footer')).toHaveText(`Alt not ${s}`);

    // Yerinde yazma: çift tık, yaz, Enter.
    await marquee.locator('.km-marquee-footer').dblclick();
    await page.keyboard.press('Control+a');
    await page.keyboard.type(`Yerinde ${s}`);
    await page.keyboard.press('Enter');
    await expect(marquee.locator('.km-marquee-footer')).toHaveText(`Yerinde ${s}`);
    await expect(footer).toHaveValue(`Yerinde ${s}`);

    // Taslak ziyaretçiye görünmez; yayınlanınca görünür.
    await expect(page.locator('[data-testid=builder-status]')).toContainText('Taslak', { timeout: 10000 });
    expect(await (await request.get('/')).text()).not.toContain(`Yerinde ${s}`);
    await page.locator('[data-testid=builder-publish]').click();
    await expect(page.locator('[data-testid=builder-status]')).toContainText('Yayında');
    const live = await (await request.get('/')).text();
    expect(live).toContain(`Yerinde ${s}`);
    expect(live).not.toContain('data-km-field');

    // Kaldır ve geri al.
    await page.locator('[data-testid=builder-remove]').click();
    await expect(marquee).toHaveCount(0);
    await page.keyboard.press('Control+z');
    await expect(frame.locator('[data-km-block="marquee"]')).toHaveCount(1);

    // Gezin kipinde site gibi çalışır; düzenleme çerçevesi yok.
    await page.locator('[data-testid=builder-interact]').click();
    await expect(page.locator('[data-testid=builder-toolbar]')).toBeHidden();

    // Toparla: bölge boş yayında.
    await api.put('/admin/layouts/home.bottom', { blocks: [] });
    await api.post('/admin/layouts/publish', {});
    expect(errors()).toEqual([]);
});

test('blok sayfası: düzenleyiciden yeni sayfa, yayınla, kökteki adresinde açılır', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const s = stamp();
    const title = `Kampanya ${s}`;
    const url = `/kampanya-${s}/`;
    await login(page);
    await page.goto('/panel/sayfa-duzenleyici?sayfa=home');
    const frame = page.frameLocator('[data-testid=builder-frame]');
    await expect(frame.locator('[data-km-zone="home.bottom"]')).toBeAttached({ timeout: 20000 });

    // Sayfa seçicide "Yeni sayfa": adres başlıktan önerilir.
    await page.locator('[data-testid=builder-page]').click();
    await page.locator('[data-testid=builder-new-page]').click();
    const dialog = page.locator('[data-testid=builder-new-page-dialog]');
    await page.locator('[data-testid=builder-new-page-title]').fill(title);
    await expect(dialog).toContainText(`Sitede: ${url}`);
    await page.locator('[data-testid=builder-new-page-create]').click();

    // Taslak açılır; ilk bölüm sayfanın başlığıyla karşılama. Ziyaretçi henüz göremez.
    await expect(page.locator('[data-testid=builder-page]')).toContainText(title);
    await expect(frame.locator('[data-km-zone^="page.main@"] [data-km-block="hero"]')).toContainText(title, { timeout: 20000 });
    await expect(page.locator('[data-testid=builder-page-settings]')).toBeVisible();
    expect((await request.get(url)).status()).toBe(404);

    // Yayınla: bölüm ve sayfa birlikte yayına.
    await page.locator('[data-testid=builder-publish]').click();
    await expect(page.locator('[data-testid=builder-status]')).toContainText('Yayında');
    const live = await request.get(url);
    expect(live.status()).toBe(200);
    expect(await live.text()).toContain(title);

    // Toparla.
    const item = (await api.get('/admin/content/pages')).json.items.find((p) => p.title === title);
    expect((await api.del(`/admin/content/pages/${item.id}`)).status).toBe(204);
    expect(errors()).toEqual([]);
});

test('sayfa düzenleyici: panele dönüp menüden yeniden girince önizleme açılır', async ({ page }) => {
    // Kapanan düzenleyicinin `destroyed`'ı panelin kök bileşenine yazılıyordu: ikinci açılış "Sayfa açılıyor…"da kalırdı.
    await login(page);
    await page.goto('/panel/sayfa-duzenleyici?sayfa=home');
    const frame = page.frameLocator('[data-testid=builder-frame]');
    await expect(frame.locator('[data-km-zone="home.main"]')).toBeAttached({ timeout: 20000 });
    await page.getByRole('button', { name: 'Panele dön' }).click();
    await page.locator('aside nav a[href="/panel/sayfa-duzenleyici"]').first().click();
    await expect(frame.locator('[data-km-zone="home.main"]')).toBeAttached({ timeout: 20000 });
    await expect(page.locator('.bd-loading')).toBeHidden();
});

test('Keşfet: Animasyon blokları kitaplığa eklenir', async ({ page, request }) => {
    const api = await apiClient(request);
    await api.put('/admin/blocks/letters', { added: false });
    await login(page);
    await page.goto('/panel/araclar/kesfet');
    await page.locator('[data-filter="animation"]').click();
    const card = page.locator('[data-testid=block-card][data-block="letters"]');
    await expect(card).toContainText('Harf animasyonlu başlık');
    await expect(page.locator('[data-testid=block-card][data-block="hero"]')).toHaveCount(0);
    await card.locator('[data-testid=block-toggle]').click();
    await expect(card.locator('[data-testid=block-in-library]')).toBeVisible();
    expect((await api.get('/admin/blocks')).json.items.find((b) => b.type === 'letters').inLibrary).toBe(true);
});
