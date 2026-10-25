/**
 * Kullanım Kılavuzu: Tüm İşlemler'den açılış, sayfaya girince başlayan tur (bulanık arka plan,
 * hedefin çevresinde halka, klavye, kapalı menü grubu turda açılır, tercih korunur), rehberler
 * (okuyucu, içindekiler, rota tablosundan bağlantılar, geri tuşu, paylaşılabilir adres) ve
 * telefonda menü çekmecesiyle tur.
 */
import { test, expect } from '@playwright/test';
import { consoleErrors, login } from '../helpers.js';

const card = (page) => page.locator('[data-testid=tour-card]');

/** Kart başlığı `title` olana kadar ilerler. */
async function stepTo(page, title) {
    for (let i = 0; i < 40; i++) {
        if ((await card(page).locator('.tour-title').textContent()) === title) return;
        await page.keyboard.press('ArrowRight');
    }
    throw new Error(`turda adım yok: ${title}`);
}

/** Halka görünen hedefin üstünde mi (kenar payı 6 px). */
async function ringOn(page, selector) {
    const ring = await page.locator('.tour-ring').boundingBox();
    const target = await page.locator(`${selector} >> visible=true`).boundingBox();
    expect(ring && target).toBeTruthy();
    expect(Math.abs(ring.x + 6 - target.x)).toBeLessThan(3);
    expect(Math.abs(ring.y + 6 - target.y)).toBeLessThan(3);
}

test('kılavuz: tur, menünün bölümleri, rehberler', async ({ page }) => {
    const errors = consoleErrors(page);
    await login(page);
    // Sistem grubu kapalı: tur onu açar, bitince tercih aynen kalır.
    await page.goto('/panel/diger-islemler');
    await page.click('[data-testid=other-guide]');
    await expect(page).toHaveURL(/\/panel\/kilavuz$/);

    // Sayfaya girince tur başlar: karşılama ortada, arka plan bulanık, sayfaya tıklanmaz.
    await expect(card(page)).toBeVisible();
    await expect(card(page)).toContainText('Kodmob Kullanım Kılavuzuna Hoş Geldiniz');
    expect(await page.locator('.tour-shade').first().evaluate((el) => getComputedStyle(el).backdropFilter)).toContain('blur');
    await expect(page.locator('html')).toHaveClass(/tour-open/);

    await page.keyboard.press('ArrowRight');
    await expect(card(page).locator('.tour-title')).toHaveText('Panelde arayın');
    await page.waitForTimeout(600);
    await ringOn(page, 'header [data-tour=search]');
    await page.keyboard.press('ArrowLeft');
    await expect(card(page).locator('.tour-title')).toHaveText('Kodmob Kullanım Kılavuzuna Hoş Geldiniz');

    // Menü adımları yan menünün bölümleri; adımda bölümün sekmeleri sayılır.
    await stepTo(page, 'Ayarlar');
    await expect(card(page)).toContainText('Kullanıcılar');
    await page.waitForTimeout(600);
    await ringOn(page, 'aside [data-tour=nav-settings]');
    const box = await card(page).boundingBox();
    expect(box.x + box.width).toBeLessThanOrEqual(1360);
    // Düğme satırı karta sığar (taşan "İleri" görünmez kalıyordu).
    expect(await card(page).locator('.tour-actions').evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);

    // Otomatik oynatma: duraklat simgesinin çevresinde kalan süre halkası; duraklatınca kalkar.
    const auto = card(page).locator('[data-testid=tour-autoplay]');
    const timer = card(page).locator('[data-testid=tour-timer]');
    await expect(timer).toHaveCount(0);
    await auto.click();
    await expect(auto).toHaveAttribute('aria-pressed', 'true');
    await expect(timer).toBeVisible();
    expect(await timer.evaluate((el) => el.style.getPropertyValue('--tour-dur'))).toMatch(/^\d+ms$/);
    await auto.click();
    await expect(timer).toHaveCount(0);

    // Esc turu kapatır.
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-testid=tour]')).toBeHidden();
    await expect(page.locator('html')).not.toHaveClass(/tour-open/);

    // Turu yeniden başlat → sonuna kadar → "Rehberlere geç".
    await page.click('[data-testid=guide-tour]');
    await expect(card(page)).toContainText('Hoş Geldiniz');
    await stepTo(page, 'Hepsi bu kadar');
    await card(page).getByRole('button', { name: 'Rehberlere geç' }).click();
    await expect(page.locator('[data-testid=tour]')).toBeHidden();
    await expect(page.locator('#guides-title')).toBeFocused();

    // Rehberler: beşi de (Yönetici); okuyucu, içindekiler, bağlantılar rota tablosundan.
    await expect(page.locator('[data-guide]')).toHaveCount(5);
    await page.locator('[data-guide=baslangic]').getByRole('button').click();
    await expect(page).toHaveURL(/\/panel\/kilavuz\?rehber=baslangic$/);
    const article = page.locator('[data-testid=guide-article]');
    await expect(article.locator('h2#yayin')).toHaveText('Taslak, yayın ve zamanlama');
    await expect(page.locator('.guide-toc li')).toHaveCount(9);
    await expect(article.locator('a[data-route=history]')).toHaveAttribute('href', '/panel/islem-gecmisi');
    await page.locator('.guide-toc a', { hasText: 'Çeviriler' }).click();
    await expect(page.locator('.guide-toc a.is-active')).toHaveText('Çeviriler');

    // Geri tuşu listeye döner; paylaşılan adres rehberi açar, tur başlamaz.
    await page.goBack();
    await expect(page).toHaveURL(/\/panel\/kilavuz$/);
    await expect(page.locator('[data-guide]')).toHaveCount(5);
    await page.goto('/panel/kilavuz?rehber=yonetim');
    await expect(page.locator('[data-testid=guide-article] h2#siteler')).toBeVisible();
    await expect(page.locator('[data-testid=tour]')).toBeHidden();
    await page.click('[data-testid=guide-back]');
    await expect(page).toHaveURL(/\/panel\/kilavuz$/);
    // Hız rehberi: bağlantıları panel adreslerine çözülür.
    await page.goto('/panel/kilavuz?rehber=hiz');
    await expect(page.locator('[data-testid=guide-article] h2#sayfa-onbellegi')).toHaveText('Sayfa önbelleği');
    await expect(page.locator('[data-testid=guide-article] a[data-route=health]')).toHaveAttribute('href', '/panel/sistem-sagligi');

    expect(errors()).toEqual([]);
});

test('kılavuz telefonda: menü adımlarında çekmece açılır, kart ekrana sığar', async ({ page }) => {
    const errors = consoleErrors(page);
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/panel/kilavuz');
    await expect(card(page)).toBeVisible();

    const drawer = page.locator('[data-tour-drawer]');
    await page.keyboard.press('ArrowRight');
    await expect(card(page).locator('.tour-title')).toHaveText('Panelde arayın');
    await expect(drawer).toBeHidden();

    await stepTo(page, 'Genel bakış');
    await expect(drawer).toBeVisible();
    await page.waitForTimeout(800);
    await ringOn(page, '[data-tour-drawer] [data-tour=nav-overview]');
    const box = await card(page).boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(390);
    expect(box.y + box.height).toBeLessThanOrEqual(844);
    expect(await card(page).locator('.tour-actions').evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);

    await card(page).getByRole('button', { name: 'Turu atla' }).click();
    await expect(page.locator('[data-testid=tour]')).toBeHidden();
    await expect(drawer).toBeHidden();
    expect(errors()).toEqual([]);
});
