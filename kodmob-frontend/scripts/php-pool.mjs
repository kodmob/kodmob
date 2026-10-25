/**
 * Yerel PHP sunucusu havuzu: önde küçük bir Node vekil, arkada birkaç `php -S` süreci.
 *
 * Neden: PHP'nin yerleşik sunucusu Windows'ta tek iş parçacıklıdır (PHP_CLI_SERVER_WORKERS
 * etkisiz). Chrome'un önceden açtığı ama istek göndermediği bağlantılarda saniyelerce bekleyip
 * kalır; o arada gelen istek zaman aşımına uğrar ("Sunucu yanıt vermedi"). Vekil isteği tam
 * okuduktan sonra boştaki sürece iletir: boş bağlantılar PHP'ye ulaşmaz, istekler paralel işlenir.
 *
 *   const pool = await startPool({ php, port: 8090, root, router, env, workers: 4 });
 *   pool.close();
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { spawn } from 'node:child_process';

/** İşletim sisteminden boş bir port (başka havuzun ya da sunucunun portuyla çakışmasın). */
function freePort() {
    return new Promise((resolve, reject) => {
        const probe = net.createServer();
        probe.unref();
        probe.on('error', reject);
        probe.listen(0, '127.0.0.1', () => {
            const { port } = probe.address();
            probe.close(() => resolve(port));
        });
    });
}

/**
 * Sürecin açılmasını bekler. Yoklamaya yönlendirici (kodmob-core/dev/router.php) havuzun belirteciyle
 * cevap verir: portta başka bir sunucu varsa (çakışma) kabul edilmez.
 */
async function waitForBackend(backend, token, timeout = 15000) {
    const until = Date.now() + timeout;
    while (Date.now() < until) {
        if (backend.proc.exitCode !== null) throw new Error(`php -S kapandı (port ${backend.port}; port kullanımda olabilir)`);
        const owner = await new Promise((resolve) => {
            const req = http.get({ host: '127.0.0.1', port: backend.port, path: '/km-pool-ping' }, (res) => {
                res.resume();
                resolve(res.headers['x-km-pool'] ?? '');
            });
            req.on('error', () => resolve(null));
        });
        if (owner === token) return;
        if (owner !== null) throw new Error(`Port ${backend.port} başka bir sunucuda`);
        await new Promise((resolve) => setTimeout(resolve, 150));
    }
    throw new Error(`php -S açılmadı (port ${backend.port})`);
}

/**
 * @param {{ php: string, port: number|string, root: string, router: string, env?: object, workers?: number, firstPort?: number, stdio?: any, logFile?: string|null }} options
 * @returns {Promise<{ close: () => void, port: number }>}
 */
export async function startPool({ php, port, root, router, env = process.env, workers = 4, firstPort, stdio = 'ignore', logFile = null }) {
    if (logFile) fs.writeFileSync(logFile, '');
    const token = crypto.randomBytes(8).toString('hex');
    const backends = [];
    for (let i = 0; i < workers; i++) {
        backends.push({ port: firstPort ? firstPort + i : await freePort(), busy: 0, proc: null });
    }
    for (const backend of backends) {
        backend.proc = spawn(php, ['-S', `127.0.0.1:${backend.port}`, '-t', root, router], { env: { ...env, KM_POOL_TOKEN: token }, stdio: ['ignore', stdio, stdio] });
    }
    try {
        await Promise.all(backends.map((b) => waitForBackend(b, token)));
    } catch (error) {
        for (const b of backends) b.proc?.kill();
        throw error;
    }

    // Her isteğin hangi sürece gittiği ve süresi: `logFile` (uçtan uca testler) ya da KM_POOL_DEBUG=1 (konsol).
    const debug = logFile
        ? (message) => fs.appendFileSync(logFile, `${new Date().toISOString()} ${message}\n`)
        : process.env.KM_POOL_DEBUG === '1' ? (message) => console.log(`[pool] ${message}`) : () => {};
    const server = http.createServer((req, res) => {
        const backend = backends.reduce((best, b) => (b.busy < best.busy ? b : best));
        backend.busy++;
        const started = Date.now();
        debug(`→ ${backend.port} ${req.method} ${req.url}`);
        let released = false;
        const release = () => {
            if (!released) {
                released = true;
                backend.busy--;
                debug(`← ${backend.port} ${req.method} ${req.url} ${res.statusCode} ${Date.now() - started}ms`);
            }
        };
        const upstream = http.request(
            { host: '127.0.0.1', port: backend.port, method: req.method, path: req.url, headers: { ...req.headers, connection: 'close' } },
            (response) => {
                // php -S her yanıtta bağlantıyı kapatır; bu başlık tarayıcıya geçerse tarayıcı da her
                // istekte yeni bağlantı açar ve Windows'ta geçici portlar tükenir (ERR_ADDRESS_IN_USE).
                const { connection, 'keep-alive': keepAlive, ...headers } = response.headers;
                res.writeHead(response.statusCode, headers);
                response.pipe(res);
                response.on('end', release);
                response.on('error', release);
            },
        );
        upstream.on('error', (error) => {
            release();
            if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end(`PHP sunucusuna ulaşılamadı: ${error.message}`);
        });
        res.on('close', release);
        req.pipe(upstream);
    });
    server.keepAliveTimeout = 5000;
    try {
        await new Promise((resolve, reject) => server.listen(Number(port), '127.0.0.1', resolve).on('error', reject));
    } catch (error) {
        // Port doluysa başlatılan PHP süreçleri sahipsiz kalmasın.
        for (const b of backends) b.proc?.kill();
        throw new Error(error.code === 'EADDRINUSE' ? `Port ${port} kullanımda (başka bir sunucu açık mı?)` : error.message);
    }

    return {
        port: Number(port),
        close() {
            server.close();
            for (const b of backends) b.proc?.kill();
        },
    };
}
