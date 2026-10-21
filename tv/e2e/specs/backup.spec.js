/**
 * Ayarlar > Yedekleme > Otomatik yedek: aç, şimdi yedekle (arka planda), listede zip, indir; geri yüklemede
 * kişisel kayıtlar kutusu, uzak depoda yedek şifresi.
 * Zip'ten geri yükleme ve S3 uyumlu depo backend testlerinde (AutoBackupTest).
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login } from '../helpers.js';

test('otomatik yedek: aç, şimdi yedekle, indir', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await login(page);
    await page.goto('/panel/sistem-ayarlari/yedekleme');
    const card = page.locator('[data-testid=auto-backup]');
    // Ayarlar yüklenince (düğmeler görünür) tıklanır; önce tıklanırsa yüklenen ayar tıklamayı ezer.
    await expect(card.locator('[data-testid=auto-backup-save]')).toBeVisible();
    await expect(card.locator('[data-testid=auto-backup-last]')).toContainText('Henüz otomatik yedek alınmadı');

    try {
        await card.locator('label.pn-switch').click();
        await card.locator('[data-testid=auto-backup-save]').click();
        await expect.poll(async () => (await api.get('/admin/backups/auto')).json.enabled).toBe(true);

        await card.locator('[data-testid=auto-backup-run]').click();
        await expect(card.locator('[data-testid=auto-backup-list] li').first()).toBeVisible({ timeout: 60000 });
        await expect(card.locator('[data-testid=auto-backup-last]')).toContainText('Son yedek');

        const download = page.waitForEvent('download');
        await card.locator('[data-testid=auto-backup-list] li button:has-text("İndir")').first().click();
        expect((await download).suggestedFilename()).toMatch(/^\d{8}-\d{6}-panel(-\d+)?\.zip$/);

        // Geri yüklemede kişisel kayıtlar (talepler…) ayrıca seçilir; kutu işaretsiz gelir.
        await card.locator('[data-testid=auto-backup-list] li button:has-text("Geri yükle")').first().click();
        await expect(page.locator('[data-testid=confirm-option]')).not.toBeChecked();
        await expect(page.getByText('Kişisel kayıtları da geri yükle')).toBeVisible();
        await page.getByRole('button', { name: 'Vazgeç' }).click();

        // Uzak depo açılınca yedek şifresi alanı görünür (uzak kopya şifrelenir).
        await card.locator('[data-testid=auto-backup-remote]').check();
        await expect(card.locator('[data-testid=auto-backup-passphrase]')).toBeVisible();
    } finally {
        await api.put('/admin/backups/auto', { enabled: false });
    }
    expect(errors()).toEqual([]);
});
