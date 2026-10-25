/**
 * Araç paketi (docs/arac-paketi.md): e2e ortamı kodmob-api/tests/fixtures/extensions'daki `merhaba` paketini
 * yükler (`kodmob:tool new` çıktısı; e2e/run.mjs). Paketin sayfası menüde ve kendi adresinde açılır, ucu
 * projenin ayarını okur, Araç Pazarı'nda "Topluluk" olarak görünür ve kapatılınca menüden kalkar.
 */
import { test, expect } from '@playwright/test';
import { confirmYes, consoleErrors, login, openFromMenu } from '../helpers.js';

test.skip(process.env.KM_PROFILE === 'starter', 'başlangıç profilinin senaryosu paketsiz');

test('araç paketi: sayfası, ayarı, Araç Pazarı\'nda aç / kapat', async ({ page }) => {
    const errors = consoleErrors(page);
    await login(page);
    await openFromMenu(page, 'content', '/panel/merhaba');
    await expect(page.locator('[data-testid=tool-merhaba-message]')).toHaveText('Merhaba!');

    // Paketin ayarı Proje Ayarları'nda; değişince sayfası yeni metni okur.
    // Paketin grubu Proje Ayarları'nda kendi sekmesinde; ayar bağlantısı o sekmeyi açar.
    await page.goto('/panel/proje-ayarlari#ayar=merhaba.greeting');
    await page.fill('#set-merhaba\\.greeting', 'Selam, e2e!');
    await page.getByRole('button', { name: 'Kaydet' }).click();
    await expect(page.locator('[data-setting="merhaba.greeting"] input')).toHaveValue('Selam, e2e!');
    await page.goto('/panel/merhaba');
    await expect(page.locator('[data-testid=tool-merhaba-message]')).toHaveText('Selam, e2e!');

    // Araç Pazarı: paket "Topluluk" rozeti ve sürümüyle; kapatılınca menüden kalkar, açılınca döner.
    await page.goto('/panel/araclar');
    const card = page.locator('[data-tool=merhaba]');
    await expect(card).toContainText('Topluluk');
    await expect(card).toContainText('v0.1.0 · Kodmob · MIT');
    await card.locator('[data-testid=tool-toggle]').dispatchEvent('click');
    await confirmYes(page);
    await expect(card.locator('[data-testid=tool-state]')).toHaveText('Bu projede kapalı');
    await expect(page.locator('aside a[href="/panel/merhaba"], [data-testid=section-tabs] a[href="/panel/merhaba"]')).toHaveCount(0);
    await expect(card.locator('[data-testid=tool-toggle]')).toBeEnabled();
    await card.locator('[data-testid=tool-toggle]').dispatchEvent('click');
    await expect(card.locator('[data-testid=tool-state]')).toHaveText('Bu projede açık');
    await openFromMenu(page, 'content', '/panel/merhaba');
    await expect(page.locator('[data-testid=tool-problem]')).toHaveCount(0);
    expect(errors()).toEqual([]);
});
