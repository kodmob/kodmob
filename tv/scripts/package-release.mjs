/**
 * Canlıya yüklenecek paketi hazırlar (sunucuda npm/composer/SSH gerekmez):
 *
 *   npm run release                    → ön yüz + API + yereldeki içerik
 *   npm run release -- --no-content    → içeriksiz (güncelleme paketi)
 *   (kök dizinde: node build.mjs — testler + bu betik; bkz. build.md)
 *
 * Sürüm: tv/package.json `version` ile kodmob-api/config/kodmob.php `version` aynı olmalı.
 *
 * Çıktı: <proje>/release/
 *   kodmob-site-v<sürüm>.zip   → public_html/ içine açılır: sayfalar, assets, .htaccess, api/ (index.php, render.php, .htaccess)
 *   kodmob-core-v<sürüm>.zip   → public_html'in YANINA açılır: Laravel (vendor dahil) + ilk içerik
 *   ilk-kurulum/.env  → yalnızca ilk kurulumda kodmob-core/.env olarak yüklenir (anahtarlar hazır, DB bilgisi doldurulur)
 *   public_html/, kodmob-core/   aynı içeriğin klasör hali (FTP ile yükleyenler için)
 *   OKUBENI.txt       → adımlar
 *
 * İlk içerik: yerel API'nin (kodmob-api/.env) veritabanındaki içerik `kodmob:export`
 * ile kodmob-core/storage/app/backups/ilk-icerik.json ve görsel asılları media/
 * klasörüne yazılır; kurulum ekranı tek tıkla yükler.
 *
 * Composer: KM_COMPOSER (varsayılan MAMP PHP 8.4 + composer.phar), PHP: KM_PHP.
 */
import { execSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const TV = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PROJECT = path.resolve(TV, '..');
const API = path.join(PROJECT, 'kodmob-api');
const OUT = path.join(PROJECT, 'release');
const SITE = path.join(OUT, 'public_html');
const CORE = path.join(OUT, 'kodmob-core');
const FIRST = path.join(OUT, 'ilk-kurulum');

const MAMP_PHP = 'C:/MAMP/bin/php/php8.4.1/php.exe';
const PHP = process.env.KM_PHP || (fs.existsSync(MAMP_PHP) ? MAMP_PHP : 'php');
const COMPOSER = process.env.KM_COMPOSER || (fs.existsSync('C:/ProgramData/ComposerSetup/bin/composer.phar') ? `"${PHP}" C:/ProgramData/ComposerSetup/bin/composer.phar` : 'composer');
const args = new Set(process.argv.slice(2));

// Laravel'den pakete girmeyenler (geliştirme dosyaları, yerel ayar, yerel veriler).
const CORE_EXCLUDE = [
    /^\.env$/, /^\.git(\/|$)/, /^\.gitignore$/, /^\.gitattributes$/, /^\.editorconfig$/, /^vendor(\/|$)/, /^node_modules(\/|$)/,
    /^tests(\/|$)/, /^dev(\/|$)/, /^phpunit\.xml$/, /^\.phpunit/, /^phpstan(-baseline)?\.neon$/, /^public(\/|$)/,
    /^bootstrap\/cache\/.+\.php$/,
    // Araç paketlerinin panel kaynakları derlemeye girer, testleri geliştirmede kalır.
    /^extensions\/[^/]+\/(panel|tests)(\/|$)/,
    /^storage\/(?!.*\.gitignore$).+/,
];

const log = (message) => console.log(`[release] ${message}`);
function fail(message) {
    console.error(`[release] DURDURULDU: ${message}`);
    process.exit(1);
}

function copyTree(src, dest, exclude = [], base = src) {
    let count = 0;
    for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
        const from = path.join(src, entry.name);
        const rel = path.relative(base, from).split(path.sep).join('/');
        if (exclude.some((re) => re.test(rel) || re.test(rel + '/'))) continue;
        if (entry.isDirectory()) {
            count += copyTree(from, path.join(dest, entry.name), exclude, base);
        } else {
            fs.mkdirSync(dest, { recursive: true });
            fs.copyFileSync(from, path.join(dest, entry.name));
            count++;
        }
    }
    return count;
}

