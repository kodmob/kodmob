/**
 * Oturumdaki kullanıcı ve yetkileri: `$store.session` (panel).
 * Bilgi `GET /me`'den gelir (rol, yetkiler, rolün yan menüsü) ve tarayıcıda
 * saklanır: panel yeniden açılınca menü beklemeden çizilir, ardından sunucudan tazelenir.
 *
 *     $store.session.can('km_manage_users')            tek yetki
 *     $store.session.can('km_view_briefs|km_use_jarvis') herhangi biri
 *     $store.session.can(['km_a', 'km_b'])             herhangi biri
 *
 * Asıl yetki denetimi sunucudadır (`cap:` ara katmanı); burada yalnızca arayüz süzülür.
 */
import { http } from '../services/http.js';
import { storage } from '../services/storage.js';
import { STORAGE_KEYS } from '../services/config.js';

export function createSessionStore() {
    const cached = storage.getJSON(STORAGE_KEYS.SESSION, null);
    return {
        me: cached?.capabilities ? cached : null,
        loaded: false,

        get caps() {
            return this.me?.capabilities || [];
        },

        get role() {
            return this.me?.role || { slug: '', label: '' };
        },

        get menu() {
            return this.me?.menu || null;
        },

        get isAdmin() {
            return !!this.me?.is_admin;
        },

        can(cap) {
            if (!cap || (Array.isArray(cap) && !cap.length)) return true;
            if (Array.isArray(cap)) return cap.some((c) => this.can(c));
            return String(cap).split('|').some((c) => this.caps.includes(c.trim()));
        },

        async load() {
            const me = await http.get('/me');
            this.set(me);
            return me;
        },

        set(me) {
            this.me = me;
            this.loaded = true;
            // Eski kayıt biçimi ({ name, email }) panel üst barında kullanılır.
            storage.setJSON(STORAGE_KEYS.SESSION, { ...me, name: me.display_name, email: me.email });
        },

        clear() {
            this.me = null;
            this.loaded = false;
        },
    };
}
