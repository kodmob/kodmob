/**
 * SEO > Yapay zekâ: render.php yapay zekâ botlarını tanıyıp kaydeder (önbellekten sunulan sayfada da),
 * panel kaydı içeri alıp botları, sayfaları, denetimi ve içerik hazırlığını gösterir.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login } from '../helpers.js';

const GPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';
const CLAUDE = 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)';

test('yapay zekâ botları kaydedilir ve panelde görünür', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    // Derlenmiş SEO yapılandırması bot desenini taşısın (önceki çalıştırmadan eski dosya kalmış olabilir).
    expect((await api.put('/seo/robots', { extra: '' })).status).toBe(200);

    for (const _ of [1, 2]) expect((await request.get('/blog/', { headers: { 'User-Agent': GPT } })).status()).toBe(200);
    expect((await request.get('/', { headers: { 'User-Agent': CLAUDE } })).status()).toBe(200);
    await request.get('/hakkimizda/'); // sıradan ziyaretçi sayılmaz

    await login(page);
    await page.goto('/panel/seo/yapay-zeka');
    const gpt = page.locator('[data-testid=ai-bots] [data-bot="GPTBot"]');
    await expect(gpt.locator('td').nth(1)).toHaveText('2');
    await expect(page.locator('[data-testid=ai-bots] [data-bot="Claude-User"] td').nth(1)).toHaveText('1');
    await expect(page.locator('[data-testid=ai-visits]')).toHaveText('3');
    await expect(page.locator('[data-check="ssr"]')).toBeVisible();
    await expect(page.getByText('Yapay zekânın en çok okuduğu sayfalar')).toBeVisible();
    await expect(page.locator('[data-testid=seo-ai]')).toContainText('/blog/');
    expect(errors()).toEqual([]);
});
