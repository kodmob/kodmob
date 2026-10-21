/**
 * Backend testleri üç veritabanında: SQLite (bellek içi), MySQL/MariaDB ve PostgreSQL.
 *
 *   npm run test:db                         üçü sırayla
 *   npm run test:db -- pgsql                yalnız biri (sqlite | mysql | pgsql)
 *   npm run test:db -- pgsql --filter=Ai    phpunit argümanları geçer
 *
 * - PostgreSQL: embedded-postgres; veri <proje>/.pg (git'e girmez), port 54329 (PG_PORT). Kurulum ya da
 *   servis yok; betik başlatır, bitince durdurur. PHP'nin pdo_pgsql eklentisi komut satırında açılır
 *   (MAMP php.ini'si değişmez).
 * - MySQL: kodmob-api/.env'deki sunucu (MAMP) ama ayrı \`kodmob_test\` veritabanı (yoksa açılır).
 *   Geliştirme veritabanına dokunulmaz: testler her seferinde tabloları sıfırdan kurar.
 * - CI'da aynı üçlü .github/workflows/ci.yml'de servislerle çalışır.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { API, PHP, PROJECT, TV } from '../e2e/env.js';

const require = createRequire(path.join(TV, 'package.json'));
const ALL = ['sqlite', 'mysql', 'pgsql'];
const args = process.argv.slice(2);
const wanted = args.filter((a) => ALL.includes(a));
const passthrough = args.filter((a) => !ALL.includes(a));
const engines = wanted.length ? wanted : ALL;
const TEST_DB = 'kodmob_test';
const PG = { port: Number(process.env.PG_PORT || 54329), user: 'postgres', password: 'kodmob', dir: path.join(PROJECT, '.pg', 'data') };

const PG_CTL = path.join(TV, 'node_modules', '@embedded-postgres', `${process.platform === 'win32' ? 'windows' : process.platform}-${process.arch}`, 'native', 'bin', process.platform === 'win32' ? 'pg_ctl.exe' : 'pg_ctl');
const log = (msg) => console.log(`\x1b[36m[test-db]\x1b[0m ${msg}`);

/** kodmob-api/.env'den bir değer (yalnızca MySQL bağlantı bilgisi için). */
function envValue(key) {
    const text = fs.readFileSync(path.join(API, '.env'), 'utf8');
    const line = text.split(/\r?\n/).find((l) => l.startsWith(`${key}=`));
    return line ? line.slice(key.length + 1).replace(/^"(.*)"$/, '$1') : '';
}

function phpunit(env, extra = []) {
    const result = spawnSync(PHP, [...extra, 'vendor/phpunit/phpunit/phpunit', '--colors=always', ...passthrough], {
        cwd: API, stdio: 'inherit', env: { ...process.env, ...env },
    });
    return result.status === 0;
}

async function withPostgres(run) {
    const mod = require('embedded-postgres');
    const EmbeddedPostgres = mod.default ?? mod;
    const pg = new EmbeddedPostgres({
        databaseDir: PG.dir, user: PG.user, password: PG.password, port: PG.port, persistent: true,
        // Windows'ta sistem yerel ayarı ("Turkish_Türkiye") initdb'yi durdurur; UTF-8 + ICU kök yerel ayarı.
        initdbFlags: ['--encoding=UTF8', '--locale=C', '--locale-provider=icu', '--icu-locale=und'],
        // PG 18'in G/Ç işçileri Windows'ta sunucu durunca açık kalıp portu tutabiliyor; eşzamanlı G/Ç yeterli.
        postgresFlags: ['-c', 'io_method=sync'],
        onLog: () => {},
        onError: () => {},
    });
    if (!fs.existsSync(path.join(PG.dir, 'PG_VERSION'))) {
        log('PostgreSQL ilk kurulum (.pg/data)…');
        await pg.initialise();
    }
    try {
        await pg.start();
    } catch {
        // Önceki çalıştırma yarıda kesildiyse sunucu açık kalmıştır: durdurulup yeniden başlatılır.
        log('Açık kalan PostgreSQL durduruluyor…');
        spawnSync(PG_CTL, ['stop', '-D', PG.dir, '-m', 'fast'], { stdio: 'ignore' });
        killOrphans();
        await pg.start();
    }
    try {
        await pg.createDatabase(TEST_DB).catch(() => {});
        return run();
    } finally {
        await pg.stop();
        killOrphans();
    }
}

/** Windows: ana süreç ölünce alt süreçler portu tutabilir; yalnızca bu paketin postgres süreçleri kapatılır. */
function killOrphans() {
    if (process.platform !== 'win32') return;
    // Alt süreçlerde ExecutablePath boş gelebiliyor; komut satırı her zaman paketin yolunu taşır.
    const filter = "Get-CimInstance Win32_Process -Filter \"Name='postgres.exe'\" | Where-Object { \"$($_.ExecutablePath) $($_.CommandLine)\" -like '*embedded-postgres*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }";
    spawnSync('powershell', ['-NoProfile', '-Command', filter], { stdio: 'ignore' });
}

function ensureMysqlDatabase() {
    const code = `$p = new PDO('mysql:host=' . getenv('H') . ';port=' . getenv('P'), getenv('U'), getenv('W'));
        $p->exec('CREATE DATABASE IF NOT EXISTS ${TEST_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');`;
    const result = spawnSync(PHP, ['-r', code], {
        env: { ...process.env, H: envValue('DB_HOST') || '127.0.0.1', P: envValue('DB_PORT') || '3306', U: envValue('DB_USERNAME') || 'root', W: envValue('DB_PASSWORD') },
        encoding: 'utf8',
    });
    if (result.status !== 0) throw new Error(`MySQL'e bağlanılamadı (MAMP açık mı?): ${(result.stderr || result.stdout).trim().split('\n')[0]}`);
}

const results = {};
for (const engine of engines) {
    log(`— ${engine} —`);
    try {
        if (engine === 'sqlite') {
            results[engine] = phpunit({});
        } else if (engine === 'mysql') {
            ensureMysqlDatabase();
            results[engine] = phpunit({ DB_CONNECTION: 'mysql', DB_DATABASE: TEST_DB });
        } else {
            results[engine] = await withPostgres(() => phpunit(
                { DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: String(PG.port), DB_DATABASE: TEST_DB, DB_USERNAME: PG.user, DB_PASSWORD: PG.password },
                ['-d', 'extension=pdo_pgsql'],
            ));
        }
    } catch (error) {
        console.error(error?.message ?? String(error));
        results[engine] = false;
    }
}
log(Object.entries(results).map(([e, ok]) => `${e}: ${ok ? 'geçti' : 'KALDI'}`).join(' · '));
process.exit(Object.values(results).every(Boolean) ? 0 : 1);
