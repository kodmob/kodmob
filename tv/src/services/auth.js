/**
 * Panel oturumu (kodmob-api, /v1/auth): giriş, jeton yenileme, çıkış.
 *
 * - Erişim jetonu (en çok 1 sa) sekmenin sessionStorage'ında: sayfa yenilemede yeniden döndürme
 *   gerekmez, sekme kapanınca silinir. Yeni sekme jetonu çerezle alır.
 * - Yenileme jetonu (30 gün, tek kullanımlık) HttpOnly `km_refresh` çerezinde: JavaScript
 *   okuyamaz, bir XSS uzun ömürlü oturumu çalamaz. Depoda yalnızca hassas olmayan "oturum var"
 *   işareti durur (`km_signed_in`; çerezin varlığını JavaScript bilemez).
 * - Yenileme tek uçuşludur (aynı anda 401 alan beş istek tek yenilemeyi bekler) ve sekmeler
 *   arasında kilitlidir (navigator.locks): iki sekme aynı jetonu harcayıp birini çıkarmaz;
 *   sıradaki sekme kazananın yazdığı yeni çerezle ister.
 * - Bir sekmede çıkış yapılınca öbür sekmeler de çıkar (`km-signed-out` olayı).
 */
import { API, STORAGE_KEYS } from './config.js';
import { storage } from './storage.js';

export class AuthError extends Error {
    constructor(message, status = 0, code = '') {
        super(message);
        this.name = 'AuthError';
        this.status = status;
        this.code = code;
    }
}

const TIMEOUT_MS = 12000;
let refreshInFlight = null;
/** Erişim jetonu ve bitişi (ms): bu sekmeye özgü (sessionStorage; kullanılamıyorsa yalnızca bellek). */
const TAB_KEY = 'km_access';
const EMPTY = { token: null, expiry: 0 };

function readTab() {
    try {
        const saved = JSON.parse(sessionStorage.getItem(TAB_KEY) || 'null');
        return saved?.token && saved.expiry > Date.now() ? saved : { ...EMPTY };
    } catch {
        return { ...EMPTY };
    }
}

function writeTab(value) {
    try {
        if (value.token) sessionStorage.setItem(TAB_KEY, JSON.stringify(value));
        else sessionStorage.removeItem(TAB_KEY);
    } catch {
        /* depolama kullanılamıyor: yalnızca bellek */
    }
}

let memory = typeof window !== 'undefined' ? readTab() : { ...EMPTY };

