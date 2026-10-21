/**
 * Yerel test sitesine dağıtım (canlıdaki yerleşimin aynısı):
 *
 *   <kök>/public_html/          ön yüz (dist) + api/ (Laravel giriş dosyaları) + media/
 *   <kök>/kodmob-core            → ../kodmob-api klasörüne bağlantı (junction; kod değişikliği anında geçer)
 *
 *   npm run deploy:local              → ön yüzü derler (build:dev) ve dağıtır
 *   npm run deploy:local -- --no-build → derlemeden, eldeki dist ile
 *   npm run serve:local               → http://127.0.0.1:8090 (php -S + kodmob-api/dev/router.php)
 *
 * Hedef: LOCAL_ROOT (varsayılan <proje>/.local). API ayarları kodmob-api/.env'de
 * (SITE_ROOT ve MEDIA_ROOT bu klasörü göstermeli).
 *
 * Güvenlik kuralları:
 * - Hedefte WordPress (api/wp-load.php) varsa hiçbir şey yapılmaz: eski kurulum
 *   elle yedeklenip kaldırılmadan üzerine yazılmaz.
 * - `media/` (yüklenen görseller) hiçbir zaman silinmez ya da yazılmaz.
 * - Kökte yalnızca bu betiğin bir önceki dağıtımda yazdığı dosyalar silinir
 *   (liste: `<kök>/public_html/.km-deploy.json`); elle konmuş dosyalara dokunulmaz.
 */
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const TV = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PROJECT = path.resolve(TV, '..');
const API = path.join(PROJECT, 'kodmob-api');
const ROOT = path.resolve(process.env.LOCAL_ROOT || path.join(PROJECT, '.local'));
const SITE = path.join(ROOT, 'public_html');
const CORE = path.join(ROOT, 'kodmob-core');
const MANIFEST = path.join(SITE, '.km-deploy.json');
const ENTRY_FILES = ['index.php', 'render.php', '.htaccess'];

const args = new Set(process.argv.slice(2));
const log = (message) => console.log(`[deploy] ${message}`);

function fail(message) {
    console.error(`[deploy] DURDURULDU: ${message}`);
    process.exit(1);
}

function listFiles(dir, base = dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        return entry.isDirectory() ? listFiles(full, base) : [path.relative(base, full).split(path.sep).join('/')];
    });
}

function removeEmptyDirs(dir, stopAt) {
    let current = dir;
    while (current.startsWith(stopAt) && current !== stopAt && fs.existsSync(current) && fs.readdirSync(current).length === 0) {
        fs.rmdirSync(current);
        current = path.dirname(current);
    }
}

// ------------------------------------------------------------ ön kontrol
if (fs.existsSync(path.join(SITE, 'api', 'wp-load.php')) || fs.existsSync(path.join(ROOT, 'api', 'wp-load.php'))) {
    fail(`${ROOT} içinde WordPress var. Laravel sürümü oraya dağıtılmaz; eski kurulumu yedekleyip kaldırın ya da LOCAL_ROOT ile başka klasör seçin.`);
}
if (!fs.existsSync(path.join(API, 'vendor', 'autoload.php'))) {
    fail('kodmob-api/vendor yok: kodmob-api klasöründe `composer install` çalıştırın.');
}
fs.mkdirSync(SITE, { recursive: true });
log(`hedef: ${ROOT}`);

// ------------------------------------------------------------ ön yüz
if (!args.has('--no-build')) {
    log('npm run build:dev …');
    execSync('npm run build:dev', { cwd: TV, stdio: 'inherit' });
}
const DIST = path.join(TV, 'dist');
if (!fs.existsSync(path.join(DIST, 'km-manifest.json'))) {
    fail('dist/km-manifest.json yok; önce derleyin.');
}
const files = listFiles(DIST).filter((rel) => !rel.startsWith('api/') && !rel.startsWith('media/'));
const previous = fs.existsSync(MANIFEST) ? JSON.parse(fs.readFileSync(MANIFEST, 'utf-8')) : [];
for (const rel of files) {
    const dest = path.join(SITE, rel);
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.copyFileSync(path.join(DIST, rel), dest);
}
const current = new Set(files);
let removed = 0;
for (const rel of previous) {
    if (current.has(rel) || rel.startsWith('api/') || rel.startsWith('media/')) continue;
    const target = path.join(SITE, rel);
    if (fs.existsSync(target)) {
        fs.unlinkSync(target);
        removeEmptyDirs(path.dirname(target), SITE);
        removed++;
    }
}
fs.writeFileSync(MANIFEST, JSON.stringify(files));
log(`ön yüz → public_html (${files.length} dosya, ${removed} eski dosya silindi)`);

