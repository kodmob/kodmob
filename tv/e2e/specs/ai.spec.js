/**
 * Ayarlar > Yapay zekâ: varsayılan Claude, sağlayıcı anahtarı ve modeli, etkin sağlayıcı değişimi,
 * anahtarın panele dönmemesi; Jarvis'ten ve aramadan ulaşım. Gerçek API'ye istek atılmaz.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, requires } from '../helpers.js';

const OPENAI_KEY = 'sk-proj-e2e-0123456789abcdefghijkl';

test('yapay zekâ: sağlayıcılar, anahtar, model, etkin sağlayıcı', async ({ page, request }) => {
    await requires(request, { modules: ['jarvis'] });
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    await login(page);

    // Jarvis'teki "Yapay zekâ ayarları" Sistem Ayarları > Yapay zekâ'ya götürür.
    await page.goto('/panel/jarvis');
    await page.click('[data-testid=jarvis-ai-settings]');
    await expect(page).toHaveURL(/\/panel\/sistem-ayarlari\/yapay-zeka$/);

    const card = (key) => page.locator(`[data-testid=ai-card-${key}]`);
    await expect(page.locator('[data-testid=ai-provider-anthropic]')).toBeChecked();
    await expect(card('openai')).toContainText('Anahtar yok');
    await expect(page.locator('[data-testid=ai-model-google]')).toHaveValue('gemini-3.8-flash');

    // OpenAI anahtarı + model + etkin sağlayıcı.
    await page.fill('[data-testid=ai-key-openai]', OPENAI_KEY);
    await page.fill('[data-testid=ai-model-openai]', 'gpt-6-luna');
    await page.check('[data-testid=ai-provider-openai]');
    await page.click('[data-testid=ai-save]');
    await expect(card('openai')).toContainText('Anahtar kayıtlı');
    await expect(card('openai')).toContainText('Etkin');
    await expect(page.locator('[data-testid=ai-key-openai]')).toHaveValue('');
    await expect(page.locator('[data-testid=ai-key-openai]')).toHaveAttribute('placeholder', /…ijkl/);

    const view = (await api.get('/system/ai')).json;
    expect(view.provider).toBe('openai');
    expect(JSON.stringify(view)).not.toContain(OPENAI_KEY);

    // Arama: "gemini anahtar" → Google kartı vurgulanır.
    await page.locator('[data-testid=search-input]').fill('gemini anahtar');
    await page.locator('[data-search-list=desktop] [data-search-key="field:ai.google"]').click();
    await expect(page.locator('[data-setting="ai.google"]')).toHaveClass(/pn-spotlight/, { timeout: 12000 });

    // Eski hale: Claude etkin, OpenAI anahtarı yok.
    expect((await api.put('/system/ai', { provider: 'anthropic', providers: { openai: { clear_key: true, model: 'gpt-6.1-sol' } } })).status).toBe(200);
    expect(errors()).toEqual([]);
});
