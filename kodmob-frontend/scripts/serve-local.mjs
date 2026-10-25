/**
 * Yerel test sitesini çalıştırır: http://127.0.0.1:8090/ (önce `npm run deploy:local`).
 * Apache gerekmez; .htaccess kuralları kodmob-core/dev/router.php'dedir.
 *
 * Önde Node vekil, arkada KM_WORKERS (varsayılan 4) `php -S` süreci (scripts/php-pool.mjs):
 * PHP'nin yerleşik sunucusu Windows'ta tek iş parçacıklıdır ve Chrome'un boş bağlantılarında
 * takılır; havuzla panel paralel ve takılmadan yüklenir. KM_WORKERS=1: eskisi gibi tek süreç.
 *
 * PHP: KM_PHP (varsayılan C:/MAMP/bin/php/php8.4.1/php.exe, yoksa PATH'teki php).
 * Kök: LOCAL_ROOT (varsayılan <proje>/.local). Port: KM_PORT (varsayılan 8090).
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { startPool } from './php-pool.mjs';

const TV = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PROJECT = path.resolve(TV, '..');
const ROOT = path.resolve(process.env.LOCAL_ROOT || path.join(PROJECT, '.local'));
const SITE = path.join(ROOT, 'public_html');
const ROUTER = path.join(PROJECT, 'kodmob-core', 'dev', 'router.php');
const MAMP_PHP = 'C:/MAMP/bin/php/php8.4.1/php.exe';
const PHP = process.env.KM_PHP || (fs.existsSync(MAMP_PHP) ? MAMP_PHP : 'php');
const PORT = process.env.KM_PORT || '8090';
const WORKERS = Math.max(1, Number(process.env.KM_WORKERS || 4));

if (!fs.existsSync(path.join(SITE, 'api', 'index.php'))) {
    console.error(`[serve] ${SITE} hazır değil; önce: npm run deploy:local`);
    process.exit(1);
}

if (WORKERS === 1) {
    console.log(`[serve] http://127.0.0.1:${PORT}/  (kök: ${SITE}, tek süreç)`);
    const child = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, '-t', SITE, ROUTER], { stdio: 'inherit' });
    child.on('exit', (code) => process.exit(code ?? 0));
} else {
    const pool = await startPool({ php: PHP, port: PORT, root: SITE, router: ROUTER, workers: WORKERS, stdio: 'inherit' });
    console.log(`[serve] http://127.0.0.1:${PORT}/  (kök: ${SITE}, ${WORKERS} PHP süreci)`);
    const stop = () => {
        pool.close();
        process.exit(0);
    };
    process.on('SIGINT', stop);
    process.on('SIGTERM', stop);
}
