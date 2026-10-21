/**
 * Uçtan uca testler (Playwright). Çalıştırma: `npm run e2e` (derler, .e2e'ye dağıtır, test eder).
 * Ortam ve güvenlik: e2e/env.js; kurulum: e2e/setup.js; senaryolar: e2e/specs/.
 *
 * Tarayıcı: kurulu Chrome (`channel: 'chrome'`; indirme gerekmez). CI'da E2E_CHANNEL boş
 * bırakılırsa Playwright'ın Chromium'u kullanılır (`npx playwright install chromium`).
 * Yerel PHP sunucusu tek iş parçacıklıdır: testler sırayla çalışır.
 */
import path from 'node:path';
import { defineConfig } from '@playwright/test';
import { BASE, ROOT } from './env.js';

const channel = process.env.E2E_CHANNEL ?? 'chrome';

export default defineConfig({
    testDir: './specs',
    globalSetup: './setup.js',
    outputDir: path.join(ROOT, 'results'),
    workers: 1,
    fullyParallel: false,
    timeout: 90_000,
    expect: { timeout: 15_000 },
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never', outputFolder: path.join(ROOT, 'report') }]] : 'list',
    use: {
        baseURL: BASE,
        ...(channel ? { channel } : {}),
        viewport: { width: 1360, height: 1000 },
        locale: 'tr-TR',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
