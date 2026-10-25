/**
 * Ortam ayarları ve sabit adresler. `import.meta.env` yalnızca burada okunur.
 */
const env = import.meta.env;

export const APP_NAME = env.VITE_APP_NAME || 'Kodmob';
export const DEBUG = env.VITE_DEBUG_MODE === 'true';

export const API = {
    base: (env.VITE_BASE_URL || '/api').replace(/\/$/, ''),
    app: env.VITE_API_BASE_PATH || '/v1',
    auth: env.VITE_AUTH_BASE_PATH || '/v1/auth',
};

export const ROUTES = {
    HOME: '/',
    /** Yönetim paneli; oturum yoksa giriş ekranını kendisi gösterir. */
    LOGIN: '/panel/',
};

/** localStorage anahtarları. */
export const STORAGE_KEYS = {
    /** "Oturum var" işareti (hassas değil; yenileme jetonu HttpOnly çerezdedir, services/auth.js). */
    SIGNED_IN: 'km_signed_in',
    SESSION: 'km_session',
    // Eski sürümün jeton anahtarları: yalnızca geçişte silinmek ve bir kez okunmak için.
    TOKEN: 'km_jwt_token',
    REFRESH_TOKEN: 'km_refresh_token',
    TOKEN_EXPIRY: 'km_token_expiry',
};