// ------------------------------------------------------------ API girişi ve çekirdek
fs.mkdirSync(path.join(SITE, 'api'), { recursive: true });
for (const name of ENTRY_FILES) {
    fs.copyFileSync(path.join(API, 'public', name), path.join(SITE, 'api', name));
}
fs.mkdirSync(path.join(SITE, 'media'), { recursive: true });
if (!fs.existsSync(CORE)) {
    if (process.platform === 'win32') {
        execSync(`mklink /J "${CORE}" "${API}"`, { shell: 'cmd.exe', stdio: 'ignore' });
    } else {
        fs.symlinkSync(API, CORE, 'dir');
    }
    log(`kodmob-core → ${API} (bağlantı)`);
}
log('api/ giriş dosyaları yazıldı.');

// ------------------------------------------------------------ dil kaydı
// Yeni build'in dil yapısı (önek, varsayılan dil, yeni diller) yerel dil kaydına işlenir.
// KM_SKIP_I18N=1: veritabanını çağıran kurar (uçtan uca testler: kodmob:e2e-reset eşitler).
const MAMP_PHP = 'C:/MAMP/bin/php/php8.4.1/php.exe';
const PHP = process.env.KM_PHP || (fs.existsSync(MAMP_PHP) ? MAMP_PHP : 'php');
function syncLanguages() {
    try {
        const out = execSync(`"${PHP}" artisan kodmob:i18n`, { cwd: API, encoding: 'utf-8', stdio: ['ignore', 'pipe', 'pipe'] });
        log(`dil kaydı eşitlendi:\n${out.replace(/\x1b\[[0-9;]*m/g, '').trimEnd()}`);
    } catch (error) {
        log(`UYARI: dil kaydı eşitlenemedi (yerel veritabanı açık mı?): ${String(error.stderr || error.message).split('\n')[0]}`);
    }
}
/** Ana sitenin tabloları (canlıda kurulum adresi yapar); yoksa yeni sürümün tablosu yerelde eksik kalıyordu. */
function migrateMain() {
    try {
        const out = execSync(`"${PHP}" artisan migrate --force`, { cwd: API, encoding: 'utf-8', stdio: ['ignore', 'pipe', 'pipe'] }).replace(/\x1b\[[0-9;]*m/g, '');
        const ran = out.split('\n').filter((line) => /DONE/.test(line)).length;
        log(`tablolar: ${ran ? `${ran} güncelleme çalıştı` : 'güncel'}`);
    } catch (error) {
        log(`UYARI: tablolar güncellenemedi (yerel veritabanı açık mı?): ${String(error.stderr || error.message).split('\n')[0]}`);
    }
}
/** Çok site: alt sitelerin tabloları ve dil kayıtları da yeni derlemeyle (canlıda kurulum adresi yapar). */
function syncSites() {
    try {
        const out = execSync(`"${PHP}" artisan kodmob:site migrate`, { cwd: API, encoding: 'utf-8', stdio: ['ignore', 'pipe', 'pipe'] }).replace(/\x1b\[[0-9;]*m/g, '').trim();
        if (out) log(`alt siteler: ${out.split('\n').join(' · ')}`);
    } catch (error) {
        log(`UYARI: alt siteler güncellenemedi: ${String(error.stdout || error.stderr || error.message).replace(/\x1b\[[0-9;]*m/g, '').trim().split('\n')[0]}`);
    }
}
if (process.env.KM_SKIP_I18N === '1') {
    log('dil kaydı: atlandı (KM_SKIP_I18N)');
} else {
    migrateMain();
    syncLanguages();
    syncSites();
    log('Sunucu: npm run serve:local → http://127.0.0.1:8090/');
}
