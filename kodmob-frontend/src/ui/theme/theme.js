/**
 * Açık / koyu tema. Varsayılan sistem tercihidir (CSS'te prefers-color-scheme);
 * ziyaretçi seçerse `km_theme` (localStorage) ve <html> sınıfıyla sabitlenir:
 * `theme-light` ya da `theme-dark`.
 *
 * `applyStoredTheme()` app.js'te en başta çağrılır (ilk çizime en yakın an);
 * CSP satır içi betiğe izin vermediği için <head>'de çalıştırılamaz.
 */
import { iconHref } from '../icon.js';
const KEY = 'km_theme';
const MODES = ['system', 'light', 'dark'];

function read() {
    try {
        const value = localStorage.getItem(KEY);
        return MODES.includes(value) ? value : 'system';
    } catch {
        return 'system';
    }
}

function apply(mode) {
    const root = document.documentElement;
    root.classList.toggle('theme-light', mode === 'light');
    root.classList.toggle('theme-dark', mode === 'dark');
}

export function applyStoredTheme() {
    apply(read());
}

export function kmTheme() {
    return {
        mode: read(),

        /** Sistem → açık → koyu → sistem */
        cycle() {
            this.mode = MODES[(MODES.indexOf(this.mode) + 1) % MODES.length];
            try {
                if (this.mode === 'system') localStorage.removeItem(KEY);
                else localStorage.setItem(KEY, this.mode);
            } catch {
                /* depolama kapalı: yalnızca bu sayfada geçerli */
            }
            apply(this.mode);
        },

        get icon() {
            return iconHref({ system: 'monitor', light: 'sun', dark: 'moon' }[this.mode]);
        },
    };
}
