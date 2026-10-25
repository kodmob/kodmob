/**
 * Uçtan uca test ortamı: yerel siteden tamamen ayrı (`<proje>/.e2e/`).
 *
 *   .e2e/public_html/      derlenmiş site + api/ (yerel dağıtım betiğiyle, LOCAL_ROOT=.e2e)
 *   .e2e/kodmob-core       → kodmob-core (bağlantı; kod aynı)
 *   .e2e/storage/          Laravel depolaması (LARAVEL_STORAGE_PATH): sayfa önbelleği, dil kaydı kopyası
 *   .e2e/database.sqlite   her çalıştırmada sıfırdan (kodmob:e2e-reset)
 *   .e2e/server.log        PHP sunucusunun çıktısı
 *
 * Yerel MySQL'e ve .local'e dokunulmaz: veritabanı, depolama ve site kökü ortam değişkenleriyle
 * değişir (.env'in üzerine geçer); sıfırlama komutu da yalnızca .e2e SQLite dosyasında çalışır.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const TV = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const PROJECT = path.resolve(TV, '..');
export const API = path.join(PROJECT, 'kodmob-core');
export const ROOT = path.resolve(process.env.E2E_ROOT || path.join(PROJECT, '.e2e'));
export const SITE = path.join(ROOT, 'public_html');
export const STORAGE = path.join(ROOT, 'storage');
export const DB = path.join(ROOT, 'database.sqlite');
export const ROUTER = path.join(API, 'dev', 'router.php');
export const PORT = process.env.E2E_PORT || '8091';
export const BASE = `http://127.0.0.1:${PORT}`;

const MAMP_PHP = 'C:/MAMP/bin/php/php8.4.1/php.exe';
export const PHP = process.env.KM_PHP || (fs.existsSync(MAMP_PHP) ? MAMP_PHP : 'php');

/** PHP süreçlerinin ortamı: ayrı veritabanı, depolama ve site kökü; e-posta gönderilmez (log). */
export function phpEnv() {
    return {
        ...process.env,
        APP_ENV: 'e2e',
        APP_DEBUG: 'true',
        APP_URL: BASE,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: DB,
        DB_URL: '',
        DB_BUSY_TIMEOUT: '10000',
        DB_JOURNAL_MODE: 'wal',
        SITE_ROOT: SITE,
        MEDIA_ROOT: path.join(SITE, 'media'),
        LARAVEL_STORAGE_PATH: STORAGE,
        CACHE_STORE: 'file',
        QUEUE_CONNECTION: 'database',
        MAIL_MAILER: 'log',
        LOG_CHANNEL: 'single',
        LOCAL_ROOT: ROOT,
        KM_SKIP_I18N: '1',
    };
}
