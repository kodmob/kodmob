/**
 * Bülten (araç newsletter; kodmob-api Modules\Newsletter): Araç Pazarı'ndan derlemesiz eklenir; sitedeki Bülten
 * sayfasından abone olunur (çift onay: onay bağlantısı e-postada; burada veritabanından okunur), panelde bülten
 * yazılıp kendime deneme ve gönderim (cron yok: sayfa açıkken parça parça), abone tabloda; abonelikten çıkma
 * düğmeyle. Sonunda araç çıkarılır (sayfa 404).
 */
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { confirmYes, consoleErrors, login, stamp, openFromMenu } from '../helpers.js';
import { DB, PHP } from '../env.js';

/** Abonenin onay / çıkma anahtarı (e-postadaki bağlantının parçası). */
const tokenOf = (email) => execFileSync(PHP, ['-r', '$db = new PDO("sqlite:" . $argv[1]); $q = $db->prepare("select token from newsletter_subscribers where email = ?"); $q->execute([$argv[2]]); echo $q->fetchColumn();', '--', DB, email]).toString().trim();

test('Bülten: ekle, abone ol ve onayla, bülten gönder, abonelikten çık, çıkar', async ({ page }) => {
    test.setTimeout(120000);
    const errors = consoleErrors(page);
    const tag = stamp();
    const email = `bulten-${tag}@example.com`;
    await login(page);
    expect((await page.request.get('/bulten/')).status()).toBe(404);

    await page.goto('/panel/araclar/kesfet');
    await page.locator('[data-testid=tools] [data-tool=newsletter] [data-testid=tool-install]').click();
    await openFromMenu(page, 'growth', '/panel/bulten');

    // Ziyaretçi: Bülten sayfası (bölgenin ilk bloğu Bülten aboneliği); onay kutusu zorunlu.
    await page.goto('/bulten/');
    const form = page.locator('[data-km-block=newsletter] form');
    await form.locator('input[name=email]').fill(email);
    await form.locator('button[type=submit]').click();
    await expect(form.locator('[data-error=consent]')).toBeVisible();
    // Bot denetimi: form açıldıktan 3 sn sonra gönderilir.
    await page.waitForTimeout(3100);
    await form.locator('input[name=consent]').check();
    await form.locator('button[type=submit]').click();
    await expect(page.locator('[data-km-block=newsletter] [data-newsletter-done]')).toBeVisible();

    await expect.poll(() => tokenOf(email)).toMatch(/^[a-f0-9]{48}$/);
    const token = tokenOf(email);
    await page.goto(`/bulten/?confirm=${token}`);
    await expect(page.locator('[data-testid=newsletter-state]')).toHaveAttribute('data-state', 'active');
    expect(page.url()).not.toContain(token);

    // Panel: bülten yaz, kendime deneme, gönder (cron yok: sayfa bir parça gönderir), ilerleme.
    await page.goto('/panel/bulten');
    await page.click('[data-testid=campaign-new]');
    const modal = page.locator('[data-testid=campaign-form]');
    await modal.locator('#campaign-subject').fill(`Ekim bülteni ${tag}`);
    await modal.locator('.ProseMirror').click();
    await page.keyboard.type('Merhaba, bu ayın yenilikleri.');
    await modal.locator('[data-testid=campaign-test]').click();
    await expect(page.locator('.toast, [role=status]').filter({ hasText: 'Deneme' }).first()).toBeVisible();
    await modal.locator('[data-testid=campaign-save-send]').click();
    await confirmYes(page);
    const card = page.locator('[data-testid=campaign]').filter({ hasText: `Ekim bülteni ${tag}` });
    await expect(card.locator('[data-testid=campaign-status]')).toHaveText('Gönderildi', { timeout: 30000 });
    await expect(card.locator('[data-testid=campaign-sent]')).toHaveText('1');

    await page.locator('[data-testid=newsletter] [data-tab=subscribers]').click();
    await expect(page).toHaveURL(/\/panel\/bulten\/aboneler/);
    await expect(page.locator('[data-testid=newsletter]').getByText(email).first()).toBeVisible();

    // Abonelikten çıkma: bağlantı sorar, düğmeyle çıkar.
    await page.goto(`/bulten/?unsubscribe=${token}`);
    await expect(page.locator('[data-testid=newsletter-state]')).toHaveAttribute('data-state', 'ask');
    await page.click('[data-testid=newsletter-unsubscribe]');
    await expect(page.locator('[data-testid=newsletter-state]')).toHaveAttribute('data-state', 'unsubscribed');
    await page.goto('/bulten/?confirm=0000');
    await expect(page.locator('[data-testid=newsletter-invalid]')).toBeVisible();

    // Aracı çıkar: sayfa 404 (veri kalır).
    await page.goto('/panel/araclar');
    await page.locator('[data-tool=newsletter] [data-testid=tool-uninstall]').click();
    await confirmYes(page);
    await expect(page.locator('[data-tool=newsletter] [data-testid=tool-uninstall]')).toHaveCount(0);
    expect((await page.request.get('/bulten/')).status()).toBe(404);
    expect(errors()).toEqual([]);
});
