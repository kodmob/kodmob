/**
 * Panel araması (üst çubuk): kısayol, ayara gidip alanı vurgulama, ayar kaydındaki ayar,
 * sunucudaki kayıt, Türkçe karakterden bağımsız eşleşme, son açılanlar, mobil katman.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, createProject, login, stamp, requires } from '../helpers.js';

test('panel araması: sayfa, ayar ve kayıt', async ({ page, request }) => {
    await requires(request, { types: ['projects'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const tag = stamp();
    const project = await createProject(api, { title: `Arama Projesi ${tag}`, slug: `arama-${tag}` });

    await login(page);
    const input = page.locator('[data-testid=search-input]');
    const results = page.locator('[data-search-list=desktop]');

    // Ctrl+K → kutu; panel formundaki ayar → sayfa ve sekme açılır, bölüm vurgulanır (SMTP seçili değil: üst bölüm).
    await page.keyboard.press('Control+k');
    await expect(input).toBeFocused();
    await input.fill('smtp sunucu');
    const smtp = results.locator('[data-search-key="field:mail.host"]');
    await expect(smtp).toContainText('Sistem Ayarları › E-posta');
    await expect(smtp.locator('mark').first()).toHaveText(/SMTP/i);
    await input.press('Enter');
    await expect(page).toHaveURL(/\/panel\/sistem-ayarlari\/e-posta$/);
    // Vurgu (pn-spotlight) 2,4 sn sürer, yavaş çalışmada kaçabilir: kalıcı olan odak denetlenir (bölümün ilk alanı).
    await expect(page.locator('[data-setting="mail"]').locator('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])').first()).toBeFocused({ timeout: 12000 });
    await expect(input).toHaveValue('');

    // Ayar kaydındaki ayar (Talepler grubu): Proje Ayarları'nda alan ortalanır, odak alanda.
    await input.click();
    await input.fill('bildirim e-postasi');
    const notify = results.locator('[data-search-key="set:brief.notify_email"]');
    await expect(notify).toContainText('Proje Ayarları › Talepler');
    await notify.click();
    await expect(page).toHaveURL(/\/panel\/proje-ayarlari\?grup=talepler$/, { timeout: 12000 });
    const field = page.locator('[data-setting="brief.notify_email"]');
    await expect(field).toHaveClass(/pn-spotlight/, { timeout: 12000 });
    await expect(field.locator('input')).toBeFocused();

    // Türkçe karakter yazmadan: "iki adimli" → İki adımlı doğrulama (Profilim).
    await input.click();
    await input.fill('iki adimli');
    await expect(results.locator('[data-search-key="field:profile.two_factor"]')).toContainText('İki adımlı doğrulama');

    // Sunucudaki kayıt: proje başlığı → düzenleyici.
    await input.fill(`Arama Projesi ${tag}`);
    const hit = results.locator(`[data-search-key="projects:${project.id}"]`);
    await expect(hit).toBeVisible();
    await hit.click();
    await expect(page).toHaveURL(new RegExp(`/panel/projeler/${project.id}$`));

    // Boş kutu: son açılanlar; Esc kapatır.
    await page.keyboard.press('Control+k');
    await expect(results).toContainText('Son açılanlar');
    await expect(results).toContainText(`Arama Projesi ${tag}`);
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-testid=search-results]')).toBeHidden();

    // "/" kısayolu (yazı alanında değilken) ve sonuç yok durumu.
    await page.locator('main').click({ position: { x: 5, y: 5 } });
    await page.keyboard.press('/');
    await expect(input).toBeFocused();
    await input.fill('qqzzxxnone');
    await expect(results).toContainText('için sonuç yok', { timeout: 6000 });
    await page.keyboard.press('Escape');

    // Mobil: büyüteç → tam ekran katman.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.click('[data-testid=search-open]');
    const mobileInput = page.getByRole('dialog', { name: 'Panelde ara' }).locator('input[type=search]');
    await expect(mobileInput).toBeFocused();
    await mobileInput.fill('sms saglayici');
    await page.locator('[data-search-list=mobile] [data-search-key="field:sms.provider"]').click();
    await expect(page).toHaveURL(/\/panel\/sistem-ayarlari\/sms$/);
    await expect(page.locator('[data-setting="sms.provider"]')).toHaveClass(/pn-spotlight/, { timeout: 12000 });
    expect(errors()).toEqual([]);
});
