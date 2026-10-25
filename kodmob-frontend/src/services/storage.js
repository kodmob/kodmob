/**
 * localStorage erişimi. Gizli pencere, kota dolu ya da engellenmiş depolama
 * durumlarında uygulama çökmez; okuma varsayılanı, yazma sessizce hiçbir şey
 * döndürmez.
 */
export const storage = {
    get(key, fallback = null) {
        try {
            const value = localStorage.getItem(key);
            return value === null ? fallback : value;
        } catch {
            return fallback;
        }
    },

    set(key, value) {
        try {
            localStorage.setItem(key, String(value));
        } catch {
            /* depolama kullanılamıyor */
        }
    },

    remove(...keys) {
        try {
            keys.forEach((key) => localStorage.removeItem(key));
        } catch {
            /* depolama kullanılamıyor */
        }
    },

    getJSON(key, fallback = null) {
        const raw = this.get(key);
        if (raw === null) return fallback;
        try {
            return JSON.parse(raw);
        } catch {
            return fallback;
        }
    },

    setJSON(key, value) {
        this.set(key, JSON.stringify(value));
    },
};
