/**
 * Ayarlar > SMS ve Kullanıcılar'dan SMS / e-posta. Test ortamında SMS sağlayıcısı "günlüğe yaz"
 * (gönderilmez), e-posta MAIL_MAILER=log.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, stamp, requires } from '../helpers.js';

test('SMS ve e-posta: ayar, deneme, kullanıcıya ve topluca gönderim', async ({ page, request }) => {
    await requires(request, { modules: ['sms'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const tag = stamp();
    const people = [];
    for (const [name, phone, email] of [[`Ayşe ${tag}`, '0532 111 11 11', `ayse-${tag}@example.com`], [`Burak ${tag}`, '', `burak-${tag}@example.com`]]) {
        const res = await api.post('/users', { username: `u${tag}${people.length}`, display_name: name, phone, email, role: 'editor', password: `Sifre-${tag}-2026` });
        expect(res.status, name).toBe(201);
        people.push(res.json);
    }

    // SMS kapalıyken Kullanıcılar'da SMS düğmesi yok.
    expect((await api.put('/system/sms', { provider: 'off' })).status).toBe(200);
    await login(page);
    await page.goto(`/panel/kullanicilar/${people[0].id}`);
    await expect(page.locator('[data-testid=user-mail]')).toBeVisible();
    await expect(page.locator('[data-testid=user-sms]')).toBeHidden();

    // Ayarlar > SMS: deneme yöntemi, deneme gönderimi kayda düşer (numara maskeli).
    await page.goto('/panel/sistem-ayarlari/sms');
    await page.selectOption('[data-testid=sms-provider]', 'log');
    await page.click('[data-testid=sms-save]');
    await expect(page.locator('[aria-live=polite] [role=status]').filter({ hasText: 'SMS ayarları kaydedildi' }).first()).toBeVisible();
    await page.fill('[data-testid=sms-test-to]', '0532 999 88 77');
    await page.click('[data-testid=sms-test]');
    const first = page.locator('[data-testid=sms-log] li').first();
    await expect(first).toContainText('Gitti');
    await expect(first).toContainText('532*****77');

    // NetGSM seçilince alanlar doğrulanır; şifre panele dönmez.
    await page.selectOption('[data-testid=sms-provider]', 'netgsm');
    await page.fill('[data-testid=sms-username]', '');
    await page.click('[data-testid=sms-save]');
    await expect(page.locator('[data-testid=sms-username] ~ .field-error')).toContainText('kullanıcı adını');
    await page.selectOption('[data-testid=sms-provider]', 'log');
    await page.click('[data-testid=sms-save]');

    // Kullanıcının yan panelinden SMS: sayaç, emoji uyarısı, gönderim.
    await page.goto(`/panel/kullanicilar/${people[0].id}`);
    await page.click('[data-testid=user-sms]');
    const compose = page.locator('[data-testid=compose]');
    await compose.locator('[data-testid=compose-message]').fill('Toplantı yarın 10:00’da 😀');
    await expect(compose).toContainText('gönderilemeyen karakter');
    await expect(compose.locator('[data-testid=compose-send]')).toBeDisabled();
    await compose.locator('[data-testid=compose-message]').fill('Toplantı yarın 10:00’da.');
    await expect(compose.locator('[data-testid=compose-count]')).toContainText('1 SMS');
    await compose.locator('[data-testid=compose-send]').click();
    await expect(compose).toBeHidden();
    const log = (await api.get('/system/sms')).json.log;
    expect(log[0]).toMatchObject({ name: people[0].display_name, recipient: '532*****11', kind: 'user.message', status: 'sent' });

    // Yan panelden e-posta: hemen gider, kayda düşer.
    await page.click('[data-testid=user-mail]');
    await compose.locator('[data-testid=compose-subject]').fill(`Duyuru ${tag}`);
    await compose.locator('[data-testid=compose-message]').fill('Merhaba,\n\nPanel bu akşam bakımda.');
    await compose.locator('[data-testid=compose-send]').click();
    await expect(compose).toBeHidden();
    await expect.poll(async () => (await api.get('/system/mail')).json.log.filter((r) => r.subject === `Duyuru ${tag}`).map((r) => `${r.recipient}:${r.status}`)).toEqual([`${people[0].email}:sent`]);

    // Toplu SMS: telefonu olmayan atlanır (pencerede söylenir).
    await page.goto(`/panel/kullanicilar?q=${tag}`);
    await expect(page.getByRole('cell', { name: `Burak ${tag}` })).toBeVisible();
    await page.click('[data-testid=grid-select]');
    await page.click('[data-testid=grid-select-all]');
    await expect(page.locator('[data-testid=grid-selected-count]')).toHaveText('2 kayıt seçildi');
    await page.click('[data-testid=grid-bulk-menu]');
    await page.click('[data-bulk=sms]');
    await expect(compose).toContainText(`Telefonu yok, gönderilmeyecek: Burak ${tag}`);
    await compose.locator('[data-testid=compose-message]').fill('Toplu deneme.');
    await compose.locator('[data-testid=compose-send]').click();
    await expect(compose).toBeHidden();
    expect((await api.get('/system/sms')).json.log[0]).toMatchObject({ name: people[0].display_name, status: 'sent' });

    expect((await api.put('/system/sms', { provider: 'off' })).status).toBe(200);
    for (const p of people) await api.post(`/users/${p.id}/deactivate`);
    expect(errors()).toEqual([]);
});
