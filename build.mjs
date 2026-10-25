/**
 * Kodmob — tek komutla canlı paketi üretir (ayrıntı: docs/build.md).
 *
 *   node build.mjs                    → testler (backend + ön yüz) + ön yüz + Laravel + yereldeki içerik → release/
 *   node build.mjs --no-content       → içeriksiz güncelleme paketi
 *   node build.mjs --bump minor       → önce sürümü yükseltir (major | minor | patch)
 *   node build.mjs --skip-tests       → backend testlerini atlar
 *   node build.mjs --ignore-links     → bağlantı denetimindeki hatalarda durmaz (yalnızca uyarır; eski adı --ignore-health)
 *
 * Windows'ta `build` (build.cmd) aynı işi yapar. PHP: KM_PHP, Composer: KM_COMPOSER.
 */
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const TV = path.join(ROOT, 'kodmob-frontend');
const API = path.join(ROOT, 'kodmob-core');
const OUT = path.join(ROOT, 'release');
const PKG = path.join(TV, 'package.json');
const CONFIG = path.join(API, 'config', 'kodmob.php');
const FRAMEWORK = path.join(ROOT, 'kodmob.json');

const MAMP_PHP = 'C:/MAMP/bin/php/php8.4.1/php.exe';
const PHP = process.env.KM_PHP || (fs.existsSync(MAMP_PHP) ? MAMP_PHP : 'php');
const COMPOSER = process.env.KM_COMPOSER || (fs.existsSync('C:/ProgramData/ComposerSetup/bin/composer.phar') ? `"${PHP}" C:/ProgramData/ComposerSetup/bin/composer.phar` : 'composer');

const argv = process.argv.slice(2);
const has = (flag) => argv.includes(flag);
const bump = argv.includes('--bump') ? argv[argv.indexOf('--bump') + 1] : null;

const log = (message) => console.log(`\n[build] ${message}`);
function fail(message) {
    console.error(`\n[build] DURDURULDU: ${message}`);
    process.exit(1);
}
const run = (command, cwd) => execSync(command, { cwd, stdio: 'inherit' });

if (has('--help') || has('-h')) {
    console.log(fs.readFileSync(fileURLToPath(import.meta.url), 'utf-8').match(/\/\*\*([\s\S]*?)\*\//)[1].replace(/^ \* ?/gm, ''));
    process.exit(0);
}
for (const dir of [TV, API]) if (!fs.existsSync(dir)) fail(`${path.basename(dir)}/ bulunamadı.`);

// ------------------------------------------------------------ sürüm
const readVersions = () => ({
    tv: JSON.parse(fs.readFileSync(PKG, 'utf-8')).version,
    api: (fs.readFileSync(CONFIG, 'utf-8').match(/'version'\s*=>\s*'([^']+)'/) || [])[1],
    // Çatının tanımı (kodmob:pack / kodmob:upgrade); yoksa denetlenmez.
    framework: fs.existsSync(FRAMEWORK) ? JSON.parse(fs.readFileSync(FRAMEWORK, 'utf-8')).version : null,
});
let versions = readVersions();

if (bump) {
    const parts = String(versions.tv).split('.').map(Number);
    const index = { major: 0, minor: 1, patch: 2 }[bump];
    if (index === undefined) fail('--bump için major, minor ya da patch yazın.');
    parts[index]++;
    parts.fill(0, index + 1);
    const next = parts.join('.');
    fs.writeFileSync(PKG, fs.readFileSync(PKG, 'utf-8').replace(/("version":\s*")[^"]+(")/, `$1${next}$2`));
    fs.writeFileSync(CONFIG, fs.readFileSync(CONFIG, 'utf-8').replace(/('version'\s*=>\s*')[^']+(')/, `$1${next}$2`));
    if (fs.existsSync(FRAMEWORK)) fs.writeFileSync(FRAMEWORK, fs.readFileSync(FRAMEWORK, 'utf-8').replace(/("version":\s*")[^"]+(")/, `$1${next}$2`));
    log(`sürüm ${versions.tv} → ${next}`);
    versions = readVersions();
}
if (!versions.api) fail("kodmob-core/config/kodmob.php içinde 'version' yok.");
if (versions.tv !== versions.api) fail(`sürümler farklı: kodmob-frontend ${versions.tv}, kodmob-core ${versions.api}.`);
if (versions.framework && versions.framework !== versions.tv) fail(`sürümler farklı: kodmob.json ${versions.framework}, kodmob-frontend ${versions.tv}.`);
const VERSION = versions.tv;
log(`Kodmob v${VERSION}`);

// ------------------------------------------------------------ bağımlılıklar
if (!fs.existsSync(path.join(TV, 'node_modules'))) {
    log('kodmob-frontend: npm ci …');
    run('npm ci', TV);
}
if (!fs.existsSync(path.join(API, 'vendor', 'autoload.php'))) {
    log('kodmob-core: composer install …');
    run(`${COMPOSER} install --no-interaction`, API);
}

// ------------------------------------------------------------ testler
if (has('--skip-tests')) {
    log('testler atlandı (--skip-tests)');
} else {
    log('kodmob-core: php artisan test …');
    try {
        run(`"${PHP}" artisan test`, API);
    } catch {
        fail('backend testleri geçmedi; paket üretilmedi.');
    }
    log('kodmob-frontend: npm test (panel rotaları, bağlantı denetimi) …');
    try {
        run('npm test', TV);
    } catch {
        fail('ön yüz testleri geçmedi; paket üretilmedi.');
    }
}

// ------------------------------------------------------------ bağlantı denetimi (Çoklu Dil > Sağlık; kırık bağlantı, eksik çeviri)
if (!has('--no-content')) {
    log('bağlantı denetimi (php artisan kodmob:links) …');
    try {
        run(`"${PHP}" artisan kodmob:links`, API);
    } catch (error) {
        if (error.status === 2) log('UYARI: bağlantılar denetlenemedi (yerel veritabanı kapalı mı?); devam ediliyor.');
        else if (has('--ignore-links') || has('--ignore-health')) log('UYARI: bağlantı denetiminde hata var; --ignore-links ile devam ediliyor.');
        else fail('bağlantı denetiminde hata var (yukarıda). Panelde Çoklu Dil > Sağlık; düzeltin ya da --ignore-links ile paketleyin.');
    }
}

// ------------------------------------------------------------ paket
log(`paket${has('--no-content') ? ' (içeriksiz)' : ''} …`);
try {
    run(`npm run release${has('--no-content') ? ' -- --no-content' : ''}`, TV);
} catch {
    fail('paketleme başarısız (yukarıdaki çıktıya bakın).');
}

// ------------------------------------------------------------ özet
const mb = (bytes) => (bytes / 1048576).toFixed(1) + ' MB';
const files = fs.readdirSync(OUT).filter((name) => name.endsWith('.zip') || name === 'OKUBENI.txt');
log(`hazır → release/  (v${VERSION})`);
for (const name of files) console.log(`  ${name.padEnd(28)} ${mb(fs.statSync(path.join(OUT, name)).size)}`);
console.log('  ilk-kurulum/.env             yalnızca ilk kurulumda (gizli; paylaşmayın)');
console.log('\n  Yükleme adımları: release/OKUBENI.txt');