/** Windows'un tar'ı (bsdtar) zip yazar ve yolları `/` ile kaydeder (Linux'ta doğru açılır). */
function zip(dir, file, entries = ['.']) {
    const tar = process.platform === 'win32' ? path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe') : 'tar';
    fs.rmSync(file, { force: true });
    try {
        execSync(`"${tar}" -a -c -f "${file}" -C "${dir}" ${entries.map((e) => `"${e}"`).join(' ')}`, { stdio: ['ignore', 'ignore', 'pipe'] });
        return true;
    } catch (error) {
        log(`UYARI: zip oluşturulamadı (${String(error.stderr || error.message).trim().split('\n')[0]}); klasörü yükleyin: ${dir}`);
        return false;
    }
}

const size = (dir) => fs.readdirSync(dir, { recursive: true, withFileTypes: true }).filter((e) => e.isFile()).reduce((n, e) => n + fs.statSync(path.join(e.parentPath ?? e.path, e.name)).size, 0);
const mb = (bytes) => (bytes / 1048576).toFixed(1) + ' MB';

// ------------------------------------------------------------ hazırlık
if (!fs.existsSync(path.join(API, 'artisan'))) fail(`kodmob-api bulunamadı: ${API}`);
const VERSION = JSON.parse(fs.readFileSync(path.join(TV, 'package.json'), 'utf-8')).version;
const apiVersion = (fs.readFileSync(path.join(API, 'config', 'kodmob.php'), 'utf-8').match(/'version'\s*=>\s*'([^']+)'/) || [])[1];
if (VERSION !== apiVersion) fail(`sürümler farklı: tv/package.json ${VERSION}, kodmob-api/config/kodmob.php ${apiVersion}. İkisini eşitleyin (node build.mjs --bump …).`);
const SITE_ZIP = `kodmob-site-v${VERSION}.zip`;
const CORE_ZIP = `kodmob-core-v${VERSION}.zip`;
log(`sürüm ${VERSION}`);
if (fs.existsSync(OUT) && !fs.existsSync(path.join(OUT, '.km-release'))) fail(`${OUT} bu betiğin klasörü değil (içinde .km-release yok); elle kontrol edin.`);
// Yalnızca bu betiğin ürettikleri yenilenir; ilk-kurulum/.env korunur (anahtarlar her pakette aynı kalsın).
const outputs = fs.existsSync(OUT) ? fs.readdirSync(OUT).filter((n) => /^kodmob-(site|core)(-v[\d.]+)?\.zip$/.test(n)) : [];
for (const name of ['public_html', 'kodmob-core', 'OKUBENI.txt', ...outputs]) {
    fs.rmSync(path.join(OUT, name), { recursive: true, force: true });
}
fs.mkdirSync(OUT, { recursive: true });
fs.writeFileSync(path.join(OUT, '.km-release'), new Date().toISOString());

// ------------------------------------------------------------ ön yüz
log('npm run build:prod …');
execSync('npm run build:prod', { cwd: TV, stdio: 'inherit' });
const DIST = path.join(TV, 'dist');
if (!fs.existsSync(path.join(DIST, 'km-manifest.json'))) fail('dist/km-manifest.json yok; derleme eksik.');
const manifest = JSON.parse(fs.readFileSync(path.join(DIST, 'km-manifest.json'), 'utf-8'));
// Sayfa kabukları _pages/<dil>/ altında (vite/site-urls.js SHELLS); varsayılan dilin ana sayfası olmalı.
for (const required of [`_pages/${manifest.default || 'tr'}/index.html`, '.htaccess', 'panel/index.html']) {
    if (!fs.existsSync(path.join(DIST, required))) fail(`dist/${required} yok; derleme eksik.`);
}
const siteFiles = copyTree(DIST, SITE);
for (const name of ['index.php', 'render.php', '.htaccess']) {
    fs.mkdirSync(path.join(SITE, 'api'), { recursive: true });
    fs.copyFileSync(path.join(API, 'public', name), path.join(SITE, 'api', name));
}
log(`public_html → ${siteFiles} dosya + api/ (${manifest.siteUrl}, ${manifest.indexable ? 'arama motorlarına AÇIK' : 'arama motorlarına kapalı'})`);

