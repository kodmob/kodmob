/**
 * Kodmob REST istemcisi (`/kodmob/v1`).
 *
 * - Her isteğe geçerli token eklenir; süresi dolmuşsa önce yenilenir.
 * - Ziyaretçi uçları token'sız çalışır. Yönetici oturumu varken 401 alınırsa
 *   bir kez yenileyip tekrar dener; yine olmazsa oturum kapanır. Paneldeyse aynı adres
 *   yeniden açılır (giriş ekranı orada çıkar, girişten sonra aynı sayfa); değilse giriş
 *   sayfasına `?next=` ile gidilir.
 * - Yanıt gövdesi olduğu gibi döner (zarf yok). Hata `ApiError`'dur:
 *   `{ status, code, message, details }`.
 */
import { API, ROUTES } from './config.js';
import { auth } from './auth.js';
import { messageFor, httpFailed } from '../domain/errors.js';

export class ApiError extends Error {
    constructor({ status = 0, code = 'km_internal_error', message = '', details = {}, data = null } = {}) {
        super(message || messageFor(code));
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.details = details || {};
        this.data = data;
    }

    get isAuth() {
        return this.status === 401;
    }

    get isForbidden() {
        return this.status === 403;
    }
}

const DEFAULT_TIMEOUT = 15000;

export function redirectToLogin() {
    const here = window.location.pathname + window.location.search;
    if (here.startsWith(ROUTES.LOGIN)) {
        window.location.reload();
        return;
    }
    window.location.assign(`${ROUTES.LOGIN}?next=${encodeURIComponent(here)}`);
}

function toQuery(params = {}) {
    const search = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        if (Array.isArray(value)) {
            value.forEach((v) => search.append(`${key}[]`, v));
        } else {
            search.append(key, value);
        }
    });
    const qs = search.toString();
    return qs ? `?${qs}` : '';
}

/**
 * Sunucu mesajının çevirisi (panelin dili; panel/i18n.js kurar): `(kod, metin) => metin`. Alan hataları da geçer
 * (`details.fields`, kodları `details.codes`).
 */
let translateMessage = null;
export function useMessageTranslator(fn) {
    translateMessage = fn;
}

async function parseError(response) {
    const body = await response.json().catch(() => ({}));
    const tr = (code, text) => (translateMessage ? translateMessage(code, text) : text);
    const details = body.details || body.data?.details || {};
    if (translateMessage && details.fields && typeof details.fields === 'object') {
        details.fields = Object.fromEntries(Object.entries(details.fields).map(([field, text]) => [field, tr(details.codes?.[field] || '', String(text))]));
    }
    return new ApiError({
        status: response.status,
        code: body.code || `http_${response.status}`,
        message: body.message ? tr(body.code || '', String(body.message)) : messageFor(body.code, httpFailed(response.status)),
        details,
        data: body.data || null,
    });
}

async function request(method, path, { query, body, timeout = DEFAULT_TIMEOUT, prefix = API.app, raw = false, retried = false } = {}) {
    const token = await auth.validToken().catch(() => null);

    const headers = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;
    let payload;
    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    let response;
    try {
        response = await fetch(`${API.base}${prefix}${path}${toQuery(query)}`, {
            method,
            headers,
            body: payload,
            signal: controller.signal,
        });
    } catch (error) {
        throw new ApiError({ code: error.name === 'AbortError' ? 'km_timeout' : 'km_network_error' });
    } finally {
        clearTimeout(timer);
    }

    if (response.status === 401 && !retried && auth.hasSession()) {
        try {
            await auth.refresh();
            return request(method, path, { query, body, timeout, prefix, raw, retried: true });
        } catch {
            auth.clear();
            redirectToLogin();
            throw new ApiError({ status: 401, code: 'km_session_expired' });
        }
    }

    if (!response.ok) {
        const error = await parseError(response);
        if (error.status === 401) {
            auth.clear();
            redirectToLogin();
        }
        throw error;
    }

    if (raw) return response;
    if (response.status === 204) return null;
    return response.json();
}

export const http = {
    get: (path, query, options = {}) => request('GET', path, { ...options, query }),
    post: (path, body = {}, options = {}) => request('POST', path, { ...options, body }),
    put: (path, body = {}, options = {}) => request('PUT', path, { ...options, body }),
    patch: (path, body = {}, options = {}) => request('PATCH', path, { ...options, body }),
    delete: (path, options = {}) => request('DELETE', path, options),

    /** CSV gibi ham yanıtlar: `Response` döner. */
    download: (path, query, options = {}) => request('GET', path, { ...options, query, raw: true }),
};

/** `{ success, data }` zarflı uçlar (`ApiController` tabanlılar, ör. iletişim) için. */
export const unwrap = (response) => (response && typeof response === 'object' && 'data' in response && 'success' in response ? response.data : response);
