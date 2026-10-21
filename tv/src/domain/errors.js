/**
 * Sunucu hata kodu → kullanıcıya yönelik mesaj (sayfanın dilinde).
 *
 * Backend `{ code, message, details }` döner. Buradaki harita yalnızca
 * istemcinin kendi ürettiği durumlar (ağ, zaman aşımı) ve sunucu mesajı
 * gelmediğinde gösterilecek yedek metinler içindir. Metinler i18n
 * sözlüğündeki `client.errors.*` anahtarlarındadır.
 */
import { t } from '../i18n/client.js';

const KEYS = {
    km_network_error: 'network',
    km_timeout: 'timeout',
    km_unauthenticated: 'unauthenticated',
    km_session_expired: 'sessionExpired',
    km_forbidden: 'forbidden',
    km_internal_error: 'internal',
    km_conflict: 'conflict',
    km_rate_limited: 'rateLimited',
};

export function messageFor(code, fallback = '') {
    return KEYS[code] ? t(`errors.${KEYS[code]}`) : fallback || t('errors.internal');
}

/** Sunucu mesaj göndermediğinde: "İstek başarısız oldu (HTTP 500)." */
export function httpFailed(status) {
    return t('errors.httpFailed', { status });
}

/**
 * Alan hataları: `details.fields` (kodmob-api) → { alan: mesaj }.
 */
export function fieldErrors(error) {
    const details = error?.details || {};
    return details.fields && typeof details.fields === 'object' ? details.fields : {};
}
