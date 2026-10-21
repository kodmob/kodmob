/**
 * `npm run e2e` → derler, .e2e'ye dağıtır, bütün senaryoları çalıştırır.
 *
 *   npm run e2e -- --no-build          eldeki dist ile (hızlı)
 *   npm run e2e -- --no-build grid     yalnızca adı "grid" geçen senaryo dosyaları
 *   npm run e2e -- --headed            tarayıcıyı görerek
 *   npm run e2e -- --starter           başlangıç profiliyle (KM_PROFILE=starter) derleyip yalnızca starter senaryosu
 *   npm run e2e -- --shop              başlangıç profili + Mağaza, Rezervasyon, Oteller açık (KODMOB_MODULES) → yalnızca shop senaryosu
 *
 * Araç paketleri: kodmob-api/extensions yerine örnek paket (kodmob-api/tests/fixtures/extensions; extensions.spec).
 *
 * Diğer argümanlar Playwright'a geçer. Başarısız testin izi: .e2e/results/<test>/trace.zip
 * (`npx playwright show-trace <dosya>`); istek günlüğü: .e2e/requests.log.
 */
import { spawn } from 'node:child_process';
import path from 'node:path';
import { createRequire } from 'node:module';
import { TV } from './env.js';

const args = process.argv.slice(2);
const env = { ...process.env };
if (args.includes('--no-build')) env.E2E_BUILD = '0';
// Başlangıç profili: site o profille derlenir; kodmob.com senaryoları ona göre değil, yalnızca starter koşar.
const starter = args.includes('--starter');
// Mağaza: başlangıç profili (Mağaza rotaları onda) ve modül ortamdan açık; yalnızca shop senaryosu.
const shop = args.includes('--shop');
if (starter || shop) env.KM_PROFILE = 'starter';
// Araç paketi: örnek paket (kodmob:tool new çıktısı) derlemeye ve sunucuya girer (extensions.spec); başlangıç paketsiz.
else env.KM_EXTENSIONS = env.KODMOB_EXTENSIONS = path.join(TV, '..', 'kodmob-api', 'tests', 'fixtures', 'extensions');
if (shop) {
    env.KODMOB_MODULES = 'blog,briefs,commerce,booking,hotels';
    env.E2E_SHOP = '1';
}
const rest = args.filter((a) => a !== '--no-build' && a !== '--starter' && a !== '--shop');
if ((starter || shop) && !rest.some((a) => !a.startsWith('-'))) rest.push(shop ? 'shop' : 'starter');
const cli = createRequire(path.join(TV, 'package.json')).resolve('@playwright/test/cli');
const child = spawn(process.execPath, [cli, 'test', '-c', path.join(TV, 'e2e', 'playwright.config.js'), ...rest], { cwd: TV, env, stdio: 'inherit' });
child.on('exit', (code) => process.exit(code ?? 1));
