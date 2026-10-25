/**
 * Alt sitelerin kendi derlemeleri (çok site; docs/done-starter-raporu.md §7). kodmob-frontend/sites/<anahtar>/ klasörü olan
 * her site için `vite build` (KM_SITE=<anahtar>; katman: vite.config.js, vite/i18n-pages.js), ardından:
 *
 *   dist-sites/<anahtar>/km-manifest.json, _pages/  →  dist/_sites/<anahtar>/   (sunucuda public_html/_sites/<anahtar>/)
 *   dist-sites/<anahtar>/assets/**                  →  dist/assets/                (adları hash'li, çakışmaz; ortak klasör)
 *
 * Ana derlemeden sonra çalışır (npm run build, build:dev, build:prod). Klasörü olmayan alt site ana sitenin
 * derlemesini kullanır (ortak tema). Klasör adı kayıttaki anahtarla aynı olmalı.
 *
 *   node scripts/build-sites.mjs [--mode development|production] [anahtar …]
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const TV = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SITES = path.join(TV, 'sites');
const DIST = path.join(TV, 'dist');
const OUT = path.join(TV, 'dist-sites');
const KEY = /^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/;
const log = (message) => console.log(`[sites] ${message}`);

const args = process.argv.slice(2);
const modeAt = args.indexOf('--mode');
const mode = modeAt >= 0 ? ['--mode', args[modeAt + 1]] : [];
const only = args.filter((a, i) => !a.startsWith('--') && i !== modeAt + 1);

const keys = (fs.existsSync(SITES) ? fs.readdirSync(SITES, { withFileTypes: true }) : [])
    .filter((d) => d.isDirectory() && KEY.test(d.name) && (!only.length || only.includes(d.name)))
    .map((d) => d.name);
if (!keys.length) process.exit(0);
if (!fs.existsSync(path.join(DIST, 'km-manifest.json'))) {
    console.error('[sites] Önce ana site derlenmeli (dist/km-manifest.json yok).');
    process.exit(1);
}

/** Klasördeki dosyalar (göreli yollar). */
function files(dir, base = dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
        const full = path.join(dir, e.name);
        return e.isDirectory() ? files(full, base) : [path.relative(base, full)];
    });
}

const vite = path.join(TV, 'node_modules', 'vite', 'bin', 'vite.js');
for (const key of keys) {
    log(`${key}: derleniyor (kodmob-frontend/sites/${key})…`);
    execFileSync(process.execPath, [vite, 'build', ...mode, '--logLevel', 'warn'], { cwd: TV, env: { ...process.env, KM_SITE: key }, stdio: 'inherit' });
    const out = path.join(OUT, key);
    const target = path.join(DIST, '_sites', key);
    fs.rmSync(target, { recursive: true, force: true });
    fs.mkdirSync(target, { recursive: true });
    fs.cpSync(path.join(out, '_pages'), path.join(target, '_pages'), { recursive: true });
    fs.copyFileSync(path.join(out, 'km-manifest.json'), path.join(target, 'km-manifest.json'));
    // Sayfa düzenleyicinin blokları ve bölgeleri (kodmob-core Site\Blocks\BlockLibrary).
    fs.copyFileSync(path.join(out, 'km-blocks.json'), path.join(target, 'km-blocks.json'));
    let copied = 0;
    if (fs.existsSync(path.join(out, 'assets'))) {
        for (const rel of files(path.join(out, 'assets'))) {
            const dest = path.join(DIST, 'assets', rel);
            if (fs.existsSync(dest)) continue;
            fs.mkdirSync(path.dirname(dest), { recursive: true });
            fs.copyFileSync(path.join(out, 'assets', rel), dest);
            copied++;
        }
    }
    fs.rmSync(out, { recursive: true, force: true });
    log(`${key}: dist/_sites/${key} (${files(target).length} dosya), ${copied} yeni varlık dist/assets'e.`);
}
fs.rmSync(OUT, { recursive: true, force: true });
