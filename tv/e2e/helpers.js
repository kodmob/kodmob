/**
 * Senaryoların ortak yardımcıları: panele giriş, API istemcisi (durum hazırlamak için), konsol
 * hatası toplayıcı, onay penceresi ve kayıt kısayolları. Her senaryo kendi verisini API ile kurar.
 */
import crypto from 'node:crypto';
import { expect, test } from '@playwright/test';

/** TOTP kodu (RFC 6238; kodmob-api TwoFactor ile aynı): `offset` adım ileri/geri. */
export function totp(secret, offset = 0) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = secret.replace(/[^A-Z2-7]/gi, '').toUpperCase().split('').map((c) => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + offset));
    const hash = crypto.createHmac('sha1', key).update(counter).digest();
    const at = hash[19] & 0x0f;
    return String((hash.readUInt32BE(at) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

export const USER = 'e2e';
export const password = () => process.env.E2E_PASSWORD;

/** Benzersiz ek (aynı çalıştırmadaki kayıtlar çakışmasın). */
export const stamp = () => `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;

export async function login(page) {
    await page.goto('/panel/');
    await page.fill('#login-user', USER);
    await page.fill('#login-pass', password());
    await page.click('button[type=submit]');
    // Menü çizildi (Blog her profilde: çatının türü); senaryolar sitenin profiline bağlı değil.
    await expect(page.locator('aside nav a[href="/panel/blog"]').first()).toBeVisible({ timeout: 20000 });
}

/** API istemcisi: `{ status, json }` döner. */
export async function apiClient(request) {
    const auth = await request.post('/api/v1/auth/token', { data: { username: USER, password: password() } });
    expect(auth.ok(), 'API oturumu').toBeTruthy();
    const { token } = await auth.json();
    const call = async (method, url, body, extra = {}) => {
        const res = await request.fetch(`/api/v1${url}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }, data: body, ...extra });
        return { status: res.status(), json: await res.json().catch(() => null) };
    };
    return {
        get: (url) => call('GET', url),
        post: (url, body) => call('POST', url, body),
        put: (url, body) => call('PUT', url, body),
        del: (url) => call('DELETE', url),
        upload: (url, multipart) => call('POST', url, undefined, { multipart }),
    };
}

/**
 * Senaryonun istediği içerik türü ya da modül bu derlemede yoksa senaryo atlanır (nedeniyle raporda görünür): ör.
 * başlangıç profilinde portföy türü (`projects`) ve SMS yok. Senaryolar sitenin profiline bağlı değil; profil ne
 * veriyorsa onu sınar.
 */
export async function requires(request, { types = [], modules = [] } = {}) {
    const api = await apiClient(request);
    const have = (await api.get('/me')).json?.modules || [];
    const missing = modules.filter((m) => !have.includes(m));
    for (const type of types) if ((await api.get(`/admin/content/${type}?per_page=1`)).status === 404) missing.push(`${type} türü`);
    test.skip(missing.length > 0, `bu derlemede yok: ${missing.join(', ')}`);
}

/** Yalnızca Türkçe yayında bir proje. */
export async function createProject(api, { title, slug, summary = '', status = 'publish' }) {
    const res = await api.post('/admin/content/projects', { status, locales: { tr: { status } }, i18n: { tr: { title, slug, summary } } });
    expect(res.status, `proje oluşturuldu: ${title}`).toBe(201);
    return res.json;
}

/**
 * Konsol hataları ve yakalanmamış istisnalar. Beklenen HTTP hataları (404 sayfası, 410, alan
 * hatası) sayılmaz. Senaryonun sonunda `expect(errors()).toEqual([])`.
 *
 * Sayfalar arası geçişi (View Transitions) Chrome yarıda bırakınca sayfaya yakalanmamış hata yazar
 * ("Transition was aborted because of invalid state"); sayfa yine açılır. Nedeni açık (docs/dikkat.md
 * "Site ve SEO"); bağlantıya tıklayarak gezen senaryo bunun için düşmesin.
 */
const TRANSITION_ABORTED = /^Transition was aborted because of invalid state/;

export function consoleErrors(page, ignore = /status of (401|404|410|422|429)/) {
    const list = [];
    page.on('pageerror', (error) => {
        if (!TRANSITION_ABORTED.test(error.message)) list.push(`${error.message}`);
    });
    page.on('console', (message) => {
        if (message.type() === 'error' && !ignore.test(message.text())) list.push(message.text());
    });
    return () => list;
}

/** Açık onay penceresini onaylar (ui/confirm). */
export async function confirmYes(page) {
    await page.waitForFunction(() => window.Alpine.store('confirm').open, null, { timeout: 5000 });
    await page.evaluate(() => window.Alpine.store('confirm').answer(true));
}

/** Düzenleyicide Ctrl+S; kayıt isteği bitene kadar bekler. */
export async function saveEditor(page) {
    const done = page.waitForResponse((r) => /\/admin\/content\/(projects|posts)/.test(r.url()) && ['POST', 'PUT'].includes(r.request().method()), { timeout: 20000 });
    await page.waitForTimeout(150);
    await page.keyboard.press('Control+s');
    await done;
    await page.waitForTimeout(500);
}

/** Yönlendirmeyi izlemeden durum kodu (ve Location). */
export async function statusOf(request, url) {
    const res = await request.get(url, { maxRedirects: 0 });
    return { status: res.status(), location: res.headers().location || null };
}