async function post(path, body, token = null) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
    try {
        const response = await fetch(`${API.base}${API.auth}${path}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
            signal: controller.signal,
        });
        const data = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, data };
    } catch (error) {
        throw new AuthError(
            error.name === 'AbortError' ? 'Sunucu yanıt vermedi. Bağlantınızı kontrol edip tekrar deneyin.' : 'Sunucuya ulaşılamadı. Bağlantınızı kontrol edin.',
            0,
            'km_network_error'
        );
    } finally {
        clearTimeout(timer);
    }
}

function storeTokens(data) {
    memory = { token: data.token, expiry: Date.now() + Number(data.expires_in || 0) * 1000 };
    writeTab(memory);
    storage.set(STORAGE_KEYS.SIGNED_IN, '1');
    // Eski sürümün depoya yazdığı jetonlar (geçiş): artık depoda jeton tutulmaz.
    storage.remove(STORAGE_KEYS.TOKEN, STORAGE_KEYS.REFRESH_TOKEN, STORAGE_KEYS.TOKEN_EXPIRY);
}

/** Sekmeler arası kilit (destek yoksa doğrudan). */
const withLock = (task) => (navigator.locks?.request ? navigator.locks.request('km-auth-refresh', task) : task());

// Başka sekmede çıkış: bu sekmenin belleği de silinir, panel giriş ekranına döner.
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
        if (event.key === STORAGE_KEYS.SIGNED_IN && !event.newValue) {
            memory = { ...EMPTY };
            writeTab(memory);
            window.dispatchEvent(new CustomEvent('km-signed-out'));
        }
    });
}

export const auth = {
    token() {
        return memory.token;
    },

    /** Oturum var mı (bellekte jeton ya da çerezle sürdürülebilir oturum işareti). */
    hasSession() {
        return !!memory.token || storage.get(STORAGE_KEYS.SIGNED_IN) === '1' || !!storage.get(STORAGE_KEYS.REFRESH_TOKEN);
    },

    /** Token süresi dolmuş ya da 30 sn içinde dolacak mı? */
    isExpired(marginMs = 30000) {
        return !memory.expiry || memory.expiry - Date.now() <= marginMs;
    },

    /** `code`: iki adımlı doğrulama açıksa uygulamadaki kod ya da yedek kod (ilk denemede boş). */
    async login(username, password, code = '') {
        const { ok, status, data } = await post('/token', { username, password, ...(code ? { code } : {}) });
        if (!ok) {
            const tooMany = status === 429 || data.code === 'jwt_auth_too_many_attempts';
            const badCode = data.code === 'jwt_auth_otp_invalid';
            throw new AuthError(
                tooMany
                    ? (data.message || 'Çok fazla hatalı deneme. Bir süre bekleyip tekrar deneyin.')
                    : badCode ? (data.message || 'Doğrulama kodu hatalı.') : 'Kullanıcı adı veya şifre hatalı.',
                status,
                data.code || 'jwt_auth_failed'
            );
        }
        // Şifre doğru, iki adımlı doğrulama açık: giriş ekranı kodu sorar ve aynı isteği kodla yineler.
        if (data.code === 'otp_required') {
            throw new AuthError(data.message || 'Doğrulama kodunu girin.', status, 'otp_required');
        }
        if (!data.token) {
            throw new AuthError('Sunucudan beklenmeyen bir yanıt alındı.', status, 'km_bad_response');
        }
        storeTokens(data);
        return data;
    },

    /** Projeler arası geçiş: ana projenin verdiği tek kullanımlık anahtarla oturum (kodmob-api Handoff). */
    async handoff(token) {
        const { ok, status, data } = await post('/handoff', { token });
        if (!ok || !data.token) {
            throw new AuthError(data.message || 'Geçiş bağlantısı geçersiz; giriş yapın.', status, data.code || 'km_handoff_invalid');
        }
        storeTokens(data);
        return data;
    },

    /** Tek uçuşlu, sekmeler arası kilitli yenileme. Oturum bittiyse temizlenir ve hata fırlatılır. */
    refresh() {
        if (!refreshInFlight) {
            refreshInFlight = withLock(async () => {
                // Kilidi beklerken bu sekme zaten yenilendiyse yeniden harcama.
                if (memory.token && !this.isExpired()) return memory.token;
                // Eski sürümden kalan jeton (çerezi olmayan oturum) yalnızca bir kez gönderilir.
                const legacy = storage.get(STORAGE_KEYS.REFRESH_TOKEN);
                let result = await post('/token/refresh', legacy ? { refresh_token: legacy } : null);
                // Kilidi desteklemeyen tarayıcıda başka sekme aynı anda yenilemiş olabilir: yeni çerezle bir kez daha.
                if (result.status === 401 && !navigator.locks?.request) {
                    await new Promise((resolve) => setTimeout(resolve, 400));
                    result = await post('/token/refresh', null);
                }
                const { ok, status, data } = result;
                if (!ok || !data.token) {
                    this.clear();
                    throw new AuthError('Oturumunuz sona erdi. Lütfen yeniden giriş yapın.', status || 401, 'km_session_expired');
                }
                storeTokens(data);
                return data.token;
            }).finally(() => {
                refreshInFlight = null;
            });
        }
        return refreshInFlight;
    },

    /** Geçerli bir erişim jetonu döner; gerekirse önce yeniler. */
    async validToken() {
        if (memory.token && !this.isExpired()) return memory.token;
        if (this.hasSession()) return this.refresh();
        return null;
    },

    async logout() {
        // Oturum sunucuda kapanmalı (çerez HttpOnly; JavaScript silemez): jeton dolduysa önce yenilenir.
        const token = await this.validToken().catch(() => null);
        this.clear();
        if (token) await post('/token/revoke', null, token).catch(() => {});
    },

    clear() {
        memory = { ...EMPTY };
        writeTab(memory);
        storage.remove(STORAGE_KEYS.SIGNED_IN, STORAGE_KEYS.TOKEN, STORAGE_KEYS.REFRESH_TOKEN, STORAGE_KEYS.TOKEN_EXPIRY, STORAGE_KEYS.SESSION);
    },
};
