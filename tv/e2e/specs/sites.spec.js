/**
 * Siteler (çok site): ana panelden alt site kurulur; alt sitenin alan adı (Chrome'da *.localhost
 * bilgisayarın kendisidir) aynı sunucuda kendi paneli, kullanıcısı ve verisiyle açılır; ana sitenin
 * hesabı ve jetonu orada geçmez. Sonunda kayıttan çıkarılır.
 */
import { test, expect } from '@playwright/test';
import { apiClient, confirmYes, consoleErrors, login, stamp, requires } from '../helpers.js';
import { PORT } from '../env.js';

test('alt site: panelden kurulur, kendi alan adında ayrı panel ve veriyle açılır', async ({ page, request, browser }) => {
    await requires(request, { modules: ['sites'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const id = stamp();
    const domain = `alt-${id}.localhost`;
    const admin = { username: `alt-${id}`, password: `alt-sifre-${id}-x` };

    await login(page);
    await page.goto('/panel/siteler');
    await page.click('[data-testid=site-new]');
    await page.fill('[data-testid=site-name]', `E2E Alt Site ${id}`);
    await expect(page.locator('[data-testid=site-key]')).toHaveValue(`e2e-alt-site-${id}`);
    await page.fill('[data-testid=site-domains]', `https://${domain}/`);
    await page.locator('[data-module=jarvis]').uncheck();
    await page.fill('[data-testid=site-admin-username]', admin.username);
    // Yöneticinin e-postası ana projedekiyle aynı: proje seçiciden tek oturumla geçiş (Handoff) bu hesaba açılır.
    await page.fill('[data-testid=site-admin-email]', 'e2e@kodmob.test');
    await page.fill('[data-testid=site-admin-password]', admin.password);
    await page.click('[data-testid=site-save]');
    await expect(page.locator('[data-testid=site-created]')).toContainText('kuruldu', { timeout: 60000 });
    await page.locator('[data-testid=site-dialog] button[type=submit]').click();
    const card = page.locator(`[data-site="e2e-alt-site-${id}"]`);
    await expect(card.locator('[data-testid=site-status]')).toHaveText('Hazır');
    await expect(card).toContainText(domain);
    // Panel bu bilgisayarda: "Yerel deneme adresi" kutusu işaretli gelir, anahtar.localhost da eklenir.
    await expect(card).toContainText(`e2e-alt-site-${id}.localhost`);

    // Proje seçici (menünün üstü): yeni proje listede, paneli kendi adresinde.
    await page.reload();
    const switcher = page.locator('aside [data-testid=project-switcher]').first();
    await switcher.locator('button').first().click();
    // Yerelde (standart dışı bağlantı noktası) alan adı bu isteğin şeması ve portuyla; canlıda kayıttaki adres.
    await expect(switcher.getByRole('menuitem', { name: `E2E Alt Site ${id}` })).toHaveAttribute('href', `http://${domain}:${PORT}/panel/`);
    await expect(switcher.locator('[data-testid=project-new]')).toBeVisible();
    await page.keyboard.press('Escape');

    // Alt sitenin alan adı: kendi paneli ve kullanıcısı; ana sitenin hesabı geçmez.
    const child = `http://${domain}:${PORT}`;
    const context = await browser.newContext({ baseURL: child });
    const other = await context.newPage();
    // Ana sitenin hesabıyla deneme reddedilir (403); o istek hatası beklenen.
    const childErrors = consoleErrors(other, /status of (401|403|404)/);
    await other.goto('/panel/');
    await other.fill('#login-user', 'e2e');
    await other.fill('#login-pass', process.env.E2E_PASSWORD);
    await other.click('button[type=submit]');
    await expect(other.locator('#login-user')).toHaveAttribute('aria-invalid', 'true');
    await other.fill('#login-user', admin.username);
    await other.fill('#login-pass', admin.password);
    await other.click('button[type=submit]');
    await expect(other.locator('aside nav a[href="/panel/"]').first()).toBeVisible({ timeout: 20000 });
    await expect(other.locator('aside nav a[href="/panel/siteler"]')).toHaveCount(0);
    // Alt projede seçici yalnızca kendi adını gösterir (diğer projeler ve yeni proje yok).
    await other.locator('aside [data-testid=project-switcher] button').first().click();
    await expect(other.locator('aside [data-testid=project-switcher] [data-testid=project-new]')).toHaveCount(0);
    await other.keyboard.press('Escape');
    await expect(other.locator('aside nav a[href="/panel/jarvis"]')).toHaveCount(0);
    // E-posta, SMS ve yapay zekâ ana projeden: alt projenin Sistem Ayarları'nda sekmeleri yok, notu var.
    await other.goto('/panel/sistem-ayarlari');
    await expect(other.locator('[data-testid=settings-shared]')).toBeVisible();
    await expect(other.locator('.pn-tab[data-tab=mail]')).toBeHidden();
    await expect(other.locator('.pn-tab[data-tab=backup]')).toBeVisible();

    // Ana sitenin jetonu alt sitede geçmez; alt sitenin sayfaları kendi adresiyle (ortak tema).
    const token = (await (await request.post('/api/v1/auth/token', { data: { username: 'e2e', password: process.env.E2E_PASSWORD } })).json()).token;
    // (Node *.localhost adını çözmez; istekler alt sitenin sayfasından, tarayıcıda.)
    expect(await other.evaluate((t) => fetch('/api/v1/me', { headers: { Authorization: `Bearer ${t}` } }).then((r) => r.status), token)).toBe(401);
    const html = await other.evaluate(() => fetch('/').then((r) => r.text()));
    expect(html).toContain(`<link rel="canonical" href="https://${domain}/">`);
    expect(childErrors()).toEqual([]);
    await context.close();

    // Tek oturumla geçiş: proje seçicide alt projeye basınca orada yeniden giriş yapmadan panel açılır; adreste anahtar kalmaz.
    const desk = page.locator('aside [data-testid=project-switcher]').first();
    await desk.locator('button').first().click();
    await desk.locator(`[data-project="e2e-alt-site-${id}"]`).click();
    await page.waitForURL((url) => url.host === `${domain}:${PORT}` && url.pathname.startsWith('/panel/'));
    await expect(page.locator('aside nav a[href="/panel/"]').first()).toBeVisible({ timeout: 20000 });
    expect(page.url()).not.toContain('gecis=');
    await expect(page.locator('#login-user')).toHaveCount(0);
    await page.goto('/panel/siteler');

    // Kayıttan çıkar: alan adı ana siteyi açar.
    await card.getByRole('button', { name: 'Kayıttan çıkar' }).click();
    await confirmYes(page);
    await expect(card).toHaveCount(0);
    expect((await api.get('/system/sites')).json.sites.map((s) => s.key)).not.toContain(`e2e-alt-site-${id}`);
    expect(errors()).toEqual([]);
});
