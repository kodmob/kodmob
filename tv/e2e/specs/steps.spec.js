/**
 * Genel bakış › Sıradaki adımlar (kodmob-api Core\Setup\Onboarding): sırayla adımlar, sıradakinin düğmesi o ekranı
 * açar, yapılan adım kendiliğinden işaretlenir; kart gizlenip yeniden gösterilir.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login } from '../helpers.js';

test('sıradaki adımlar: sırayla, ilgili ekrana götürür, yapılınca işaretlenir', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await login(page);
    const card = page.locator('[data-testid=next-steps]');
    await expect(card).toBeVisible();
    await expect(card.locator('[data-step]')).toHaveCount((await api.get('/admin/overview')).json.steps.length);

    // E-posta adımı Sistem Ayarları › E-posta'yı açar.
    await card.locator('[data-step=mail] a').click();
    await expect(page).toHaveURL(/\/panel\/sistem-ayarlari\/e-posta$/);

    // Otomatik yedek açılınca adım işaretlenir.
    const before = (await api.get('/admin/overview')).json.steps.find((s) => s.key === 'backup').done;
    await api.put('/admin/backups/auto', { enabled: true });
    try {
        await page.goto('/panel/');
        await expect(card.locator('[data-step=backup]')).toHaveClass(/is-done/);
    } finally {
        if (!before) await api.put('/admin/backups/auto', { enabled: false });
    }

    // Gizle / göster (bu tarayıcıda hatırlanır).
    await card.locator('[data-testid=next-steps-hide]').click();
    await expect(card).toHaveCount(0);
    await page.reload();
    await expect(page.locator('[data-testid=next-steps-show]')).toBeVisible();
    await page.locator('[data-testid=next-steps-show]').click();
    await expect(card).toBeVisible();
    expect(errors()).toEqual([]);
});

test('Proje Ayarları: her grup bir sekme; adım ve ayar bağlantısı doğru sekmeyi açar', async ({ page }) => {
    const errors = consoleErrors(page);
    await login(page);
    await page.goto('/panel/proje-ayarlari');
    const tabs = page.locator('[data-testid=settings-groups]');
    await expect(tabs.locator('[data-group=kimlik][aria-selected=true]')).toBeVisible();
    await expect(page.locator('[data-setting="site.email"]')).toBeHidden();

    // Sekme: yalnızca o grubun alanları; adres ?grup= ile.
    await tabs.locator('[data-group=iletisim]').click();
    await expect(page).toHaveURL(/\?grup=iletisim$/);
    await expect(page.locator('[data-setting="site.email"]')).toBeVisible();
    await expect(page.locator('[data-setting="site.name"]')).toBeHidden();
    // Doğrudan bağlantı da aynı sekmeyi açar.
    await page.goto('/panel/proje-ayarlari?grup=iletisim');
    await expect(tabs.locator('[data-group=iletisim][aria-selected=true]')).toBeVisible();
    await expect(page).toHaveURL(/\?grup=iletisim$/);

    // Değişiklik sekmede işaretlenir, başka sekmeye geçince kaybolmaz.
    await page.fill('#set-site\\.phone', '0212 000 00 00');
    await tabs.locator('[data-group=kimlik]').click();
    await expect(tabs.locator('[data-group=iletisim]')).toContainText('●');
    await page.getByRole('button', { name: 'Vazgeç' }).click();

    // Sıradaki adım "İletişim ve yasal bilgiler" alanın kendisine götürür.
    await page.goto('/panel/');
    await page.locator('[data-testid=next-steps] [data-step=contact] a').click();
    await expect(page).toHaveURL(/\/panel\/proje-ayarlari\?grup=iletisim/);
    await expect(page.locator('[data-setting="site.email"]')).toHaveClass(/pn-spotlight/, { timeout: 12000 });
    expect(errors()).toEqual([]);
});