// ------------------------------------------------------------ Laravel çekirdeği
const coreFiles = copyTree(API, CORE, CORE_EXCLUDE);
fs.copyFileSync(path.join(API, '.env.example'), path.join(CORE, '.env.example'));
log(`kodmob-core → ${coreFiles} dosya; composer install --no-dev …`);
try {
    execSync(`${COMPOSER} install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts --working-dir="${CORE}"`, { stdio: ['ignore', 'ignore', 'pipe'] });
} catch (error) {
    fail(`composer çalışmadı: ${String(error.stderr || error.message).split('\n').filter((l) => l.trim() && !l.includes('Deprecation')).slice(0, 4).join(' ')}`);
}
if (!fs.existsSync(path.join(CORE, 'vendor', 'autoload.php'))) fail('vendor/autoload.php oluşmadı.');
// Kurulum ekranı ve günlük için yazılabilir klasörler (zip boş klasörü taşımaz: .gitignore dosyaları var).
for (const dir of ['storage/app/backups/media', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/pages', 'storage/logs', 'bootstrap/cache']) {
    fs.mkdirSync(path.join(CORE, dir), { recursive: true });
    const keep = path.join(CORE, dir, '.gitignore');
    if (!fs.readdirSync(path.join(CORE, dir)).length) fs.writeFileSync(keep, '*\n!.gitignore\n');
}
fs.writeFileSync(path.join(CORE, 'storage', 'app', 'backups', '.htaccess'), 'Require all denied\n');
fs.writeFileSync(path.join(CORE, '.htaccess'), '# Çekirdek web kökünün içine (_core) konursa dışarıdan açılmasın.\nRequire all denied\n');
fs.writeFileSync(path.join(CORE, 'VERSION'), `${VERSION}\n`);

// ------------------------------------------------------------ ilk içerik
let content = null;
if (!args.has('--no-content')) {
    try {
        const out = execSync(`"${PHP}" artisan kodmob:export "${path.join(CORE, 'storage', 'app', 'backups', 'ilk-icerik.json')}" --media="${path.join(CORE, 'storage', 'app', 'backups', 'media')}"`, { cwd: API, encoding: 'utf-8', stdio: ['ignore', 'pipe', 'pipe'] });
        content = out.replace(/\x1b\[[0-9;]*m/g, '').trim();
        log(`ilk içerik: ${content}`);
    } catch (error) {
        log(`UYARI: yerel içerik dışa aktarılamadı (yerel veritabanı açık mı?); paket içeriksiz. ${String(error.stderr || error.message).split('\n')[0]}`);
    }
}

// ------------------------------------------------------------ ilk kurulum .env (bir kez üretilir, sonra korunur)
const envFile = path.join(FIRST, '.env');
if (!fs.existsSync(envFile)) {
    fs.mkdirSync(FIRST, { recursive: true });
    const env = fs.readFileSync(path.join(API, '.env.example'), 'utf-8')
        .replace(/^APP_URL=.*$/m, `APP_URL=${manifest.siteUrl}`)
        .replace(/^APP_KEY=.*$/m, `APP_KEY=base64:${crypto.randomBytes(32).toString('base64')}`)
        .replace(/^JWT_SECRET=.*$/m, `JWT_SECRET=${crypto.randomBytes(48).toString('hex')}`)
        .replace(/^SETUP_TOKEN=.*$/m, `SETUP_TOKEN=${crypto.randomBytes(18).toString('hex')}`);
    fs.writeFileSync(envFile, env);
    log('ilk-kurulum/.env üretildi (APP_KEY, JWT_SECRET, SETUP_TOKEN hazır)');
}
const setupToken = (fs.readFileSync(envFile, 'utf-8').match(/^SETUP_TOKEN=(.*)$/m) || [])[1] || '<SETUP_TOKEN>';

// ------------------------------------------------------------ zip + yönerge
const zipped = [zip(SITE, path.join(OUT, SITE_ZIP)), zip(OUT, path.join(OUT, CORE_ZIP), ['kodmob-core'])];

fs.writeFileSync(path.join(OUT, 'OKUBENI.txt'), `KODMOB v${VERSION} — CANLIYA YÜKLEME (Laravel) · ${new Date().toISOString().slice(0, 10)}
Site: ${manifest.siteUrl}   (public_html ${mb(size(SITE))}, kodmob-core ${mb(size(CORE))})

Hostinger'da klasörler (dosya yöneticisi):
  domains/kodmob.com/public_html/     ← site
  domains/kodmob.com/kodmob-core/     ← Laravel (public_html'in YANINDA; web'den erişilemez)

İLK KURULUM (WordPress'ten geçiş)
1) Eski WordPress'i kaldırın: public_html/api klasörünün adını "api-wp-eski" yapın
   (ya da yedek alıp silin). Eski WordPress veritabanı kalabilir; yeni sistem ayrı tablolar kullanır.
2) ${CORE_ZIP} → domains/kodmob.com/ içine açın (kodmob-core/ klasörü oluşur).
   Hosting public_html dışına izin vermiyorsa: kodmob-core klasörünü public_html/api/_core adıyla koyun.
3) ilk-kurulum/.env → kodmob-core/.env olarak yükleyin ve DB_DATABASE, DB_USERNAME,
   DB_PASSWORD alanlarını doldurun (Hostinger > Veritabanları; yeni bir MySQL veritabanı önerilir).
   Diğer değerler (APP_KEY, JWT_SECRET, SETUP_TOKEN) hazırdır, değiştirmeyin.
4) ${SITE_ZIP} → public_html/ içine açın (üzerine yazın). Sonuç:
     public_html/_pages/  panel/  assets/  .htaccess  km-manifest.json
     public_html/api/index.php  api/render.php  api/.htaccess
5) PHP sürümü: 8.4 ya da 8.5 seçin (Hostinger > Gelişmiş > PHP Yapılandırması). Eklentiler: pdo_mysql, mbstring, gd, curl, fileinfo, openssl.
6) Tarayıcıda açın:  ${manifest.siteUrl}/api/v1/setup?token=${setupToken}
   Sunucu denetimi yeşilse yönetici bilgilerini yazın${content ? ' ve "Paketle gelen içeriği yükle" işaretli kalsın' : ''}; "Kurulumu tamamla".
7) Bitince kodmob-core/.env içindeki SETUP_TOKEN satırını boşaltın (SETUP_TOKEN=).
8) Kontrol:
     ${manifest.siteUrl}/               ana sayfa (Türkçe, öneksiz; İngilizce /en/)
     ${manifest.siteUrl}/panel/         giriş
     ${manifest.siteUrl}/api/v1/site    JSON
9) (Önerilir) Hostinger > Cron Jobs, her dakika:
     php /home/<kullanıcı>/domains/kodmob.com/kodmob-core/artisan schedule:run >> /dev/null 2>&1
   (Jarvis taramaları, eski talep ve bildirimlerin silinmesi. Cron yoksa panel açıkken çalışır.)

GÜNCELLEME (sonraki sürümler: kök dizinde node build.mjs --no-content)
- ${SITE_ZIP} ve ${CORE_ZIP}'i aynı yerlere açın (üzerine yazın). kodmob-core/.env,
  storage/ ve public_html/media/ (yüklenen görseller) korunur; bunları silmeyin.
- Tablo değişikliği için kurulum adresini bir kez açın (SETUP_TOKEN geçici olarak yeniden yazılır).
- 2.0'a geçişte yeni tablolar oluşur: roller ve yetkiler, işlem geçmişi, oturumlar, güvenlik, SEO.
  robots.txt artık panelden yönetilir (SEO > Robots); public_html/robots.txt dosyası kalsa da geçersizdir.
- 2.1: panel adreslerinde # yok (/panel/projeler/12, /panel/ayarlar/yedekleme). public_html/.htaccess
  mutlaka yeni haliyle yüklenmeli (panel kuralı); eski /panel/#/… bağlantılar kendiliğinden çevrilir.
  Yeni dil kaydı tablosu (languages) ve bildirim/rol menüsü göçü kurulum adresiyle çalışır.
- 2.2: Türkçe adreslerde /tr/ yok (/, /projeler/x/); eski /tr/… adresleri kendiliğinden 301 ile
  yenisine gider. Sayfa kabukları public_html/_pages/ altında. Eski public_html/tr/, en/, de/
  klasörleri silinebilir (kalsa da kullanılmaz). .htaccess mutlaka yeni haliyle yüklenmeli.
  Kurulum adresini bir kez açın: dil kaydı yeni build ile eşitlenir.
  Çoklu dil: içerik çevirileri (dil başına yayın, blog çeviri grupları, Jarvis ile çeviri), adresi
  değişen kayıt 301, silinen 410, yol gösteren 404 sayfası, kırık bağlantı denetimi ve panelde
  Çoklu Dil sayfası (/panel/diller; Sayfa metinleri oraya taşındı).
- 2.3: arka plan işleri ve SMTP (Ayarlar > E-posta, Arka plan), iki adımlı doğrulama, çerezli oturum,
  otomatik ve uzak yedek, SMS (NetGSM). Kurulum adresi yeni tabloları kurar (jobs, mail_log, sms_log…).
- 2.4: Ayarlar > Kimlik (site adı, logo yazısı ya da görseli, ana renk, koyu bant rengi; boşsa eski görünüm),
  Ayarlar > Yapay zekâ (Claude, OpenAI, Gemini; eski Jarvis anahtarı kendiliğinden taşınır), Diğer İşlemler
  ve üst çubukta panel araması. Çok site altyapısı: alt site kurulmadıkça hiçbir şey değişmez (Yönetim >
  Siteler). public_html/.htaccess mutlaka yeni haliyle yüklenmeli (_sites kuralı). Kurulum adresini bir kez
  açın: yeni sütunlar (kullanıcı tercihleri, yapay zekâ yanıtları) oluşur.
- 2.5: panel yeniden düzenlendi: üstte proje seçici, menüde Temel / Proje modülleri, altta Sistem; Proje
  Ayarları ve Sistem Ayarları (eski /panel/ayarlar/… adresleri yenisine açılır); Tüm İşlemler üst barda; Araç
  Pazarı (araçlar projede açılıp kapanır); SEO > Yapay zekâ; Site Sihirbazı; Kullanım Kılavuzu; Sistem Sağlığı.
  Alt projeler e-posta / SMS / yapay zekâ ayarını ana projeden kullanır; projeler arası tek oturumla geçiş.
  Kurulum adresini bir kez açın: yeni tablolar (seo_ai_hits, handoff_tokens) ana ve alt projelerde oluşur.

E-POSTA: varsayılan "sendmail". Panelde Ayarlar > E-posta > "Deneme gönder" ile deneyin. Gelmezse aynı
sayfada SMTP: smtp.hostinger.com, 465, SSL, kullanıcı ve şifre (info@kodmob.com hesabı); şifre şifreli
saklanır. Son gönderimler ve hataları aynı sayfada.

ARKA PLAN: e-postalar kuyruktan gider; kuyruğu cron boşaltır (9. adım). Cron yoksa iş yanıttan sonra
aynı istekte çalışır (yeniden deneme olmaz). Durum: Ayarlar > Arka plan (son cron, bekleyen ve
başarısız işler).

SORUN GİDERME
- /api/... 500: kodmob-core/storage/logs/ içindeki günlük; .env ve klasör izinleri (storage, bootstrap/cache yazılabilir).
- "kodmob-core klasörü bulunamadı": 2. adımdaki yer.
- Giriş sonrası oturum düşüyor: public_html/api/.htaccess yüklü mü (Authorization başlığı).
`);

log(`bitti → ${OUT}${zipped.every(Boolean) ? ` (${SITE_ZIP}, ${CORE_ZIP}, ilk-kurulum/.env, OKUBENI.txt)` : ''}`);
