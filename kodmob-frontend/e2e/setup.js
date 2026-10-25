/**
 * Uçtan uca testlerin kurulumu (Playwright globalSetup): siteyi .e2e'ye derleyip dağıtır, test
 * veritabanını sıfırlar, PHP sunucusunu başlatır. Dönen işlev testlerden sonra sunucuyu kapatır.
 * Yönetici `e2e`; şifresi her çalıştırmada rastgele (process.env.E2E_PASSWORD, dosyaya yazılmaz).
 *
 * E2E_BUILD=0: derlemeden, eldeki dist ile (npm run e2e -- --no-build).
 */
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { startPool } from '../scripts/php-pool.mjs';
import { API, BASE, DB, PHP, PORT, ROOT, ROUTER, SITE, STORAGE, TV, phpEnv } from './env.js';

const log = (message) => console.log(`[e2e] ${message}`);

async function waitForServer(url, timeout = 30000) {
    const until = Date.now() + timeout;
    while (Date.now() < until) {
        try {
            if ((await fetch(url)).ok) return;
        } catch {
            /* henüz açılmadı */
        }
        await new Promise((resolve) => setTimeout(resolve, 300));
    }
    throw new Error(`Sunucu açılmadı: ${url} (${path.join(ROOT, 'server.log')})`);
}

export default async function globalSetup() {
    fs.mkdirSync(ROOT, { recursive: true });
    for (const dir of ['app/backups', 'framework/cache/data', 'framework/views', 'framework/pages', 'framework/i18n', 'framework/seo', 'logs']) {
        fs.mkdirSync(path.join(STORAGE, dir), { recursive: true });
    }
    fs.rmSync(path.join(STORAGE, 'framework', 'pages'), { recursive: true, force: true });
    fs.mkdirSync(path.join(STORAGE, 'framework', 'pages'), { recursive: true });
    fs.rmSync(DB, { force: true });
    fs.writeFileSync(DB, '');
    // Çok site: önceki çalıştırmanın alt siteleri (kayıt ve depolama, veritabanı dosyaları dahil).
    fs.rmSync(path.join(STORAGE, 'sites'), { recursive: true, force: true });
    fs.rmSync(path.join(STORAGE, 'sites.json'), { force: true });

    log(`site → ${SITE}`);
    execFileSync(process.execPath, [path.join(TV, 'scripts', 'deploy-local.mjs'), ...(process.env.E2E_BUILD === '0' ? ['--no-build'] : [])], { cwd: TV, env: phpEnv(), stdio: 'inherit' });

    const password = crypto.randomBytes(18).toString('base64url');
    execFileSync(PHP, ['artisan', 'kodmob:e2e-reset', `--password=${password}`], { cwd: API, env: phpEnv(), stdio: 'inherit' });
    process.env.E2E_PASSWORD = password;

    // Önde vekil, arkada 4 PHP süreci (scripts/php-pool.mjs): tek iş parçacıklı sunucu takılmasın.
    const out = fs.openSync(path.join(ROOT, 'server.log'), 'w');
    const pool = await startPool({ php: PHP, port: PORT, root: SITE, router: ROUTER, env: phpEnv(), workers: 4, stdio: out, logFile: path.join(ROOT, 'requests.log') });
    await waitForServer(`${BASE}/api/v1/`);
    log(`sunucu: ${BASE} (4 PHP süreci)`);

    return async () => {
        pool.close();
    };
}
