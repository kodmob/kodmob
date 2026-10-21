/**
 * Ayarlar > E-posta ve Arka plan: deneme gönderimi ve kaydı, SMTP doğrulaması, şifrenin panele
 * dönmemesi; cron yokken talep e-postalarının yanıttan sonra gitmesi. Test ortamında e-posta
 * gönderilmez (MAIL_MAILER=log).
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, stamp } from '../helpers.js';

test('e-posta: deneme kayda düşer, SMTP alanları doğrulanır, şifre geri gelmez', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await login(page);
    await page.goto('/panel/sistem-ayarlari/e-posta');
    await expect(page.locator('[data-testid=mail-mailer]')).toHaveValue('env');

    await page.click('[data-testid=mail-test]');
    const first = page.locator('[data-testid=mail-log] li').first();
    await expect(first).toContainText('Gitti');
    await expect(first).toContainText('Deneme');

    await page.selectOption('[data-testid=mail-mailer]', 'smtp');
    await page.fill('[data-testid=mail-host]', '');
    await page.click('[data-testid=mail-save]');
    await expect(page.locator('[data-testid=mail-host] ~ .field-error')).toContainText('SMTP');

    await page.fill('[data-testid=mail-host]', 'smtp.olmayan-alan.invalid');
    await page.fill('[data-testid=mail-password]', 'deneme-sifre-123');
    await page.click('[data-testid=mail-save]');
    await expect(page.locator('[data-testid=mail-password]')).toHaveAttribute('placeholder', /kayıtlı/);
    await expect(page.locator('[data-testid=mail-password]')).toHaveValue('');
    const view = (await api.get('/system/mail')).json;
    expect(view.password_set).toBe(true);
    expect(view).not.toHaveProperty('password');

    expect((await api.put('/system/mail', { mailer: 'env', host: '', clear_password: true })).json.mailer).toBe('env');
    expect(errors()).toEqual([]);
});

test('arka plan: cron yokken talep e-postaları yanıttan sonra gider', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await login(page);
    await page.goto('/panel/sistem-ayarlari/arka-plan');
    await expect(page.locator('[data-testid=jobs-cron]')).toContainText('Hiç çalışmadı');

    const before = (await api.get('/system/mail')).json.log.length;
    const brief = await request.post('/api/v1/briefs', {
        data: { name: `E2E Talep ${stamp()}`, email: 'e2e@example.com', services: ['web'], message: 'Uçtan uca test talebi, lütfen dikkate almayın.', consent: true, locale: 'tr', started_at: Date.now() - 60000 },
    });
    expect(brief.status()).toBe(201);
    await expect.poll(async () => (await api.get('/system/mail')).json.log.length - before).toBe(2);
    const kinds = (await api.get('/system/mail')).json.log.slice(0, 2).map((r) => `${r.kind}:${r.status}`).sort();
    expect(kinds).toEqual(['brief.notify:sent', 'brief.reply:sent']);

    await page.goto('/panel/sistem-ayarlari/e-posta');
    await expect(page.locator('[data-testid=mail-log]')).toContainText('Talep bildirimi');
    await expect(page.locator('[data-testid=mail-log]')).toContainText('Talep yanıtı');
    expect(errors()).toEqual([]);
});
